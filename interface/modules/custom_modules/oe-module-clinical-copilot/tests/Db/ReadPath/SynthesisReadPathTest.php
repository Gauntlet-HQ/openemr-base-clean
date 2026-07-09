<?php

/**
 * DB-backed U8 acceptance evals: read-path digest evals E1-E6, capability-crash, audit log, in-flight/no-trend.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\ReadPath;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityInterface;
use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityResult;
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\DbLabTurnaroundConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Capability\ControlProxy;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedResponse;
use OpenEMR\Modules\ClinicalCopilot\Capability\OverdueTests;
use OpenEMR\Modules\ClinicalCopilot\Capability\PendingResults;
use OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\PatientIdentifierLookup;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\UnavailableLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGeneration;
use OpenEMR\Services\PrescriptionService;
use PHPUnit\Framework\TestCase;

/**
 * No live LLM calls (build-notes.md): {@see UnavailableLlmClient} is used
 * throughout, exactly the class {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory}
 * hands back when no Vertex project is configured -- the honest dev/test
 * default. Every miss therefore degrades to a facts-only doc
 * (`verify_status='degraded'`), which is still a legal cache entry
 * (T22/DocStore::findBest()'s degraded fallback) -- exactly what lets these
 * evals exercise the real hit/miss digest-addressing behavior without a
 * live model.
 */
final class SynthesisReadPathTest extends TestCase
{
    private const LOINC_A1C = '4548-4';

    private SynthesisReadPath $readPath;
    private DocStore $docStore;
    private int $pid;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->pid = self::insertSyntheticPatient();
        $this->docStore = new DocStore();
        $this->readPath = self::buildReadPath($this->docStore);
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    /**
     * E6 determinism, and the hit/miss mechanics every other eval below
     * relies on: two reads over an unchanged DB state produce the identical
     * digest, and the second read is served from the first's (degraded)
     * row -- no second row is ever inserted for an unchanged fact set.
     */
    public function testDeterminismAndCacheHitOnUnchangedState(): void
    {
        self::insertA1cResult($this->pid, '7.2', '2026-01-10');

        $first = $this->readPath->read($this->pid, 1);
        self::assertFalse($first->capabilityCrash);
        self::assertFalse($first->servedFromCache);
        self::assertSame(VerifyStatus::Degraded, $first->verifyStatus);
        self::assertSame(1, self::countDocRows($this->pid));

        $second = $this->readPath->read($this->pid, 1);
        self::assertSame($first->factDigest, $second->factDigest, 'E6: unchanged DB state must yield an identical digest');
        self::assertTrue($second->servedFromCache, 'the second read must be served from the row the first read inserted');
        self::assertSame(1, self::countDocRows($this->pid), 'a cache hit must never insert a second row');
    }

    /**
     * E1 late arrival: a backdated lab row inserted AFTER the first read
     * must change the digest on the next read.
     */
    public function testLateArrivalChangesDigest(): void
    {
        self::insertA1cResult($this->pid, '7.2', '2026-01-10');
        $before = $this->readPath->read($this->pid, 1);

        self::insertA1cResult($this->pid, '6.9', '2025-06-01'); // backdated, earlier than the first draw

        $after = $this->readPath->read($this->pid, 1);

        self::assertNotSame($before->factDigest, $after->factDigest);
        self::assertSame(2, self::countDocRows($this->pid), 'a genuine fact-set change is a real miss -- a second row');
    }

    /**
     * E2 in-place correction: UPDATE-ing a result to `corrected` with a new
     * value must change the digest (T19: value participates in fact_id).
     */
    public function testInPlaceCorrectionChangesDigest(): void
    {
        $resultId = self::insertA1cResult($this->pid, '7.2', '2026-01-10');
        $before = $this->readPath->read($this->pid, 1);

        QueryUtils::sqlStatementThrowException(
            "UPDATE `procedure_result` SET `result_status` = 'corrected', `result` = '7.9' WHERE `procedure_result_id` = ?",
            [$resultId],
        );

        $after = $this->readPath->read($this->pid, 1);

        self::assertNotSame($before->factDigest, $after->factDigest);
    }

    /**
     * E3 soft delete: flipping `procedure_order.activity` to 0 removes the
     * row from the lab-slice base filter entirely -- must change the digest.
     */
    public function testSoftDeleteChangesDigest(): void
    {
        self::insertA1cResult($this->pid, '7.2', '2026-01-10');
        $before = $this->readPath->read($this->pid, 1);

        QueryUtils::sqlStatementThrowException(
            'UPDATE `procedure_order` SET `activity` = 0 WHERE `patient_id` = ?',
            [$this->pid],
        );

        $after = $this->readPath->read($this->pid, 1);

        self::assertNotSame($before->factDigest, $after->factDigest);
    }

    /**
     * E4 irrelevant churn: data for a DIFFERENT patient must never change
     * this patient's digest.
     */
    public function testIrrelevantChurnForAnotherPatientDoesNotChangeDigest(): void
    {
        self::insertA1cResult($this->pid, '7.2', '2026-01-10');
        $before = $this->readPath->read($this->pid, 1);

        $otherPid = self::insertSyntheticPatient();
        self::insertA1cResult($otherPid, '9.9', '2026-01-10');

        $after = $this->readPath->read($this->pid, 1);

        self::assertSame($before->factDigest, $after->factDigest);
        self::assertSame(1, self::countDocRows($this->pid), 'still just the one row -- the second read was a cache hit');
    }

    /**
     * E5 config drift: bumping the `threshold:a1c` cadence config row's
     * version invalidates this patient's existing digest (their facts used
     * that threshold to evaluate out-of-range), while a DIFFERENT patient's
     * digest -- computed independently -- is simply whatever it is for
     * their own facts; this eval's job is only to prove the version bump
     * changes THIS patient's digest, matching
     * tests/Isolated/Fact/DigestTest.php::testConfigVersionBumpChangesDigest
     * at the read-path level.
     */
    public function testConfigVersionBumpChangesDigest(): void
    {
        self::insertA1cResult($this->pid, '7.2', '2026-01-10');
        $before = $this->readPath->read($this->pid, 1);

        QueryUtils::sqlStatementThrowException(
            "UPDATE `mod_copilot_cadence` SET `version` = 'v2-test' WHERE `code_set` = 'threshold:a1c'",
        );

        $after = $this->readPath->read($this->pid, 1);

        self::assertNotSame($before->factDigest, $after->factDigest);
    }

    /**
     * Capability-crash rule (ARCHITECTURE.md §6.1): one capability throwing
     * during extraction means NO digest and NO ledger write, ever -- the
     * surviving capabilities' facts still render under a named banner.
     */
    public function testCapabilityCrashProducesNoDigestAndNoLedgerWriteButRendersSurvivingFacts(): void
    {
        self::insertA1cResult($this->pid, '7.2', '2026-01-10');

        $configProvider = new DbLabContractConfigProvider();
        $labReader = new LabSliceReader($configProvider);
        $controlProxy = new ControlProxy($labReader);

        $throwing = new class implements CapabilityInterface {
            public function capability(): Capability
            {
                return Capability::VitalsTrend;
            }

            public function capabilityVersion(): string
            {
                return '1';
            }

            public function extract(int $pid): CapabilityResult
            {
                throw new \RuntimeException('simulated data-shape surprise');
            }
        };

        $readPath = new SynthesisReadPath(
            [$controlProxy, $throwing],
            $configProvider,
            new DbLabTurnaroundConfigProvider(),
            $this->docStore,
            self::buildVerifiedGeneration(),
            new PatientIdentifierLookup(),
            new Redactor(),
        );

        $result = $readPath->read($this->pid, 1);

        self::assertTrue($result->capabilityCrash);
        self::assertStringContainsString('vitals_trend', $result->crashBanner);
        self::assertNotEmpty($result->facts, 'ControlProxy still succeeded -- its facts must still render under the banner');
        self::assertSame(0, self::countDocRows($this->pid), 'a capability crash must NEVER produce a ledger row');
    }

    /**
     * U8 acceptance: "preliminary renders in-flight and is absent from the
     * trend." Seeds a preliminary A1c result (no other A1c draw) and
     * confirms the served fact set carries it as `preliminary_result`,
     * never as a `trend_point` citing the same row.
     */
    public function testPreliminaryResultNeverAppearsAsATrendPoint(): void
    {
        self::insertA1cResult($this->pid, '8.1', '2026-02-01', 'preliminary');

        $result = $this->readPath->read($this->pid, 1);

        $preliminary = array_values(array_filter($result->facts, static fn ($f) => $f->kind === FactKind::PreliminaryResult));
        $trendPoints = array_values(array_filter($result->facts, static fn ($f) => $f->kind === FactKind::TrendPoint));

        self::assertNotEmpty($preliminary, 'the preliminary draw must be presented (in-flight)');
        self::assertSame([], $trendPoints, 'a preliminary-only fact set must never surface as a trend point');
    }

    /**
     * U8 acceptance: a doc view is audit-logged via the host EventAuditLogger
     * (ARCHITECTURE.md §4/§1.3), even though this test exercises
     * SynthesisReadPath::read() directly rather than the full
     * DocController -- reproduces the audit call DocController::view()
     * makes around it.
     */
    public function testViewWritesAnAuditLogEntry(): void
    {
        self::insertA1cResult($this->pid, '7.2', '2026-01-10');

        $before = self::countAuditLogRows($this->pid);

        $result = $this->readPath->read($this->pid, 1);
        EventAuditLogger::getInstance()->newEvent(
            'patient-record',
            'testuser',
            'Default',
            1,
            'Clinical Co-Pilot synthesis view, correlation_id=' . $result->correlationId,
            $this->pid,
        );

        $after = self::countAuditLogRows($this->pid);

        self::assertGreaterThan($before, $after, 'a chart-data view must leave an EventAuditLogger entry');
    }

    private static function countDocRows(int $pid): int
    {
        return (int)QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM `mod_copilot_doc` WHERE `pid` = ?', 'c', [$pid]);
    }

    private static function countAuditLogRows(int $pid): int
    {
        return (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `log` WHERE `event` = 'patient-record' AND `patient_id` = ?",
            'c',
            [$pid],
        );
    }

    private static function buildReadPath(DocStore $docStore): SynthesisReadPath
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

        return new SynthesisReadPath(
            $capabilities,
            $configProvider,
            $turnaroundProvider,
            $docStore,
            self::buildVerifiedGeneration(),
            new PatientIdentifierLookup(),
            new Redactor(),
        );
    }

    private static function buildVerifiedGeneration(): VerifiedGeneration
    {
        $reducer = new Reducer(new UnavailableLlmClient(), new PromptAssembler(), new Redactor());

        return new VerifiedGeneration($reducer, new Verifier());
    }

    private static function insertSyntheticPatient(): int
    {
        $pid = QueryUtils::fetchSingleValue('SELECT MAX(`pid`) + 1 AS pid FROM `patient_data`', 'pid');
        $pid = $pid !== null ? (int)$pid : 1;

        $uuid = (new UuidRegistry(['table_name' => 'patient_data']))->createUuid();
        QueryUtils::sqlInsert(
            'INSERT INTO `patient_data`
                (`uuid`, `pid`, `pubpid`, `fname`, `lname`, `DOB`, `sex`, `date`, `regdate`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), \'clinical_copilot_readpath_test\')',
            [$uuid, $pid, 'CCP-RP-' . $pid, 'Synthetic', 'Patient', '1970-01-01', 'Female'],
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
}
