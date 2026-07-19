<?php

/**
 * Maps a consolidated lab group's draw rows to inline-SVG sparkline coordinates.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

/**
 * Pure and DB-free (isolated-testable,
 * tests/Isolated/ReadPath/TrendSparklineTest.php) coordinate mapper for the
 * per-analyte trend sparkline rendered above each consolidated lab table in
 * doc.html.twig. No chart library: this class does ALL of the plot math
 * (time-proportional x, inverted min/max-scaled y, viewBox padding) and the
 * template's `trend_sparkline` macro only emits `<svg>` markup from the
 * returned strings -- keeping the arithmetic out of Twig, where it would be
 * untestable template conditionals.
 *
 * Degenerate inputs (each one covered by the isolated test):
 * - No plottable row (empty group, or every row lacks a numeric `parsed`
 *   and/or a parseable `clinical_date`): returns null -- the template skips
 *   the widget entirely instead of rendering an empty `<svg>`.
 * - Single point: one dot centered in the viewBox, no line segment.
 * - Constant series (max value == min value): flat mid-height line -- the
 *   zero value-range never reaches the scaling denominator.
 * - Same-date series (every draw shares one clinical_date): points stack on
 *   the horizontal center -- the zero time-range never reaches the scaling
 *   denominator either.
 * - Null-valued draw between numeric draws (a text-only result mid-series):
 *   breaks the polyline into separate segments, leaving a visible gap rather
 *   than drawing a false connecting line across the unknown value.
 * - Out-of-order input: rows are re-sorted chronologically here. The caller's
 *   order is deliberately NOT trusted -- {@see DocViewModel::group()} sorts
 *   most-recent-first for the table, which is exactly the reverse of plot
 *   order, and undated rows (unplottable on a time axis) are dropped.
 * - Comparator-qualified values ("<7.0"): plotted at their parsed magnitude
 *   (the only number available); the comparator symbol is preserved in the
 *   point's `<title>` label so the hover stays honest about the censoring.
 *
 * Reference-range bands are intentionally absent: the fact rows carry
 * out-of-range information only as flags ({@see \OpenEMR\Modules\ClinicalCopilot\Fact\Flag}),
 * never the numeric low/high bounds, so there is no range to draw.
 */
final class TrendSparkline
{
    /** viewBox width -- compact enough to sit above a card's table. */
    public const WIDTH = 240.0;

    /** viewBox height. */
    public const HEIGHT = 48.0;

    /** Horizontal inset so edge points and their dots are not clipped. */
    private const PAD_X = 8.0;

    /** Vertical inset so min/max points and their dots are not clipped. */
    private const PAD_Y = 8.0;

    private function __construct()
    {
        // static-only
    }

    /**
     * @param list<array<string, mixed>> $rows one consolidated lab group's
     *        draw rows ({@see DocViewModel::consolidateLabRows()} output:
     *        `clinical_date` ISO `Y-m-d` or null, `parsed` float|null,
     *        `unit` string|null, `comparator` string)
     * @return array{
     *     width: float,
     *     height: float,
     *     segments: list<string>,
     *     points: list<array{x: string, y: string, latest: bool, label: string}>
     * }|null null when nothing is plottable -- the template renders no widget
     */
    public static function fromRows(array $rows): ?array
    {
        $dated = [];
        foreach ($rows as $row) {
            $date = $row['clinical_date'] ?? null;
            if (!is_string($date) || $date === '') {
                continue;
            }
            $timestamp = strtotime($date);
            if ($timestamp === false) {
                continue;
            }
            $parsed = $row['parsed'] ?? null;
            $dated[] = [
                'timestamp' => $timestamp,
                'date' => $date,
                'value' => is_int($parsed) || is_float($parsed) ? (float) $parsed : null,
                'unit' => is_string($row['unit'] ?? null) ? $row['unit'] : '',
                'comparator' => is_string($row['comparator'] ?? null) ? $row['comparator'] : 'none',
            ];
        }

        usort($dated, static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        $numeric = array_values(array_filter($dated, static fn (array $entry): bool => $entry['value'] !== null));
        if ($numeric === []) {
            return null;
        }

        $timestamps = array_column($numeric, 'timestamp');
        $values = array_column($numeric, 'value');
        $timeMin = min($timestamps);
        $timeRange = max($timestamps) - $timeMin;
        $valueMin = min($values);
        $valueRange = max($values) - $valueMin;
        $latestIndex = count($numeric) - 1;

        $points = [];
        $segments = [];
        /** @var list<string> $currentSegment */
        $currentSegment = [];
        $numericIndex = 0;
        foreach ($dated as $entry) {
            if ($entry['value'] === null) {
                // A dated draw with no numeric value is a gap: end the line
                // here so the next numeric point starts a fresh segment.
                self::flushSegment($segments, $currentSegment);
                continue;
            }

            $x = self::scaleX($entry['timestamp'], $timeMin, $timeRange);
            $y = self::scaleY($entry['value'], $valueMin, $valueRange);
            $points[] = [
                'x' => self::fmt($x),
                'y' => self::fmt($y),
                'latest' => $numericIndex === $latestIndex,
                'label' => self::label($entry['date'], $entry['comparator'], $entry['value'], $entry['unit']),
            ];
            $currentSegment[] = self::fmt($x) . ',' . self::fmt($y);
            $numericIndex++;
        }
        self::flushSegment($segments, $currentSegment);

        return [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'segments' => $segments,
            'points' => $points,
        ];
    }

    /**
     * Time-proportional x within the padded plot area; a zero time range
     * (single point, or all draws on one date) centers horizontally.
     */
    private static function scaleX(int $timestamp, int $timeMin, int $timeRange): float
    {
        if ($timeRange === 0) {
            return self::WIDTH / 2.0;
        }

        return self::PAD_X + (($timestamp - $timeMin) / $timeRange) * (self::WIDTH - 2.0 * self::PAD_X);
    }

    /**
     * Min/max-scaled y, inverted because SVG y grows downward; a zero value
     * range (constant series) draws a flat mid-height line.
     */
    private static function scaleY(float $value, float $valueMin, float $valueRange): float
    {
        if ($valueRange <= 0.0) {
            return self::HEIGHT / 2.0;
        }

        return self::HEIGHT - self::PAD_Y - (($value - $valueMin) / $valueRange) * (self::HEIGHT - 2.0 * self::PAD_Y);
    }

    /**
     * @param list<string> $segments
     * @param list<string> $currentSegment emptied by reference; runs of fewer
     *        than two points draw no line (the dot alone marks the value)
     */
    private static function flushSegment(array &$segments, array &$currentSegment): void
    {
        if (count($currentSegment) >= 2) {
            $segments[] = implode(' ', $currentSegment);
        }
        $currentSegment = [];
    }

    /**
     * The native-tooltip `<title>` text: "2026-06-01: 7.2 %", mirroring the
     * table's value cell (comparator symbol, then value, then unit).
     */
    private static function label(string $date, string $comparator, float $value, string $unit): string
    {
        $symbol = match ($comparator) {
            'lt' => '<',
            'lte' => '<=',
            'gt' => '>',
            'gte' => '>=',
            default => '',
        };

        return $date . ': ' . $symbol . self::fmtValue($value) . ($unit !== '' ? ' ' . $unit : '');
    }

    /** Locale-independent coordinate string, 0.1 resolution in viewBox units. */
    private static function fmt(float $n): string
    {
        return number_format($n, 1, '.', '');
    }

    /** "7.20" -> "7.2", "7.00" -> "7" -- stable display without float-cast noise. */
    private static function fmtValue(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
