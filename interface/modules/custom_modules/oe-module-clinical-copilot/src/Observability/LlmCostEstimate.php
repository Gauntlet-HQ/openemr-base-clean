<?php

/**
 * Deliberately-approximate USD cost estimate for one Gemini call (observability only).
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
 * A rough dollar figure for one generateContent call, written onto the
 * `cost_usd` trace/doc columns so `MetricsService`'s `SUM(cost_usd)` and the
 * dashboard give an at-a-glance "are we burning far more than usual" signal.
 *
 * This is an ANOMALY-DETECTION aid, NOT an invoice. The rates below are
 * hardcoded ballparks (published list prices, per 1M tokens) and precision is
 * explicitly a non-goal -- whether a run was $0.10 or $0.25 does not matter;
 * whether a correlation id suddenly costs 10x does. Update the constants if
 * the pricing contract changes; do not reconcile a bill against them.
 *
 * Gemini 2.5 bills "thinking" at the OUTPUT rate and
 * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiGenerateContentContract::extractOutputTokenCount()}
 * already folds `thoughtsTokenCount` into `tokensOut`, so the single output
 * multiplier here covers thinking too.
 */
final class LlmCostEstimate
{
    /**
     * Ballpark list prices in USD per 1,000,000 tokens, keyed by model-id
     * prefix so dated/suffixed variants (e.g. `gemini-2.5-pro-002`) still match.
     *
     * @var array<string, array{in: float, out: float}>
     */
    private const RATES_USD_PER_MILLION = [
        'gemini-2.5-pro' => ['in' => 1.25, 'out' => 10.00],
        'gemini-2.5-flash' => ['in' => 0.30, 'out' => 2.50],
    ];

    /**
     * Used when the model id matches no known prefix (a new/renamed model).
     * Deliberately the priciest known tier so an unknown model reads as "at
     * least this much" rather than silently cheap -- the estimate must never
     * under-report a runaway just because a model string drifted.
     *
     * @var array{in: float, out: float}
     */
    private const FALLBACK_RATE = ['in' => 1.25, 'out' => 10.00];

    private function __construct()
    {
        // static-only
    }

    /**
     * Returns null when nothing was metered (e.g. the LLM was never reached,
     * so `model`/tokens are null) -- an honest NULL, not a fabricated $0.00.
     */
    public static function estimateUsd(?string $model, ?int $tokensIn, ?int $tokensOut): ?float
    {
        if ($model === null || $tokensIn === null || $tokensOut === null) {
            return null;
        }

        $rate = self::rateFor($model);
        $usd = ($tokensIn / 1_000_000) * $rate['in']
            + ($tokensOut / 1_000_000) * $rate['out'];

        return round($usd, 6);
    }

    /**
     * @return array{in: float, out: float}
     */
    private static function rateFor(string $model): array
    {
        foreach (self::RATES_USD_PER_MILLION as $prefix => $rate) {
            if (str_starts_with($model, $prefix)) {
                return $rate;
            }
        }

        return self::FALLBACK_RATE;
    }
}
