<?php

/**
 * Append-only repository over mod_copilot_chat_turn (same ledger philosophy as T7).
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
use OpenEMR\Common\Logging\SystemLogger;

/**
 * I3/E7-style append-only: exactly two public methods, {@see self::insert()}
 * and {@see self::forSession()} -- no update, no delete, mirroring
 * {@see \OpenEMR\Modules\ClinicalCopilot\DocStore}'s own "the method list IS
 * the audit" discipline.
 */
final class ChatTurnStore
{
    public function __construct(
        private readonly SystemLogger $logger = new SystemLogger(),
    ) {
    }

    public function insert(NewChatTurn $newTurn): int
    {
        $sql = 'INSERT INTO `mod_copilot_chat_turn`
            (`session_id`, `seq`, `role`, `content`, `tool_calls`, `verification_verdict`,
             `correlation_id`, `tokens_in`, `tokens_out`, `cost_usd`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        return QueryUtils::sqlInsert($sql, [
            $newTurn->sessionId,
            $newTurn->seq,
            $newTurn->role->value,
            self::encodeJson($newTurn->content),
            $newTurn->toolCalls !== null ? self::encodeJson($newTurn->toolCalls) : null,
            $newTurn->verificationVerdict !== null ? self::encodeJson($newTurn->verificationVerdict) : null,
            $newTurn->correlationId,
            $newTurn->tokensIn,
            $newTurn->tokensOut,
            $newTurn->costUsd,
        ]);
    }

    /**
     * Every turn row for a session, oldest first (`seq` ascending) -- the
     * order the agent loop replays to rebuild conversation history and the
     * accumulated tool-result fact set (ARCHITECTURE.md §1.1). A row whose
     * `role` does not parse (a corrupt/hand-edited row) is skipped rather
     * than crashing the whole replay -- see {@see self::hydrate()}.
     *
     * @return list<ChatTurn>
     */
    public function forSession(int $sessionId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM `mod_copilot_chat_turn` WHERE `session_id` = ? ORDER BY `seq` ASC, `id` ASC',
            [$sessionId],
        );

        return $this->hydrateAll($rows);
    }

    /**
     * Count of `assistant`-role rows -- the turn count the 30-turns/session
     * cap (ARCHITECTURE.md §3.7) counts against (user/tool rows are not
     * separately-billable "turns" in that sense; one physician question, one
     * assistant answer, one unit against the cap).
     */
    public function countAssistantTurns(int $sessionId): int
    {
        return (int)QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM `mod_copilot_chat_turn` WHERE `session_id` = ? AND `role` = ?',
            'c',
            [$sessionId, ChatTurnRole::Assistant->value],
        );
    }

    /**
     * Every turn row sharing one correlation id (the user row, any tool
     * rows, and the assistant row a single turn produces) -- what
     * `public/status.php` uses to find the owning session (via the earliest
     * row, always the `user` role, written before the turn's LLM/tool work
     * even starts) and to detect completion (the presence of an `assistant`
     * row).
     *
     * @return list<ChatTurn>
     */
    public function findByCorrelationId(string $correlationId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM `mod_copilot_chat_turn` WHERE `correlation_id` = ? ORDER BY `seq` ASC, `id` ASC',
            [$correlationId],
        );

        return $this->hydrateAll($rows);
    }

    public function nextSeq(int $sessionId): int
    {
        $max = QueryUtils::fetchSingleValue(
            'SELECT MAX(`seq`) AS m FROM `mod_copilot_chat_turn` WHERE `session_id` = ?',
            'm',
            [$sessionId],
        );

        return $max !== null ? (int)$max + 1 : 1;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<ChatTurn>
     */
    private function hydrateAll(array $rows): array
    {
        $turns = [];
        foreach ($rows as $row) {
            $turn = $this->hydrate($row);
            if ($turn !== null) {
                $turns[] = $turn;
            }
        }

        return $turns;
    }

    /**
     * Returns null -- and logs once -- when `role` does not parse into
     * {@see ChatTurnRole}: a corrupt/hand-edited row, not a value this
     * method can safely coerce into any particular role. The caller
     * ({@see self::hydrateAll()}) skips it rather than including a
     * fabricated role in the replayed history; `seq` ordering of the
     * surviving rows is unaffected since the caller filters in place.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ?ChatTurn
    {
        $role = ChatTurnRole::tryFrom((string)$row['role']);
        if ($role === null) {
            $this->logger->error('ClinicalCopilot: skipping chat_turn row with unrecognised role', [
                'turn_id' => (int)$row['id'],
                'role' => $row['role'],
            ]);

            return null;
        }

        $content = json_decode((string)$row['content'], true);
        $toolCallsRaw = $row['tool_calls'] ?? null;
        $toolCalls = is_string($toolCallsRaw) ? json_decode($toolCallsRaw, true) : null;
        $verdictRaw = $row['verification_verdict'] ?? null;
        $verdict = is_string($verdictRaw) ? json_decode($verdictRaw, true) : null;

        return new ChatTurn(
            (int)$row['id'],
            (int)$row['session_id'],
            (int)$row['seq'],
            $role,
            is_array($content) ? $content : [],
            is_array($toolCalls) ? $toolCalls : null,
            is_array($verdict) ? $verdict : null,
            (string)$row['correlation_id'],
            $row['tokens_in'] !== null ? (int)$row['tokens_in'] : null,
            $row['tokens_out'] !== null ? (int)$row['tokens_out'] : null,
            $row['cost_usd'] !== null ? (float)$row['cost_usd'] : null,
            self::parseDateTime((string)$row['created_at']),
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function parseDateTime(string $value): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $parsed !== false ? $parsed : new \DateTimeImmutable($value);
    }
}
