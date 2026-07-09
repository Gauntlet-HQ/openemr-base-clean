<?php

/**
 * DB-backed evals: idle chat sessions auto-close and stop pinning the per-user active-session cap.
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
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionStatus;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnRole;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\NewChatSession;
use OpenEMR\Modules\ClinicalCopilot\Chat\NewChatTurn;
use OpenEMR\Modules\ClinicalCopilot\Controller\ChatController;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * The regression this guards: `mod_copilot_chat_session.status` only ever left
 * `active` on a V3 freeze, so sessions a clinician opened across charts over a
 * shift accumulated forever and, once past the per-user active-session cap
 * ({@see \OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceRateLimiter}),
 * every new turn was denied 429 BEFORE the LLM was ever called. Idle sessions
 * now auto-close after {@see ChatSessionStore::IDLE_TIMEOUT_MINUTES} minutes so
 * the cap reflects only genuinely-live sessions.
 *
 * No live LLM calls: `CLINICAL_COPILOT_GCP_PROJECT_ID` is unset here, so a
 * submitted turn degrades through the Unavailable client (I6) -- which is
 * exactly what this test wants, since the point is only that the turn is
 * *reached* (200/ok) rather than blocked by the cap.
 */
final class ChatSessionExpiryTest extends TestCase
{
    private const LOINC_A1C = '4548-4';
    private const USER_ID = 1;

    private ChatController $controller;
    private ChatSessionStore $sessionStore;
    private ChatTurnStore $turnStore;
    private int $pid;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->pid = self::insertSyntheticPatient();
        self::insertA1cResult($this->pid, '7.4', '2025-05-01');
        $this->controller = ChatController::createDefault();
        $this->sessionStore = new ChatSessionStore();
        $this->turnStore = new ChatTurnStore();
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    public function testIdleSessionIsExpiredButRecentSessionSurvives(): void
    {
        $idleId = $this->insertSessionAgedMinutes(90);
        $recentId = $this->insertSessionAgedMinutes(1);

        $this->sessionStore->expireIdleForUser(self::USER_ID);

        self::assertSame(ChatSessionStatus::Expired, $this->sessionStore->find($idleId)?->status);
        self::assertSame(ChatSessionStatus::Active, $this->sessionStore->find($recentId)?->status);
    }

    public function testRecentTurnKeepsAnOtherwiseOldSessionAlive(): void
    {
        // Session row is old, but its most recent turn is fresh -- last
        // activity, not creation time, drives the idle clock, so it survives.
        $sessionId = $this->insertSessionAgedMinutes(90);
        $this->turnStore->insert(new NewChatTurn(
            $sessionId,
            $this->turnStore->nextSeq($sessionId),
            ChatTurnRole::User,
            ['text' => 'still here'],
            null,
            null,
            Uuid::uuid7()->toString(),
            null,
            null,
            null,
        ));

        $this->sessionStore->expireIdleForUser(self::USER_ID);

        self::assertSame(ChatSessionStatus::Active, $this->sessionStore->find($sessionId)?->status);
    }

    public function testExceptSessionIdIsNeverExpired(): void
    {
        $currentId = $this->insertSessionAgedMinutes(90);

        // The turn path passes the running session as the exception: its
        // activity for this turn is not on the ledger yet, so it must not be
        // swept out from under an in-flight turn.
        $this->sessionStore->expireIdleForUser(self::USER_ID, exceptSessionId: $currentId);

        self::assertSame(ChatSessionStatus::Active, $this->sessionStore->find($currentId)?->status);
    }

    public function testFrozenSessionIsNeverExpired(): void
    {
        $frozenId = $this->insertSessionAgedMinutes(90);
        $this->sessionStore->freeze($frozenId);

        $this->sessionStore->expireIdleForUser(self::USER_ID);

        self::assertSame(ChatSessionStatus::Frozen, $this->sessionStore->find($frozenId)?->status);
    }

    public function testAccumulatedIdleSessionsNoLongerBlockANewTurn(): void
    {
        // Five abandoned, idle sessions for this user -- well past the default
        // cap of 3 active sessions. Before idle-expiry this alone made every
        // turn fail 429 "max active sessions reached".
        for ($i = 0; $i < 5; $i++) {
            $this->insertSessionAgedMinutes(90);
        }

        $sessionId = $this->startSession();
        $result = $this->controller->submitTurn($sessionId, self::USER_ID, 'What is her A1c?');

        self::assertTrue($result['ok'], 'accumulated idle sessions must not block a fresh turn: ' . (string)($result['reason'] ?? ''));
        self::assertArrayNotHasKey('http_status', $result);
    }

    public function testReleaseSessionsClearsOthersImmediatelyButKeepsCurrent(): void
    {
        // All three are fresh (1 min old) -- the idle sweep would NOT touch
        // them. The manual release must clear them regardless, which is the
        // whole point of the escape hatch for a clinician juggling live charts.
        $current = $this->insertSessionAgedMinutes(1);
        $other1 = $this->insertSessionAgedMinutes(1);
        $other2 = $this->insertSessionAgedMinutes(1);

        $result = $this->controller->releaseSessions(self::USER_ID, $current);

        self::assertTrue($result['ok']);
        self::assertSame(2, $result['released']);
        self::assertSame(ChatSessionStatus::Active, $this->sessionStore->find($current)?->status, 'the current session is kept');
        self::assertSame(ChatSessionStatus::Expired, $this->sessionStore->find($other1)?->status);
        self::assertSame(ChatSessionStatus::Expired, $this->sessionStore->find($other2)?->status);
    }

    public function testTurnOnAnExpiredSessionSignalsReseedInsteadOfAnswering(): void
    {
        $sessionId = $this->startSession();
        // Simulate the session having auto-closed (e.g. swept by another tab's
        // bootstrap) after the client had already loaded its id.
        QueryUtils::sqlStatementThrowException(
            'UPDATE `mod_copilot_chat_session` SET `status` = ? WHERE `id` = ?',
            [ChatSessionStatus::Expired->value, $sessionId],
        );

        $result = $this->controller->submitTurn($sessionId, self::USER_ID, 'still there?');

        self::assertFalse($result['ok']);
        self::assertSame(409, $result['http_status']);
        self::assertTrue($result['session_expired'] ?? false, 'the client needs the flag to re-seed and resend');
    }

    private function startSession(): int
    {
        $result = $this->controller->startSession($this->pid, self::USER_ID);
        self::assertTrue($result['ok'], (string)($result['reason'] ?? ''));

        return (int)$result['session_id'];
    }

    /**
     * Inserts an ACTIVE session for USER_ID whose `created_at` is `$minutes`
     * in the past (the store's insert always stamps NOW(), so the age is set
     * with a follow-up update -- the only way to exercise the idle window
     * deterministically in a test).
     */
    private function insertSessionAgedMinutes(int $minutes): int
    {
        $id = $this->sessionStore->insert(new NewChatSession($this->pid, self::USER_ID, null, str_repeat('0', 64)));
        QueryUtils::sqlStatementThrowException(
            'UPDATE `mod_copilot_chat_session` SET `created_at` = ? WHERE `id` = ?',
            [(new \DateTimeImmutable("-{$minutes} minutes"))->format('Y-m-d H:i:s'), $id],
        );

        return $id;
    }

    private static function insertSyntheticPatient(): int
    {
        $pid = QueryUtils::fetchSingleValue('SELECT MAX(`pid`) + 1 AS pid FROM `patient_data`', 'pid');
        $pid = $pid !== null ? (int)$pid : 1;

        $uuid = (new UuidRegistry(['table_name' => 'patient_data']))->createUuid();
        QueryUtils::sqlInsert(
            'INSERT INTO `patient_data`
                (`uuid`, `pid`, `pubpid`, `fname`, `lname`, `DOB`, `sex`, `date`, `regdate`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), \'clinical_copilot_chat_expiry_test\')',
            [$uuid, $pid, 'CCP-EXP-' . $pid, 'Synthetic', 'Patient', '1970-01-01', 'Female'],
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
