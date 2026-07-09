<?php

/**
 * Estimates the USD cost of an AI transaction from its token usage.
 *
 * Prices are expressed in USD per one million tokens and are keyed by model
 * name, with separate input (prompt) and output (completion) rates. The
 * table reflects published OpenAI list prices at the time of writing and is
 * intended as an estimate for cost-tracking, not billing reconciliation.
 *
 * Unknown models return null so the ledger can record "unknown" rather than
 * a misleading zero. Custom rates can be supplied through the constructor.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI\Observability;

final readonly class TokenPricing
{
    /**
     * Published list prices in USD per 1,000,000 tokens: [input, output].
     *
     * @var array<string, array{float, float}>
     */
    private const DEFAULT_RATES = [
        'gpt-4o-mini' => [0.15, 0.60],
        'gpt-4o' => [2.50, 10.00],
        'gpt-4-turbo' => [10.00, 30.00],
        'gpt-4' => [30.00, 60.00],
        'gpt-3.5-turbo' => [0.50, 1.50],
        'o1-mini' => [1.10, 4.40],
        'o1' => [15.00, 60.00],
    ];

    private const TOKENS_PER_UNIT = 1_000_000.0;

    /** @var array<string, array{float, float}> */
    private array $rates;

    /**
     * @param array<string, array{float, float}> $rates Optional overrides,
     *        merged over the built-in table (keyed by lowercase model name).
     */
    public function __construct(array $rates = [])
    {
        $normalized = [];
        foreach ($rates as $model => $rate) {
            $normalized[strtolower($model)] = $rate;
        }

        $this->rates = array_merge(self::DEFAULT_RATES, $normalized);
    }

    /**
     * Estimate the cost in USD for the given model and token usage.
     *
     * Returns null when the model has no known price so callers can
     * distinguish "free" from "unpriced".
     */
    public function estimateCostUsd(string $model, TokenUsage $usage): ?float
    {
        $rate = $this->rates[strtolower(trim($model))] ?? null;
        if ($rate === null) {
            return null;
        }

        [$inputRate, $outputRate] = $rate;
        $inputCost = ($usage->promptTokens / self::TOKENS_PER_UNIT) * $inputRate;
        $outputCost = ($usage->completionTokens / self::TOKENS_PER_UNIT) * $outputRate;

        return round($inputCost + $outputCost, 6);
    }

    public function hasRateFor(string $model): bool
    {
        return isset($this->rates[strtolower(trim($model))]);
    }
}
