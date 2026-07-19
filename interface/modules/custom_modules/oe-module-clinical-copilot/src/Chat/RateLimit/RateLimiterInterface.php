<?php

/**
 * The seam U12's per-user/per-site rate-limit enforcement plugs into (ARCHITECTURE.md §3.7).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\RateLimit;

/**
 * ARCHITECTURE.md §3.7 splits the chat rate limits across two owners: "per
 * session: one active turn at a time ... max 30 turns per session" are
 * enforced directly by {@see \OpenEMR\Modules\ClinicalCopilot\Controller\ChatController}
 * itself (session-scoped, no config needed -- see that class); "per user:
 * max 3 active sessions; max 60 turns/hour" and the per-site daily spend
 * cap/hourly burn cap are versioned-config-driven and owned by U12, exactly
 * the same seam-now/implement-later decoupling
 * {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface} and
 * {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\AlertSinkInterface} already
 * establish for observability. {@see AllowAllRateLimiter} is the default
 * no-op until U12 supplies the real `mod_copilot_cadence` `rate_limit_breaker`
 * config-backed implementation (the config row already exists, seeded in
 * `table.sql`, ready for U12 to read).
 */
interface RateLimiterInterface
{
    public function checkTurn(int $pid, int $userId, int $sessionId): RateLimitDecision;
}
