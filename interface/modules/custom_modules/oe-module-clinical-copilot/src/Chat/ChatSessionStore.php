<?php

/**
 * Repository over mod_copilot_chat_session: insert, find, and the one legal mutation (freeze).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Common\Database\QueryUtils;

/**
 * Unlike {@see \OpenEMR\Modules\ClinicalCopilot\DocStore} and the chat turn
 * ledger (both append-only, I3/E7), `mod_copilot_chat_session` carries one
 * mutable column by design: `status` (ARCHITECTURE_COMPLETE.md "Module-owned
 * tables": "Frozen on a verifier V3 sev-1 trip"). {@see self::freeze()} is
 * therefore the ONE update path this class exposes, and it only ever writes
 * `frozen` -- there is no "unfreeze" method anywhere in this module (a
 * frozen session is preserved as evidence, never resumed, ARCHITECTURE.md
 * §2.3).
 */
final class ChatSessionStore
{
    /**
     * How long a session may sit idle before it auto-closes. A conversation
     * resumed after this window mints a fresh session instead. Matches the
     * "20 minutes after each session" lifecycle: the clock is measured from
     * the session's LAST activity (its most recent turn), so every turn
     * resets it. Deliberately a plain constant for now, the same way the
     * per-user caps live in config -- a future per-office control panel is the
     * right home for making this tunable.
     */
    public const IDLE_TIMEOUT_MINUTES = 20;

    public function insert(NewChatSession $newSession): int
    {
        $sql = 'INSERT INTO `mod_copilot_chat_session` (`pid`, `user_id`, `doc_id`, `fact_digest`, `status`)
                VALUES (?, ?, ?, ?, ?)';

        return QueryUtils::sqlInsert($sql, [
            $newSession->pid,
            $newSession->userId,
            $newSession->docId,
            $newSession->factDigest,
            ChatSessionStatus::Active->value,
        ]);
    }

    public function find(int $id): ?ChatSession
    {
        $row = QueryUtils::querySingleRow('SELECT * FROM `mod_copilot_chat_session` WHERE `id` = ?', [$id]);

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Latest active session for a user on a patient chart, if any. Used when
     * reopening the chat panel so a page refresh does not mint a fresh session
     * row (and trip the per-user active-session cap).
     */
    public function findLatestActiveForUserAndPid(int $userId, int $pid): ?ChatSession
    {
        $row = QueryUtils::querySingleRow(
            'SELECT * FROM `mod_copilot_chat_session`
             WHERE `user_id` = ? AND `pid` = ? AND `status` = ?
             ORDER BY `id` DESC
             LIMIT 1',
            [$userId, $pid, ChatSessionStatus::Active->value],
        );

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * ARCHITECTURE.md §2.3: a V3 sev-1 trip freezes the session -- "the
     * response is discarded, the session is frozen, the event is alerted."
     * Idempotent by construction (an UPDATE to an already-`frozen` row is a
     * harmless no-op), so a caller never needs to check current status
     * first.
     */
    public function freeze(int $id): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `mod_copilot_chat_session` SET `status` = ? WHERE `id` = ?',
            [ChatSessionStatus::Frozen->value, $id],
        );
    }

    /**
     * Auto-close ("expire") a user's stale chat sessions: every ACTIVE
     * session of theirs whose last activity is older than `$idleMinutes`.
     * "Last activity" is the most recent turn on the session, or the
     * session's own creation time when it has no turns yet -- so an untouched,
     * just-opened session is given the full idle window before it can expire.
     *
     * This is the counterpart to the per-user active-session cap enforced in
     * {@see \OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceRateLimiter}:
     * without it, sessions only ever leave `active` on a V3 freeze, so a
     * clinician who opens chat across a handful of charts over a shift steadily
     * accumulates active sessions until the cap denies every new turn BEFORE
     * the LLM is ever called. Expiring idle sessions keeps the count honest --
     * an abandoned session frees its slot instead of pinning it forever.
     *
     * Purely a status transition: it never creates or reads a session, so it
     * is safe on any user-facing request. It is deliberately NOT called from
     * the background worker -- the worker must neither create nor maintain
     * chat sessions.
     *
     * Frozen sessions are untouched (a frozen session is preserved as
     * evidence, ARCHITECTURE.md §2.3; the `status = 'active'` predicate
     * already excludes them).
     *
     * @param int|null $exceptSessionId a session to leave untouched even if it
     *        looks idle -- the one the current turn runs on, whose activity
     *        (the message being answered) has not been written to the ledger
     *        yet at the point this sweep runs.
     */
    public function expireIdleForUser(int $userId, ?int $exceptSessionId = null, int $idleMinutes = self::IDLE_TIMEOUT_MINUTES): void
    {
        $cutoff = (new \DateTimeImmutable("-{$idleMinutes} minutes"))->format('Y-m-d H:i:s');

        $sql = 'UPDATE `mod_copilot_chat_session`
                SET `status` = ?
                WHERE `user_id` = ?
                  AND `status` = ?
                  AND COALESCE(
                        (SELECT MAX(`t`.`created_at`)
                           FROM `mod_copilot_chat_turn` `t`
                          WHERE `t`.`session_id` = `mod_copilot_chat_session`.`id`),
                        `mod_copilot_chat_session`.`created_at`
                      ) < ?';
        $params = [
            ChatSessionStatus::Expired->value,
            $userId,
            ChatSessionStatus::Active->value,
            $cutoff,
        ];

        if ($exceptSessionId !== null) {
            $sql .= ' AND `id` <> ?';
            $params[] = $exceptSessionId;
        }

        QueryUtils::sqlStatementThrowException($sql, $params);
    }

    /**
     * Count a user's currently-active sessions (optionally excluding one) --
     * the same population the per-user cap counts. Used to tell the clinician
     * how many the manual "release sessions" control just freed.
     */
    public function countActiveForUser(int $userId, ?int $exceptSessionId = null): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM `mod_copilot_chat_session` WHERE `user_id` = ? AND `status` = ?';
        $params = [$userId, ChatSessionStatus::Active->value];
        if ($exceptSessionId !== null) {
            $sql .= ' AND `id` <> ?';
            $params[] = $exceptSessionId;
        }

        return (int)QueryUtils::fetchSingleValue($sql, 'c', $params);
    }

    /**
     * Force-close ALL of a user's active sessions right now, regardless of how
     * recently they were used (unlike {@see self::expireIdleForUser()}, which
     * only releases sessions past the idle window). This backs the manual
     * "release sessions" escape hatch: when a clinician is juggling more live
     * charts than the per-user cap allows and every turn is being denied, one
     * click frees the slots so chat works again immediately.
     *
     * `$exceptSessionId` keeps the session the clinician is actively in, so the
     * current conversation survives while the abandoned ones are cleared.
     * Frozen sessions are untouched (excluded by the `status = 'active'`
     * predicate).
     */
    public function expireAllForUser(int $userId, ?int $exceptSessionId = null): void
    {
        $sql = 'UPDATE `mod_copilot_chat_session` SET `status` = ? WHERE `user_id` = ? AND `status` = ?';
        $params = [ChatSessionStatus::Expired->value, $userId, ChatSessionStatus::Active->value];
        if ($exceptSessionId !== null) {
            $sql .= ' AND `id` <> ?';
            $params[] = $exceptSessionId;
        }

        QueryUtils::sqlStatementThrowException($sql, $params);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): ChatSession
    {
        return new ChatSession(
            (int)$row['id'],
            (int)$row['pid'],
            (int)$row['user_id'],
            $row['doc_id'] !== null ? (int)$row['doc_id'] : null,
            (string)$row['fact_digest'],
            ChatSessionStatus::tryFrom((string)$row['status']) ?? ChatSessionStatus::Frozen,
            self::parseDateTime((string)$row['created_at']),
        );
    }

    private static function parseDateTime(string $value): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $parsed !== false ? $parsed : new \DateTimeImmutable($value);
    }
}
