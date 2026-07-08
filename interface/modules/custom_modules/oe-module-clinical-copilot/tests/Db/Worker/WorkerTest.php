<?php

/**
 * DB-backed U9 acceptance evals: worker-dead correctness, warm cache hits, T22 bounded QA rerun, heartbeat resilience.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Worker;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\DbLabTurnaroundConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Capability\ControlProxy;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedResponse;
use OpenEMR\Modules\ClinicalCopilot\Capability\OverdueTests;
use OpenEMR\Modules\ClinicalCopilot\Capability\PendingResults;
use OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend;
use OpenEMR\Modules\ClinicalCopilot\Config\WorkerConfig;
use OpenEMR\Modules\ClinicalCopilot\Doc\QaStatus;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use OpenEMR\Modules\ClinicalCopilot\Observability\Alert\AlertEvaluator;
use OpenEMR\Modules\ClinicalCopilot\Observability\Alert\LogAlertNotifier;
use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaReviewer;
use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaSweepOutcome;
use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaSweepSummary;
use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaTargetType;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceCircuitBreaker;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceConfigStore;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\Observability\WorkerTick;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\PatientIdentifierLookup;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGeneration;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use OpenEMR\Modules\ClinicalCopilot\Worker;
use OpenEMR\Modules\ClinicalCopilot\Worker\AppointmentWindowReader;
use OpenEMR\Modules\ClinicalCopilot\Worker\AppointmentWindowReaderInterface;
use OpenEMR\Services\PrescriptionService;
use PHPUnit\Framework\TestCase;

/**
 * No live LLM calls (build-notes.md): every {@see SynthesisReadPath} built
 * here uses {@see CountingLlmClient::down()} -- the honest no-ADC dev/test
 * default -- so every digest miss degrades to a facts-only doc
 * (`verify_status='degraded'`), still a legal, servable cache entry
 * (T22/DocStore::findBest()'s degraded fallback). This is exactly what lets
 * these evals exercise real hit/miss/rerun mechanics without a live model,
 * while still being able to assert on how many times the LLM was actually
 * called.
 */
final class WorkerTest extends TestCase
{
    private const LOINC_A1C = '4548-4';

    private DocStore $docStore;
    private CountingLlmClient $llmClient;
    private SynthesisReadPath $readPath;
    private int $pid;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->pid = self::insertSyntheticPatient();
        $this->docStore = new DocStore();
        $this->llmClient = CountingLlmClient::down();
        $this->readPath = self::buildReadPath($this->docStore, $this->llmClient);
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
        putenv(WorkerConfig::ENV_BACKGROUND_LLM_ENABLED);
        unset(
            $_ENV[WorkerConfig::ENV_BACKGROUND_LLM_ENABLED],
            $_SERVER[WorkerConfig::ENV_BACKGROUND_LLM_ENABLED],
        );
    }

    /**
     * I7: "worker failure degrades latency only, never correctness." A read
     * with no prior warm at all -- the worker has literally never run --
     * still computes a correct, fresh result. This is the baseline every
     * other eval in this class builds on: warming is optional for
     * correctness, only for latency.
     */
    public function testWorkerAbsenceStillLetsReadPathServeFreshFacts(): void
    {
        self::insertA1cResult($this->pid, '7.2', '2026-01-10');

        $result = $this->readPath->read($this->pid, null);

        self::assertFalse($result->capabilityCrash);
        self::assertNotEmpty($result->facts, 'facts are computed fresh regardless of whether any worker tick ever ran');
        self::assertSame(1, $this->llmClient->callCount());
    }

    /**
     * U9 acceptance: "warm hit serves without LLM call." The first tick's
     * warm pass misses (no doc exists yet) and calls the reducer once; a
     * second tick over the SAME unchanged DB state must serve the row the
     * first tick inserted -- zero additional LLM calls, zero additional
     * `mod_copilot_doc` rows.
     */
    public function testWarmHitServesWithoutAnLlmCall(): void
    {
        putenv(WorkerConfig::ENV_BACKGROUND_LLM_ENABLED . '=true');

        self::insertA1cResult($this->pid, '7.2', '2026-01-10');
        $now = new \DateTimeImmutable('2026-03-02 08:00:00');
        self::insertScheduleEvent($this->pid, $now->modify('+20 minutes'));

        $worker = $this->buildWorker($now, new AppointmentWindowReader());

        $first = $worker->runTick();
        self::assertTrue($first->warmOk);
        self::assertSame(1, $first->warmedCount);
        self::assertSame(1, $this->llmClient->callCount());
        self::assertSame(1, self::countDocRows($this->pid));

        $second = $worker->runTick();
        self::assertTrue($second->warmOk);
        self::assertSame(1, $second->warmedCount);
        self::assertSame(1, $this->llmClient->callCount(), 'an unchanged fact set must be a cache hit -- no second LLM call');
        self::assertSame(1, self::countDocRows($this->pid), 'a cache hit must never insert a second row');
    }

    public function testWorkerWarmDoesNotCallLlmWhenBackgroundDisabled(): void
    {
        putenv(WorkerConfig::ENV_BACKGROUND_LLM_ENABLED . '=false');

        self::insertA1cResult($this->pid, '7.2', '2026-01-10');
        $now = new \DateTimeImmutable('2026-03-02 08:00:00');
        self::insertScheduleEvent($this->pid, $now->modify('+20 minutes'));

        $worker = $this->buildWorker($now, new AppointmentWindowReader());
        $result = $worker->runTick();

        self::assertTrue($result->warmOk);
        self::assertSame(0, $result->warmedCount);
        self::assertSame(0, $this->llmClient->callCount());
        self::assertSame(0, self::countDocRows($this->pid));
    }

    /**
     * I7, structural: the heartbeat is written even when a LATER stage
     * throws. A fake {@see AppointmentWindowReaderInterface} that always
     * throws stands in for "the warm stage had a bad day"; the worker's own
     * per-stage guarding (Worker::safely()) must still let heartbeat and
     * every later stage run.
     */
    public function testHeartbeatIsWrittenEvenWhenWarmThrows(): void
    {
        $before = self::heartbeatTickCount();

        $throwingAppointments = new class implements AppointmentWindowReaderInterface {
            public function dueForWarm(\DateTimeImmutable $now, \DateTimeImmutable $until): array
            {
                throw new \RuntimeException('simulated appointment-window failure');
            }

            public function nextApptAt(int $pid, \DateTimeImmutable $now): ?\DateTimeImmutable
            {
                return null;
            }
        };

        $worker = $this->buildWorker(new \DateTimeImmutable(), $throwingAppointments);
        $result = $worker->runTick();

        self::assertTrue($result->heartbeatOk, 'the heartbeat must land even though warm() throws afterward');
        self::assertFalse($result->warmOk, 'the warm stage itself must report failure, not silently succeed');
        self::assertTrue($result->qaSweepOk, 'later stages must still run after an earlier stage throws');
        self::assertTrue($result->alertEvalOk);

        $after = self::heartbeatTickCount();
        self::assertGreaterThan($before, $after, 'recordHeartbeat() must have run and persisted despite the later warm() failure');
    }

    /**
     * T22: a `QaStatus::Low` outcome at the patient's CURRENT digest, with an
     * appointment safely before the T-5min cutoff, triggers a bounded
     * regeneration -- but never more than
     * {@see \OpenEMR\Modules\ClinicalCopilot\Worker}'s own
     * MAX_QA_RERUNS_PER_DIGEST (2) for the same (pid, digest). A third
     * identical low finding is a no-op.
     */
    public function testQaLowTriggersAtMostTwoRerunsThenStops(): void
    {
        self::insertA1cResult($this->pid, '7.2', '2026-01-10');
        $now = new \DateTimeImmutable('2026-03-02 08:00:00');
        self::insertScheduleEvent($this->pid, $now->modify('+20 minutes'));

        $digest = $this->readPath->currentDigest($this->pid);
        self::assertNotNull($digest);

        $worker = $this->buildWorker($now, new AppointmentWindowReader());
        $outcome = self::lowOutcome($this->pid, $digest);
        $summary = self::summaryOf($outcome);

        self::assertSame(1, $worker->runQaDrivenReruns($summary, $now), 'first QA-driven rerun must fire');
        self::assertSame(1, self::countQaLowRows($this->pid, $digest));

        self::assertSame(1, $worker->runQaDrivenReruns($summary, $now), 'second QA-driven rerun must fire -- still under the cap of 2');
        self::assertSame(2, self::countQaLowRows($this->pid, $digest));

        self::assertSame(0, $worker->runQaDrivenReruns($summary, $now), 'a third identical low finding must be a no-op -- bounded at 2');
        self::assertSame(2, self::countQaLowRows($this->pid, $digest), 'row count must never exceed the cap');
    }

    /**
     * T22 freshness guard: if the facts have drifted since the low-scored
     * doc's digest was computed (here: a brand-new lab result changes the
     * patient's fact set), the QA-driven rerun must be skipped entirely --
     * the normal warm pass handles the new digest instead, never a rerun
     * tagged against a digest that no longer reflects the chart.
     */
    public function testDigestDriftSkipsTheQaRerun(): void
    {
        self::insertA1cResult($this->pid, '7.2', '2026-01-10');
        $now = new \DateTimeImmutable('2026-03-02 08:00:00');
        self::insertScheduleEvent($this->pid, $now->modify('+20 minutes'));

        $staleDigest = $this->readPath->currentDigest($this->pid);
        self::assertNotNull($staleDigest);

        // The chart changes AFTER the low-scored doc was computed -- the
        // current digest no longer matches the one the QA outcome refers to.
        self::insertA1cResult($this->pid, '6.9', '2026-02-01');

        $worker = $this->buildWorker($now, new AppointmentWindowReader());
        $outcome = self::lowOutcome($this->pid, $staleDigest);
        $summary = self::summaryOf($outcome);

        self::assertSame(0, $worker->runQaDrivenReruns($summary, $now), 'a drifted digest must never trigger a QA-driven rerun');
        self::assertSame(0, self::countQaLowRows($this->pid, $staleDigest));
    }

    private function buildWorker(\DateTimeImmutable $now, AppointmentWindowReaderInterface $appointments): Worker
    {
        $tick = new WorkerTick(
            QaReviewer::createDefault(),
            new AlertEvaluator(new TraceRecorder(), new LogAlertNotifier()),
        );

        return new Worker(
            $tick,
            $this->readPath,
            $appointments,
            new CadenceCircuitBreaker(),
            new CadenceConfigStore(),
            new FixedClock($now),
        );
    }

    private static function lowOutcome(int $pid, string $digest): QaSweepOutcome
    {
        return new QaSweepOutcome(QaTargetType::Doc, 0, $pid, $digest, 'ok', 0.2, QaStatus::Low, false, true);
    }

    private static function summaryOf(QaSweepOutcome $outcome): QaSweepSummary
    {
        return new QaSweepSummary(1, 0, 1, 0, 0, [$outcome]);
    }

    private static function heartbeatTickCount(): int
    {
        $raw = QueryUtils::fetchSingleValue(
            "SELECT `config_json` FROM `mod_copilot_cadence` WHERE `code_set` = 'worker_heartbeat'",
            'config_json',
        );
        $config = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($config) ? (int)($config['tick_count'] ?? 0) : 0;
    }

    private static function countDocRows(int $pid): int
    {
        return (int)QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM `mod_copilot_doc` WHERE `pid` = ?', 'c', [$pid]);
    }

    private static function countQaLowRows(int $pid, string $digest): int
    {
        return (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_doc` WHERE `pid` = ? AND `fact_digest` = ? AND `regen_reason` = 'qa_low'",
            'c',
            [$pid, $digest],
        );
    }

    private static function buildReadPath(DocStore $docStore, CountingLlmClient $llmClient): SynthesisReadPath
    {
        $configProvider = new DbLabContractConfigProvider();
        $labReader = new LabSliceReader($configProvider);
        $turnaroundProvider = new DbLabTurnaroundConfigProvider();

        $capabilities = [
            new ControlProxy($labReader),
            new MedResponse(new PrescriptionService(), $labReader),
            new VitalsTrend(),
            new OverdueTests($labReader, $configProvider, ServiceContainer::getClock()),
            new PendingResults($labReader, $turnaroundProvider),
        ];

        $reducer = new Reducer($llmClient, new PromptAssembler(), new Redactor());
        $verifiedGeneration = new VerifiedGeneration($reducer, new Verifier());

        return new SynthesisReadPath(
            $capabilities,
            $configProvider,
            $turnaroundProvider,
            $docStore,
            $verifiedGeneration,
            new PatientIdentifierLookup(),
            new Redactor(),
        );
    }

    private static function insertSyntheticPatient(): int
    {
        $pid = QueryUtils::fetchSingleValue('SELECT MAX(`pid`) + 1 AS pid FROM `patient_data`', 'pid');
        $pid = $pid !== null ? (int)$pid : 1;

        $uuid = (new UuidRegistry(['table_name' => 'patient_data']))->createUuid();
        QueryUtils::sqlInsert(
            'INSERT INTO `patient_data`
                (`uuid`, `pid`, `pubpid`, `fname`, `lname`, `DOB`, `sex`, `date`, `regdate`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), \'clinical_copilot_worker_test\')',
            [$uuid, $pid, 'CCP-WK-' . $pid, 'Synthetic', 'Patient', '1970-01-01', 'Female'],
        );

        return $pid;
    }

    private static function insertA1cResult(int $pid, string $value, string $date, string $status = 'final'): int
    {
        $orderId = QueryUtils::sqlInsert(
            'INSERT INTO `procedure_order` (`provider_id`, `patient_id`, `encounter_id`, `date_collected`, `date_ordered`, `order_status`, `activity`, `procedure_order_type`)
             VALUES (1, ?, 0, ?, ?, \'complete\', 1, \'laboratory_test\')',
            [$pid, $date, $date],
        );
        QueryUtils::sqlInsert(
            'INSERT INTO `procedure_order_code` (`procedure_order_id`, `procedure_order_seq`, `procedure_code`, `procedure_name`, `procedure_source`)
             VALUES (?, 1, ?, \'Hemoglobin A1c\', \'1\')',
            [$orderId, self::LOINC_A1C],
        );
        $reportId = QueryUtils::sqlInsert(
            'INSERT INTO `procedure_report` (`procedure_order_id`, `procedure_order_seq`, `date_collected`, `date_report`, `report_status`, `review_status`)
             VALUES (?, 1, ?, ?, \'complete\', \'reviewed\')',
            [$orderId, $date, $date],
        );

        return (int)QueryUtils::sqlInsert(
            'INSERT INTO `procedure_result` (`procedure_report_id`, `result_data_type`, `result_code`, `result_text`, `date`, `units`, `result`, `range`, `abnormal`, `result_status`)
             VALUES (?, \'N\', ?, \'Hemoglobin A1c\', ?, \'%\', ?, \'\', \'\', ?)',
            [$reportId, self::LOINC_A1C, $date, $value, $status],
        );
    }

    private static function insertScheduleEvent(int $pid, \DateTimeImmutable $apptAt): int
    {
        $uuid = (new UuidRegistry(['table_name' => 'openemr_postcalendar_events', 'table_id' => 'pc_eid']))->createUuid();

        return QueryUtils::sqlInsert(
            'INSERT INTO `openemr_postcalendar_events`
                (`uuid`, `pc_catid`, `pc_multiple`, `pc_aid`, `pc_pid`, `pc_title`, `pc_time`, `pc_eventDate`, `pc_endDate`, `pc_duration`, `pc_startTime`, `pc_endTime`, `pc_apptstatus`)
             VALUES (?, 5, 0, \'1\', ?, \'Clinical Co-Pilot worker test visit\', NOW(), ?, ?, 900, ?, ?, \'-\')',
            [
                $uuid,
                (string)$pid,
                $apptAt->format('Y-m-d'),
                $apptAt->format('Y-m-d'),
                $apptAt->format('H:i:s'),
                $apptAt->modify('+15 minutes')->format('H:i:s'),
            ],
        );
    }
}
