<?php

/**
 * TrendSparkline: coordinate mapping for the inline-SVG lab trend widget.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\ReadPath\TrendSparkline;
use PHPUnit\Framework\TestCase;

/**
 * Pure, DB-free -- exercises every degenerate input the mapper documents:
 * empty/unplottable series, single point, constant series (zero value range,
 * the divide-by-zero trap), same-date series (zero time range, the other
 * divide-by-zero trap), null-valued draws splitting the polyline into gap
 * segments, and out-of-order input being re-sorted chronologically. Failure
 * mode guarded overall: a malformed series must degrade to `null` (widget
 * simply absent) -- never to a division-by-zero warning, a NaN coordinate,
 * or a line drawn across an unknown value.
 */
final class TrendSparklineTest extends TestCase
{
    public function testNormalSeriesMapsChronologicallyWithInvertedYAndLatestFlag(): void
    {
        // Fed most-recent-first, exactly as DocViewModel::group() orders the
        // table rows -- the mapper must re-sort chronologically itself.
        $spark = TrendSparkline::fromRows([
            self::row('2026-06-01', 7.2),
            self::row('2026-03-01', 6.5),
            self::row('2026-01-01', 6.9),
        ]);

        self::assertNotNull($spark);
        self::assertCount(3, $spark['points']);
        self::assertCount(1, $spark['segments'], 'an unbroken numeric series is one polyline');

        // Chronological left-to-right: x strictly increases.
        $xs = array_map(static fn (array $p): float => (float) $p['x'], $spark['points']);
        self::assertTrue($xs[0] < $xs[1] && $xs[1] < $xs[2], 'x must increase with time');

        // SVG y grows downward: the max value (7.2, last point) sits at the
        // top (smallest y), the min value (6.5, middle point) at the bottom.
        $ys = array_map(static fn (array $p): float => (float) $p['y'], $spark['points']);
        self::assertSame(min($ys), $ys[2], 'the highest value maps to the smallest y');
        self::assertSame(max($ys), $ys[1], 'the lowest value maps to the largest y');

        // Only the newest draw is highlighted.
        self::assertSame([false, false, true], array_column($spark['points'], 'latest'));
        self::assertSame('2026-06-01: 7.2 %', $spark['points'][2]['label']);
    }

    public function testSinglePointCentersDotAndDrawsNoLine(): void
    {
        $spark = TrendSparkline::fromRows([self::row('2026-06-01', 7.2)]);

        self::assertNotNull($spark);
        self::assertSame([], $spark['segments'], 'one point cannot form a line');
        self::assertCount(1, $spark['points']);
        // Zero time range centers x; zero value range centers y -- neither
        // degenerate range may reach a scaling denominator.
        self::assertSame(TrendSparkline::WIDTH / 2, (float) $spark['points'][0]['x']);
        self::assertSame(TrendSparkline::HEIGHT / 2, (float) $spark['points'][0]['y']);
        self::assertTrue($spark['points'][0]['latest']);
    }

    public function testEmptyAndUnplottableSeriesReturnNull(): void
    {
        self::assertNull(TrendSparkline::fromRows([]), 'empty group renders no widget');
        self::assertNull(
            TrendSparkline::fromRows([self::row(null, 7.2), self::row('', 6.9)]),
            'undated draws cannot be placed on a time axis'
        );
        self::assertNull(
            TrendSparkline::fromRows([self::row('2026-06-01', null)]),
            'a series with no numeric value has nothing to plot'
        );
        self::assertNull(
            TrendSparkline::fromRows([self::row('not-a-date', 7.2)]),
            'an unparseable clinical_date is skipped, not fatal'
        );
    }

    public function testNullValuedDrawSplitsThePolylineIntoGapSegments(): void
    {
        // A text-only result mid-series: the line must break there rather
        // than pretend continuity across an unknown value.
        $spark = TrendSparkline::fromRows([
            self::row('2026-01-01', 6.9),
            self::row('2026-02-01', 7.0),
            self::row('2026-03-01', null),
            self::row('2026-04-01', 7.4),
            self::row('2026-05-01', 7.1),
        ]);

        self::assertNotNull($spark);
        self::assertCount(4, $spark['points'], 'the null draw gets no dot');
        self::assertCount(2, $spark['segments'], 'the gap splits the line in two');
        foreach ($spark['segments'] as $segment) {
            self::assertCount(2, explode(' ', $segment), 'each segment spans the two points on its side of the gap');
        }
    }

    public function testTrailingNullAfterSingleNumericPointDrawsNoLine(): void
    {
        // A run of one numeric point on either side of a gap is a dot, not a
        // one-point "polyline".
        $spark = TrendSparkline::fromRows([
            self::row('2026-01-01', 6.9),
            self::row('2026-02-01', null),
            self::row('2026-03-01', 7.4),
        ]);

        self::assertNotNull($spark);
        self::assertCount(2, $spark['points']);
        self::assertSame([], $spark['segments'], 'single-point runs draw no line segments');
    }

    public function testConstantSeriesDrawsFlatMidHeightLine(): void
    {
        $spark = TrendSparkline::fromRows([
            self::row('2026-01-01', 7.0),
            self::row('2026-03-01', 7.0),
            self::row('2026-06-01', 7.0),
        ]);

        self::assertNotNull($spark);
        self::assertCount(1, $spark['segments']);
        foreach ($spark['points'] as $point) {
            self::assertSame(TrendSparkline::HEIGHT / 2, (float) $point['y'], 'zero value range must flatline at mid-height, not divide by zero');
        }
    }

    public function testSameDateSeriesStacksOnHorizontalCenter(): void
    {
        $spark = TrendSparkline::fromRows([
            self::row('2026-06-01', 6.9),
            self::row('2026-06-01', 7.2),
        ]);

        self::assertNotNull($spark);
        foreach ($spark['points'] as $point) {
            self::assertSame(TrendSparkline::WIDTH / 2, (float) $point['x'], 'zero time range must center, not divide by zero');
        }
    }

    public function testComparatorSurvivesIntoTheTooltipLabel(): void
    {
        $spark = TrendSparkline::fromRows([
            self::row('2026-06-01', 7.0, comparator: 'lt'),
        ]);

        self::assertNotNull($spark);
        self::assertSame('2026-06-01: <7 %', $spark['points'][0]['label'], 'a censored value must keep its comparator in the hover text');
    }

    public function testCoordinatesAreEmittedAsFixedPointStrings(): void
    {
        $spark = TrendSparkline::fromRows([
            self::row('2026-01-01', 6.9),
            self::row('2026-06-01', 7.2),
        ]);

        self::assertNotNull($spark);
        foreach ($spark['points'] as $point) {
            self::assertMatchesRegularExpression('/^\d+\.\d$/', $point['x'], 'coordinates are locale-independent one-decimal strings');
            self::assertMatchesRegularExpression('/^\d+\.\d$/', $point['y']);
        }
        self::assertMatchesRegularExpression('/^\d+\.\d,\d+\.\d \d+\.\d,\d+\.\d$/', $spark['segments'][0]);
    }

    /**
     * The subset of a consolidated fact row ({@see DocViewModel} factRow +
     * consolidateLabRows output) the mapper reads.
     *
     * @return array<string, mixed>
     */
    private static function row(?string $date, int|float|null $parsed, string $unit = '%', string $comparator = 'none'): array
    {
        return [
            'clinical_date' => $date,
            'parsed' => $parsed,
            'unit' => $unit,
            'comparator' => $comparator,
        ];
    }
}
