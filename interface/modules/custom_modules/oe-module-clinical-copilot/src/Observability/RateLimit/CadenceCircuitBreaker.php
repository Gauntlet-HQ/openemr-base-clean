<?php

/**
 * mod_copilot_cadence + mod_copilot_trace-backed CircuitBreakerInterface implementation (ARCHITECTURE.md §3.7).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Chat\RateLimit\CircuitBreakerInterface;

/**
 * "Breaker state lives in the module config table and is checked before
 * every LLM call. Open breaker => chat degrades to the facts browser with a
 * banner" (ARCHITECTURE.md §3.7). Three independent trip conditions, any one
 * of which opens the breaker:
 *
 *  1. Manual override ({@see CadenceConfigStore::forceOpen()}) -- the only
 *     piece of PERSISTED state this class reads; everything else below is
 *     computed fresh from `mod_copilot_trace` on every {@see self::isOpen()}
 *     call, which is exactly what gives "reset is automatic at window
 *     rollover" (§3.7) for free: once the offending spend/errors age out of
 *     their window, the aggregate drops back under threshold with no state
 *     transition to perform.
 *  2. Error-rate trip: >= `breaker_error_threshold` error-status trace spans
 *     within the trailing `breaker_window_seconds`.
 *  3. Spend trip: hourly burn > `hourly_llm_burn_cap_usd`, OR today's total
 *     spend > `daily_llm_spend_cap_usd` (ARCHITECTURE.md §3.5's "LLM spend"
 *     alert and this breaker trip are the same underlying signal; the alert
 *     is the human-facing notification, this is the automatic enforcement).
 */
final class CadenceCircuitBreaker implements CircuitBreakerInterface
{
    public function __construct(
        private readonly CadenceConfigStore $configStore = new CadenceConfigStore(),
    ) {
    }

    public function isOpen(): bool
    {
        return $this->snapshot()['open'];
    }

    /**
     * @return array{
     *     open: bool, reason: ?string, forced_open: bool,
     *     error_count_in_window: int, hourly_spend_usd: float, daily_spend_usd: float,
     *     hourly_burn_cap_usd: float, daily_spend_cap_usd: float
     * }
     */
    public function snapshot(): array
    {
        $limits = $this->configStore->limits();
        $state = $this->configStore->state();

        if ($state['forced_open']) {
            return [
                'open' => true,
                'reason' => 'manually forced open: ' . ($state['forced_reason'] ?? ''),
                'forced_open' => true,
                'error_count_in_window' => 0,
                'hourly_spend_usd' => 0.0,
                'daily_spend_usd' => 0.0,
                'hourly_burn_cap_usd' => $limits['hourly_llm_burn_cap_usd'],
                'daily_spend_cap_usd' => $limits['daily_llm_spend_cap_usd'],
            ];
        }

        $windowStart = (new \DateTimeImmutable("-{$limits['breaker_window_seconds']} seconds"))->format('Y-m-d H:i:s.u');
        $errorCount = (int)QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `status` = ? AND `started_at` > ?',
            'c',
            ['error', $windowStart],
        );

        $hourAgo = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s.u');
        $hourlySpend = (float)(QueryUtils::fetchSingleValue(
            'SELECT COALESCE(SUM(`cost_usd`), 0) AS s FROM `mod_copilot_trace` WHERE `started_at` > ?',
            's',
            [$hourAgo],
        ) ?? 0.0);

        $todayStart = (new \DateTimeImmutable('today midnight'))->format('Y-m-d H:i:s.u');
        $dailySpend = (float)(QueryUtils::fetchSingleValue(
            'SELECT COALESCE(SUM(`cost_usd`), 0) AS s FROM `mod_copilot_trace` WHERE `started_at` > ?',
            's',
            [$todayStart],
        ) ?? 0.0);

        $reason = null;
        if ($errorCount >= $limits['breaker_error_threshold']) {
            $reason = "error rate: {$errorCount} errors in trailing {$limits['breaker_window_seconds']}s (threshold {$limits['breaker_error_threshold']})";
        } elseif ($hourlySpend > $limits['hourly_llm_burn_cap_usd']) {
            $reason = sprintf('hourly LLM burn $%.2f exceeds cap $%.2f', $hourlySpend, $limits['hourly_llm_burn_cap_usd']);
        } elseif ($dailySpend > $limits['daily_llm_spend_cap_usd']) {
            $reason = sprintf('daily LLM spend $%.2f exceeds cap $%.2f', $dailySpend, $limits['daily_llm_spend_cap_usd']);
        }

        return [
            'open' => $reason !== null,
            'reason' => $reason,
            'forced_open' => false,
            'error_count_in_window' => $errorCount,
            'hourly_spend_usd' => $hourlySpend,
            'daily_spend_usd' => $dailySpend,
            'hourly_burn_cap_usd' => $limits['hourly_llm_burn_cap_usd'],
            'daily_spend_cap_usd' => $limits['daily_llm_spend_cap_usd'],
        ];
    }
}
