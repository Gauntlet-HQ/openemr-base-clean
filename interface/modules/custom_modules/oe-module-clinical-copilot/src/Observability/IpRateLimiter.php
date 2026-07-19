<?php

/**
 * Minimal per-IP rate limiter for the unauthenticated /copilot/ready endpoint.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

/**
 * ARCHITECTURE.md §3.4: "/copilot/ready ... per-IP rate-limited." This is an
 * unauthenticated endpoint, so a DB-backed limiter would itself be a probe
 * target abusable to generate write load -- deliberately backed by APCu (a
 * process-local, ephemeral counter) instead, matching this check's own
 * "cheap, no side effects" requirement. APCu is not guaranteed present in
 * every PHP-FPM configuration; when unavailable, this fails OPEN (allows the
 * request) rather than failing the whole endpoint closed -- an accepted,
 * documented limitation (see the U12 report) rather than a hidden one: a
 * missing APCu extension must never turn an unauthenticated liveness/readiness
 * probe into a 5xx.
 */
final class IpRateLimiter
{
    private const WINDOW_SECONDS = 60;

    public function __construct(
        private readonly int $maxRequestsPerWindow = 30,
    ) {
    }

    public function allow(string $ip): bool
    {
        if (!function_exists('apcu_fetch') || !function_exists('apcu_store') || $ip === '') {
            return true;
        }

        $key = 'ccp_ready_rl_' . substr(hash('sha256', $ip), 0, 32);
        $count = apcu_fetch($key);
        if ($count === false) {
            apcu_store($key, 1, self::WINDOW_SECONDS);

            return true;
        }

        if ((int)$count >= $this->maxRequestsPerWindow) {
            return false;
        }

        apcu_store($key, (int)$count + 1, self::WINDOW_SECONDS);

        return true;
    }
}
