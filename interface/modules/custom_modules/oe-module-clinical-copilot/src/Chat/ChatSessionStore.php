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
