<?php

/**
 * DB-backed U11 acceptance evals: ChatController turn execution -- identity, malformed input, caps, degradation.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Chat;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnRole;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\NewChatTurn;
use OpenEMR\Modules\ClinicalCopilot\Controller\ChatController;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * No live LLM calls anywhere (build-notes.md): `CLINICAL_COPILOT_GCP_PROJECT_ID`
 * is unset in this environment, so every turn here degrades through
 * {@see \OpenEMR\Modules\ClinicalCopilot\Chat\Llm\UnavailableChatLlmClient}
 * (I6) -- the honest, LLM-free dev/test default this whole suite runs
 * against, exactly like `tests/Db/ReadPath/SynthesisReadPathTest.php` does
 * for the synthesis path.
 */
final class ChatControllerTurnTest extends TestCase
{
    private const LOINC_A1C = '4548-4';

    private ChatController $controller;
    private ChatSessionStore $sessionStore;
    private ChatTurnStore $turnStore;
    private int $pid;
    private const USER_ID = 1;

    protected function setUp(): void
    {
        // These evals assert the verifier GATE's behaviour, so pin it enforced
        // regardless of the (currently-disabled) runtime default -- see
        // OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPolicy.
        putenv('CLINICAL_COPILOT_VERIFY_ENFORCE=1');
        QueryUtils::startTransaction();
        $this->pid = self::insertSyntheticPatient();
        self::insertA1cResult($this->pid, '7.4', '2025-05-01');
        $this->controller = ChatController::createDefault();
        $this->sessionStore = new ChatSessionStore();
        $this->turnStore = new ChatTurnStore();
    }

    protected function tearDown(): void
    {
        putenv('CLINICAL_COPILOT_VERIFY_ENFORCE');
        QueryUtils::rollbackTransaction();
    }

    private function startSession(): int
    {
        $result = $this->controller->startSession($this->pid, self::USER_ID);
        self::assertTrue($result['ok'], (string)($result['reason'] ?? ''));

        return (int)$result['session_id'];
    }

    public function testUnknownSessionIsRejected(): void
    {
        $result = $this->controller->submitTurn(999999999, self::USER_ID, 'hello');

        self::assertFalse($result['ok']);
        self::assertSame(404, $result['http_status']);
    }

    public function testWrongUserIsRejected(): void
    {
        $sessionId = $this->startSession();

        $result = $this->controller->submitTurn($sessionId, self::USER_ID + 1, 'hello');

        self::assertFalse($result['ok']);
        self::assertSame(403, $result['http_status']);
    }

    public function testFrozenSessionIsRejected(): void
    {
        $sessionId = $this->startSession();
        $this->sessionStore->freeze($sessionId);

        $result = $this->controller->submitTurn($sessionId, self::USER_ID, 'hello');

        self::assertFalse($result['ok']);
        self::assertSame(423, $result['http_status']);
    }

    public function testEmptyMessageIsRejectedCleanly(): void
    {
        $sessionId = $this->startSession();

        $result = $this->controller->submitTurn($sessionId, self::USER_ID, '   ');

        self::assertFalse($result['ok']);
        self::assertSame(400, $result['http_status']);
    }

    public function testOversizedMessageIsRejectedCleanly(): void
    {
        $sessionId = $this->startSession();

        $result = $this->controller->submitTurn($sessionId, self::USER_ID, str_repeat('a', 5000));

        self::assertFalse($result['ok']);
        self::assertSame(400, $result['http_status']);
    }

    public function testGarbageBytesMessageDoesNotCrash(): void
    {
        $sessionId = $this->startSession();

        // Malformed/garbage input (ARCHITECTURE.md §5 boundary eval): must
        // get a clean response, never an uncaught exception.
        $result = $this->controller->submitTurn($sessionId, self::USER_ID, "\x00\x01\xFF garbage \xC0\xC0");

        self::assertIsArray($result);
        self::assertArrayHasKey('ok', $result);
    }

    public function testMaxTurnsPerSessionCapIsEnforced(): void
    {
        $sessionId = $this->startSession();

        for ($i = 0; $i < 30; $i++) {
            $this->turnStore->insert(new NewChatTurn(
                $sessionId,
                $this->turnStore->nextSeq($sessionId),
                ChatTurnRole::Assistant,
                ['claims' => null, 'verify_status' => 'degraded', 'degraded_reason' => 'llm_unavailable', 'degraded_message' => 'x', 'frozen' => false],
                null,
                null,
                Uuid::uuid7()->toString(),
                null,
                null,
                null,
            ));
        }

        $result = $this->controller->submitTurn($sessionId, self::USER_ID, 'one more question');

        self::assertFalse($result['ok']);
        self::assertSame(429, $result['http_status']);
    }

    public function testDegradedTurnRendersFactsWithNoLiveLlm(): void
    {
        $sessionId = $this->startSession();

        $result = $this->controller->submitTurn($sessionId, self::USER_ID, 'What is her A1c?');

        self::assertTrue($result['ok']);
        self::assertSame('degraded', $result['verify_status']);
        self::assertNotEmpty($result['degraded_message']);
        self::assertFalse($result['frozen']);
        self::assertNotEmpty($result['facts'], 'the facts browser must still show the preloaded facts');

        $turns = $this->turnStore->forSession($sessionId);
        $roles = array_map(static fn ($t) => $t->role, $turns);
        self::assertContains(ChatTurnRole::User, $roles);
        self::assertContains(ChatTurnRole::Assistant, $roles);
    }

    private static function insertSyntheticPatient(): int
    {
        $pid = QueryUtils::fetchSingleValue('SELECT MAX(`pid`) + 1 AS pid FROM `patient_data`', 'pid');
        $pid = $pid !== null ? (int)$pid : 1;

        $uuid = (new UuidRegistry(['table_name' => 'patient_data']))->createUuid();
        QueryUtils::sqlInsert(
            'INSERT INTO `patient_data`
                (`uuid`, `pid`, `pubpid`, `fname`, `lname`, `DOB`, `sex`, `date`, `regdate`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), \'clinical_copilot_chat_controller_test\')',
            [$uuid, $pid, 'CCP-CTRL-' . $pid, 'Synthetic', 'Patient', '1970-01-01', 'Female'],
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
