<?php

/**
 * DB-backed U11 acceptance eval: T19's cheap, LLM-free per-turn digest drift check.
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
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\DbLabTurnaroundConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Capability\ControlProxy;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedResponse;
use OpenEMR\Modules\ClinicalCopilot\Capability\OverdueTests;
use OpenEMR\Modules\ClinicalCopilot\Capability\PendingResults;
use OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatFreshnessChecker;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
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
 * T19 (docs/clinical-copilot-tradeoffs.md): "every turn runs the cheap half
 * of the machinery -- fact re-extraction + digest compare ... no LLM
 * involved." {@see ChatFreshnessChecker} MUST compute the identical digest
 * {@see SynthesisReadPath} computes for the same DB state, or every turn
 * would falsely report drift -- this eval proves that identity holds AND
 * that a genuine data change is detected.
 */
final class ChatFreshnessCheckerTest extends TestCase
{
    private const LOINC_A1C = '4548-4';

    private int $pid;
    private SynthesisReadPath $readPath;
    private ChatFreshnessChecker $checker;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->pid = self::insertSyntheticPatient();

        $labContractConfigProvider = new DbLabContractConfigProvider();
        $labSliceReader = new LabSliceReader($labContractConfigProvider);
        $turnaroundConfigProvider = new DbLabTurnaroundConfigProvider();
        $capabilities = [
            new ControlProxy($labSliceReader),
            new MedResponse(new PrescriptionService(), $labSliceReader),
            new VitalsTrend(),
            new OverdueTests($labSliceReader, $labContractConfigProvider, ServiceContainer::getClock()),
            new PendingResults($labSliceReader, $turnaroundConfigProvider),
        ];

        $reducer = new Reducer(new UnavailableLlmClient(), new PromptAssembler(), new Redactor());
        $this->readPath = new SynthesisReadPath(
            $capabilities,
            $labContractConfigProvider,
            $turnaroundConfigProvider,
            new DocStore(),
            new VerifiedGeneration($reducer, new Verifier()),
            new PatientIdentifierLookup(),
            new Redactor(),
        );
        $this->checker = new ChatFreshnessChecker($capabilities, $labContractConfigProvider, $turnaroundConfigProvider);
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    public function testNoDriftWhenDbStateIsUnchanged(): void
    {
        self::insertA1cResult($this->pid, '7.2', '2025-01-10');
        $served = $this->readPath->read($this->pid, 1);

        self::assertFalse($this->checker->hasDrifted($this->pid, (string)$served->factDigest));
    }

    public function testDriftDetectedAfterANewLabArrives(): void
    {
        self::insertA1cResult($this->pid, '7.2', '2025-01-10');
        $served = $this->readPath->read($this->pid, 1);

        self::insertA1cResult($this->pid, '8.1', '2025-06-01');

        self::assertTrue($this->checker->hasDrifted($this->pid, (string)$served->factDigest));
    }

    private static function insertSyntheticPatient(): int
    {
        $pid = QueryUtils::fetchSingleValue('SELECT MAX(`pid`) + 1 AS pid FROM `patient_data`', 'pid');
        $pid = $pid !== null ? (int)$pid : 1;

        $uuid = (new UuidRegistry(['table_name' => 'patient_data']))->createUuid();
        QueryUtils::sqlInsert(
            'INSERT INTO `patient_data`
                (`uuid`, `pid`, `pubpid`, `fname`, `lname`, `DOB`, `sex`, `date`, `regdate`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), \'clinical_copilot_freshness_test\')',
            [$uuid, $pid, 'CCP-FRESH-' . $pid, 'Synthetic', 'Patient', '1970-01-01', 'Female'],
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
