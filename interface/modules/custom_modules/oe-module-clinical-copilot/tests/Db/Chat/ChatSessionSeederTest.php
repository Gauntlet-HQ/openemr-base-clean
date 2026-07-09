<?php

/**
 * DB-backed U11 acceptance evals: session creation pinned to (pid, doc, digest); refusal on capability crash.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Chat;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityInterface;
use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityResult;
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\DbLabTurnaroundConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Capability\ControlProxy;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedResponse;
use OpenEMR\Modules\ClinicalCopilot\Capability\OverdueTests;
use OpenEMR\Modules\ClinicalCopilot\Capability\PendingResults;
use OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionSeeder;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionStatus;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionStore;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\PatientIdentifierLookup;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\UnavailableLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGeneration;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use OpenEMR\Services\PrescriptionService;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §1.1: "the session is preloaded with the exact
 * content-addressed doc the physician is reading." No live LLM anywhere
 * (build-notes.md) -- {@see UnavailableLlmClient} throughout, exactly what
 * {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory} hands
 * back with no Vertex project configured (the honest default in this
 * environment), so every seeded session is preloaded from a `degraded`
 * (facts-only) doc -- still a legal, fully-formed seed (I6).
 */
final class ChatSessionSeederTest extends TestCase
{
    private const LOINC_A1C = '4548-4';

    private DocStore $docStore;
    private ChatSessionStore $sessionStore;
    private int $pid;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->pid = self::insertSyntheticPatient();
        $this->docStore = new DocStore();
        $this->sessionStore = new ChatSessionStore();
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    public function testSeedCreatesAnActiveSessionPinnedToPidAndTheServedDigest(): void
    {
        self::insertA1cResult($this->pid, '7.4', '2025-05-01');

        $readPath = self::buildReadPath($this->docStore);
        $seeder = new ChatSessionSeeder($readPath, $this->sessionStore);

        $session = $seeder->seed($this->pid, 1);

        self::assertNotNull($session);
        self::assertSame($this->pid, $session->pid);
        self::assertSame(1, $session->userId);
        self::assertSame(ChatSessionStatus::Active, $session->status);
        self::assertNotNull($session->docId);
        self::assertNotSame('', $session->factDigest);

        // The session's docId/factDigest must resolve back to a real,
        // just-served row -- "turn 1 needs zero retrieval" only holds if the
        // preload actually points at a real doc.
        $docRow = $this->docStore->findBest($this->pid, $session->factDigest);
        self::assertNotNull($docRow);
        self::assertSame($session->docId, $docRow->id);
    }

    public function testSeedReusesTheLatestActiveSessionWhenDigestIsUnchanged(): void
    {
        self::insertA1cResult($this->pid, '7.4', '2025-05-01');

        $readPath = self::buildReadPath($this->docStore);
        $seeder = new ChatSessionSeeder($readPath, $this->sessionStore);

        $first = $seeder->seed($this->pid, 1);
        $second = $seeder->seed($this->pid, 1);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->id, $second->id);
    }

    public function testSeedRefusesWhenACapabilityCrashes(): void
    {
        self::insertA1cResult($this->pid, '7.4', '2025-05-01');

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

        $seeder = new ChatSessionSeeder($readPath, $this->sessionStore);
        $session = $seeder->seed($this->pid, 1);

        self::assertNull($session, 'no safe fact set exists to seed a session from after a capability crash');
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
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), \'clinical_copilot_chat_seeder_test\')',
            [$uuid, $pid, 'CCP-SEED-' . $pid, 'Synthetic', 'Patient', '1970-01-01', 'Female'],
        );

        return $pid;
    }

    private static function insertA1cResult(int $pid, string $value, string $date): int
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
             VALUES (?, \'N\', ?, \'Hemoglobin A1c\', ?, \'%\', ?, \'\', \'\', \'final\')',
            [$reportId, self::LOINC_A1C, $date, $value],
        );
    }
}
