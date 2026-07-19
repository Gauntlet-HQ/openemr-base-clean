<?php

/**
 * Default no-op RateLimiterInterface (allow-all) until U12 wires the real per-user/per-site caps.
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
 * Mirrors {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\NullTraceRecorder}/
 * {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\NullAlertSink}'s role: wired
 * everywhere by default so the seam compiles and every chat eval runs today,
 * with zero behavior until U12 supplies a real implementation.
 */
final class AllowAllRateLimiter implements RateLimiterInterface
{
    public function checkTurn(int $pid, int $userId, int $sessionId): RateLimitDecision
    {
        return RateLimitDecision::allow();
    }
}
