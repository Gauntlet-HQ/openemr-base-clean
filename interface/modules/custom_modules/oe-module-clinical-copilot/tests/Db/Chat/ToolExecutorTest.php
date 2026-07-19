<?php

/**
 * DB-backed U11 acceptance evals: ToolExecutor against real, pid-pinned capabilities.
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
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallRequest;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolExecutor;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\NullAlertSink;
use OpenEMR\Services\PrescriptionService;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE_COMPLETE.md U11 row: "tool executor (JSON-Schema args,
 * server-side pid injection, pid assertion on return, ≤5 calls / ≤3
 * rounds)." Exercises this against the SAME real capability wiring
 * {@see \OpenEMR\Modules\ClinicalCopilot\Controller\ChatController::buildChatAgent()}
 * uses (unlike `tests/Isolated/Chat/`, which stubs {@see \OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolExecutorInterface}
 * entirely -- this suite is what proves the REAL implementation behaves the
 * same way the stub assumes).
 *
 * Note on scope: the pid-MISMATCH escalation branch inside
 * {@see ToolExecutor::execute()} (a returned fact whose pid differs from the
 * pinned one) cannot be triggered here -- every real capability always
 * stamps facts with the pid it was called with, by construction, so a
 * genuine mismatch never happens through this path; it exists purely as
 * defense-in-depth against a future capability defect. That branch is
 * exercised indirectly by `tests/Isolated/Chat/CrossPatientForgedCitationTest.php`,
 * which proves the equivalent independent re-check at the verifier layer
 * (V3) catches the same class of defect.
 */
final class ToolExecutorTest extends TestCase
{
    private const LOINC_A1C = '4548-4';

    private ToolExecutor $executor;
    private int $pid;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->pid = self::insertSyntheticPatient();
        $this->executor = self::buildExecutor($this->pid);
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    public function testUnrecognizedToolNameFails(): void
    {
        $outcome = $this->executor->execute(new ToolCallRequest('get_something_else', []));

        self::assertFalse($outcome->ok);
        self::assertStringContainsString('unrecognized tool', (string)$outcome->errorMessage);
    }

    public function testMissingRequiredArgumentFails(): void
    {
        $outcome = $this->executor->execute(new ToolCallRequest('get_control_trend', ['analyte' => 'a1c']));

        self::assertFalse($outcome->ok);
        self::assertStringContainsString('invalid arguments', (string)$outcome->errorMessage);
        self::assertStringContainsString('window_months', (string)$outcome->errorMessage);
    }

    /**
     * I10's structural guarantee, proven at the executor boundary: a forged
     * `pid` argument is rejected as an unrecognized property before any
     * capability method is ever invoked -- there is no code path from a tool
     * call's arguments to a different patient's data.
     */
    public function testForgedPidArgumentIsRejectedBeforeAnyCapabilityRuns(): void
    {
        $outcome = $this->executor->execute(new ToolCallRequest('get_control_trend', [
            'analyte' => 'a1c',
            'window_months' => 12,
            'pid' => $this->pid + 1000,
        ]));

        self::assertFalse($outcome->ok);
        self::assertStringContainsString("unrecognized argument 'pid'", (string)$outcome->errorMessage);
    }

    public function testValidControlTrendCallReturnsFactsPinnedToTheSessionPid(): void
    {
        self::insertA1cResult($this->pid, '7.4', '2025-05-01');

        $outcome = $this->executor->execute(new ToolCallRequest('get_control_trend', ['analyte' => 'a1c', 'window_months' => 12]));

        self::assertTrue($outcome->ok);
        self::assertNotEmpty($outcome->facts);
        foreach ($outcome->facts as $fact) {
            self::assertSame($this->pid, $fact->pid, 'every fact returned to the agent loop must carry the pinned pid');
        }
    }

    public function testNoArgumentToolsRunCleanly(): void
    {
        $overdue = $this->executor->execute(new ToolCallRequest('get_overdue', []));
        $pending = $this->executor->execute(new ToolCallRequest('get_pending', []));

        self::assertTrue($overdue->ok);
        self::assertTrue($pending->ok);
    }

    private static function buildExecutor(int $pid): ToolExecutor
    {
        $labContractConfigProvider = new DbLabContractConfigProvider();
        $labSliceReader = new LabSliceReader($labContractConfigProvider);
        $turnaroundConfigProvider = new DbLabTurnaroundConfigProvider();

        return new ToolExecutor(
            $pid,
            'test-correlation-id',
            new ControlProxy($labSliceReader),
            new MedResponse(new PrescriptionService(), $labSliceReader),
            new VitalsTrend(),
            new OverdueTests($labSliceReader, $labContractConfigProvider, ServiceContainer::getClock()),
            new PendingResults($labSliceReader, $turnaroundConfigProvider),
            new NullAlertSink(),
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
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), \'clinical_copilot_chat_test\')',
            [$uuid, $pid, 'CCP-CHAT-' . $pid, 'Synthetic', 'Patient', '1970-01-01', 'Female'],
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
