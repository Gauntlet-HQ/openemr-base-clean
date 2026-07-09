<?php

/**
 * Isolated evals: RateMath's pure percentage/percentile arithmetic.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Observability;

use OpenEMR\Modules\ClinicalCopilot\Observability\Metrics\RateMath;
use PHPUnit\Framework\TestCase;

/**
 * Pulled out of {@see \OpenEMR\Modules\ClinicalCopilot\Observability\Alert\AlertEvaluator}
 * and {@see \OpenEMR\Modules\ClinicalCopilot\Observability\Metrics\MetricsService}
 * specifically so the zero-denominator/empty-input edge cases (the ones a
 * live-DB test would rarely hit deliberately) have direct, fast coverage.
 */
final class RateMathTest extends TestCase
{
    public function testPercentageOfZeroDenominatorIsZeroNotAnError(): void
    {
        self::assertSame(0.0, RateMath::percentage(5, 0));
    }

    public function testPercentageComputesCorrectly(): void
    {
        self::assertEqualsWithDelta(25.0, RateMath::percentage(1, 4), 0.0001);
    }

    public function testPercentileOfEmptyListIsZero(): void
    {
        self::assertSame(0.0, RateMath::percentile([], 95.0));
    }

    /**
     * Nearest-rank p95 over 100, 200, ..., 1000 (10 values): rank =
     * ceil(0.95 * 10) = 10 -> the 10th sorted value, 1000.
     */
    public function testP95NearestRankOverTenValues(): void
    {
        $values = [100, 200, 300, 400, 500, 600, 700, 800, 900, 1000];

        self::assertSame(1000.0, RateMath::percentile($values, 95.0));
    }

    /**
     * p50 over the same 10 values: rank = ceil(0.50 * 10) = 5 -> the 5th
     * sorted value, 500.
     */
    public function testP50NearestRankOverTenValues(): void
    {
        $values = [1000, 900, 800, 700, 600, 500, 400, 300, 200, 100];

        self::assertSame(500.0, RateMath::percentile($values, 50.0));
    }

    public function testPercentileOfSingleValueIsThatValueRegardlessOfPercentile(): void
    {
        self::assertSame(42.0, RateMath::percentile([42], 95.0));
        self::assertSame(42.0, RateMath::percentile([42], 50.0));
    }

    public function testAverageOfEmptyListIsZero(): void
    {
        self::assertSame(0.0, RateMath::average([]));
    }

    public function testAverageComputesCorrectly(): void
    {
        self::assertEqualsWithDelta(2.0, RateMath::average([1, 2, 3]), 0.0001);
    }
}
