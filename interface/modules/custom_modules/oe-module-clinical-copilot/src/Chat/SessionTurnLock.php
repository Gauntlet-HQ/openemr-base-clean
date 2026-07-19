<?php

/**
 * The "one active turn per session" guard (ARCHITECTURE.md §3.7): a cross-request mutex.
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
 * "One active turn per session -- a second POST while a turn is running is
 * rejected (HTTP 409 + client hint), which also makes double-submit and
 * two-tabs behavior deterministic" (ARCHITECTURE.md §3.7). Because a turn
 * executes synchronously inside one PHP request with no queue and no second
 * process to ask (§1.3: "the request IS the turn"), there is no in-database
 * "turn in progress" row to check -- the turn's own ledger row is only
 * written once the turn FINISHES. A real cross-request mutex is needed
 * instead: MySQL's `GET_LOCK()`/`RELEASE_LOCK()` named-lock pair, scoped to
 * this session's id, with a zero-second timeout so a concurrent holder is
 * detected immediately rather than queued (a queued second request would
 * violate "rejected", not merely "delayed").
 *
 * Deliberately NOT a PHP-level (single-process) lock: PHP-FPM/Apache serve
 * concurrent requests from different worker processes, so anything short of
 * a database-level lock would miss the very race this guard exists for.
 */
final class SessionTurnLock
{
    /**
     * Attempts to acquire the lock for `$sessionId` with no wait. Returns
     * true if acquired (caller MUST call {@see self::release()} when the
     * turn finishes, success or failure alike -- a `finally` block is the
     * caller's responsibility); false means another turn is already running
     * for this session (the 409 case).
     */
    public function tryAcquire(int $sessionId): bool
    {
        $result = QueryUtils::fetchSingleValue(
            'SELECT GET_LOCK(?, 0) AS l',
            'l',
            [self::lockName($sessionId)],
        );

        return (int)$result === 1;
    }

    public function release(int $sessionId): void
    {
        QueryUtils::fetchSingleValue(
            'SELECT RELEASE_LOCK(?) AS l',
            'l',
            [self::lockName($sessionId)],
        );
    }

    private static function lockName(int $sessionId): string
    {
        return "clinical_copilot_chat_session_{$sessionId}";
    }
}
