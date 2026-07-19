<?php

/**
 * Pure arithmetic shared by AlertEvaluator and MetricsService: percentages and percentiles.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Metrics;

/**
 * No I/O, no clock reads -- pulled out of {@see \OpenEMR\Modules\ClinicalCopilot\Observability\Alert\AlertEvaluator}
 * and {@see MetricsService} specifically so the arithmetic (percentile
 * interpolation, zero-denominator handling) has isolated, DB-free test
 * coverage instead of only being exercised indirectly through a live-DB test.
 */
final class RateMath
{
    private function __construct()
    {
        // static-only
    }

    /**
     * A zero denominator is defined as 0.0 -- "no attempts" is not the same
     * failure mode as "every attempt failed," and callers (alert thresholds)
     * must never fire on an empty window.
     */
    public static function percentage(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100.0, 4);
    }

    /**
     * Nearest-rank percentile over a list of millisecond durations. Empty
     * input is defined as 0.0 (nothing observed yet, not a stall).
     *
     * @param list<int> $valuesMs
     */
    public static function percentile(array $valuesMs, float $p): float
    {
        if ($valuesMs === []) {
            return 0.0;
        }

        $sorted = $valuesMs;
        sort($sorted);
        $count = count($sorted);
        $rank = (int)ceil(($p / 100.0) * $count);
        $index = max(0, min($count - 1, $rank - 1));

        return (float)$sorted[$index];
    }

    /**
     * @param list<float> $values
     */
    public static function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }
}
