<?php

/**
 * PromptFactWindow: 2-year recency window + per-series cap on dense facts; sparse facts kept whole.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\PromptFactWindow;
use PHPUnit\Framework\TestCase;

final class PromptFactWindowTest extends TestCase
{
    public function testChatKeepsEverythingWithinTwoYearsEvenBeyondTheMinimum(): void
    {
        // 40 A1c (%) + 40 glucose (mg/dL) within ~17 months -- a frequent
        // patient. All kept: the 2-year window WINS over the 15 minimum; they
        // are a union, they do not both cap. Two series (by unit) are separate.
        $facts = [];
        $base = new \DateTimeImmutable('2026-06-01');
        for ($i = 0; $i < 40; $i++) {
            $date = $base->modify('-' . ($i * 13) . ' days')->format('Y-m-d');
            $facts[] = self::trend('a1c-' . $i, '%', $date, 7.0, $i + 1);
            $facts[] = self::trend('glu-' . $i, 'mg/dL', $date, 120.0, 1000 + $i);
        }

        $byUnit = [];
        foreach (PromptFactWindow::forChat($facts) as $f) {
            if ($f->kind->value === 'trend_point') {
                $unit = $f->value?->unitCanonical ?? '';
                $byUnit[$unit] = ($byUnit[$unit] ?? 0) + 1;
            }
        }

        self::assertSame(40, $byUnit['%'], 'all A1c within 2 years kept, not capped to 15');
        self::assertSame(40, $byUnit['mg/dL'], 'all glucose within 2 years kept, independent of A1c');
    }

    public function testChatKeepsTheLastFifteenForASparsePatientAndDropsBeyondBoth(): void
    {
        // 20 semi-annual A1c over ~10 years: only ~5 fall in the last 2 years,
        // but the 15 minimum surfaces recent history; the oldest (beyond BOTH
        // the window and the last 15) are dropped. A 2016 med is sparse => kept.
        $facts = [];
        $base = new \DateTimeImmutable('2026-01-01');
        for ($i = 0; $i < 20; $i++) {
            $date = $base->modify('-' . ($i * 6) . ' months')->format('Y-m-d');
            $facts[] = self::trend('a1c-' . $i, '%', $date, 7.0, $i + 1);
        }
        $facts[] = self::nonValue('med-active', 'med_response', 'med_event', '2016-01-01', 99);

        $ids = array_map(static fn (Fact $f): string => $f->factId, PromptFactWindow::forChat($facts));
        $trendKept = count(array_filter($ids, static fn (string $id): bool => str_starts_with($id, 'a1c-')));

        self::assertSame(15, $trendKept, 'the last 15 of a sparse series are kept (the 2yr window is smaller here)');
        self::assertContains('a1c-0', $ids, 'the most recent draw is kept');
        self::assertNotContains('a1c-19', $ids, 'the oldest draw, beyond both the window and the last 15, is dropped');
        self::assertContains('med-active', $ids, 'a still-listed medication is kept regardless of age');
    }

    public function testUnderTheCapEverythingRecentSurvives(): void
    {
        $facts = [
            self::trend('a', '%', '2026-01-01', 7.1, 1),
            self::trend('b', '%', '2026-04-01', 7.3, 2),
            self::nonValue('m', 'med_response', 'med_event', '2026-01-01', 9),
        ];

        self::assertCount(3, PromptFactWindow::forChat($facts));
    }

    public function testNarrativeKeepsTheLastTwentyVisitsAndDropsOlder(): void
    {
        // 30 monthly A1c draws (30 distinct visit dates) + a 2020 medication.
        $facts = [];
        $base = new \DateTimeImmutable('2026-06-01');
        for ($i = 0; $i < 30; $i++) {
            $date = $base->modify('-' . $i . ' months')->format('Y-m-d');
            $facts[] = self::trend('a1c-' . $i, '%', $date, 7.0, $i + 1);
        }
        $facts[] = self::nonValue('med', 'med_response', 'med_event', '2020-01-01', 999);

        $windowed = PromptFactWindow::forNarrative($facts, 20);
        $trendCount = count(array_filter($windowed, static fn (Fact $f): bool => $f->kind->value === 'trend_point'));
        $ids = array_map(static fn (Fact $f): string => $f->factId, $windowed);

        self::assertSame(20, $trendCount, 'the narrative keeps the last 20 visits of trend history');
        self::assertContains('a1c-0', $ids, 'the most recent visit is kept');
        self::assertNotContains('a1c-29', $ids, 'the 30th-most-recent visit is dropped');
        self::assertContains('med', $ids, 'a 2020 medication is still kept for the narrative');
    }

    private static function trend(string $id, string $unit, string $date, float $parsed, int $pk): Fact
    {
        return Fact::fromArray([
            'fact_id' => $id,
            'capability' => 'control_proxy',
            'capability_version' => '1',
            'kind' => 'trend_point',
            'pid' => 42,
            'clinical_date' => $date,
            'date_source' => 'collected',
            'value' => ['raw' => (string) $parsed, 'parsed' => $parsed, 'comparator' => 'none', 'unit_original' => $unit, 'unit_canonical' => $unit, 'conversion_version' => null],
            'status' => 'final',
            'flags' => [],
            'citations' => [['table' => 'procedure_result', 'pk' => $pk, 'field' => 'result', 'date_source' => 'collected']],
        ]);
    }

    private static function nonValue(string $id, string $capability, string $kind, string $date, int $pk): Fact
    {
        return Fact::fromArray([
            'fact_id' => $id,
            'capability' => $capability,
            'capability_version' => '1',
            'kind' => $kind,
            'pid' => 42,
            'clinical_date' => $date,
            'date_source' => 'collected',
            'value' => null,
            'status' => 'final',
            'flags' => [],
            'citations' => [['table' => 'procedure_result', 'pk' => $pk, 'field' => null, 'date_source' => 'collected']],
        ]);
    }
}
