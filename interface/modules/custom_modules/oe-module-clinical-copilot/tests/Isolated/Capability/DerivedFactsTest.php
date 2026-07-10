<?php

/**
 * Isolated evals for deterministic derived-fact math (deltas/span/count).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Capability;

use OpenEMR\Modules\ClinicalCopilot\Capability\Support\DerivedFacts;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use PHPUnit\Framework\TestCase;

/**
 * U5 acceptance criterion: "derived facts recomputed in tests equal the
 * cited values and cite their raw facts." Every eval below recomputes the
 * derived value directly from the two (or N) raw Facts' own `value.parsed`
 * and asserts equality with the derived Fact, then asserts every citation
 * on the raw facts appears among the derived fact's citations.
 */
final class DerivedFactsTest extends TestCase
{
    /**
     * Eval: consecutive-pair deltas over a 4-point rising series (modeled on
     * CCP-001's rising A1c trend: 7.2 -> 7.6 -> 8.0 -> 8.4) produce exactly
     * 3 derived_delta facts, each equal to (later - earlier) and citing both
     * endpoints' own citations.
     */
    public function testDeltasOverRisingSeries(): void
    {
        $series = [
            CapabilityFactTestFactory::trendPoint(1, 1, 7.2, '2025-07-07'),
            CapabilityFactTestFactory::trendPoint(1, 2, 7.6, '2025-10-07'),
            CapabilityFactTestFactory::trendPoint(1, 3, 8.0, '2026-01-07'),
            CapabilityFactTestFactory::trendPoint(1, 4, 8.4, '2026-04-07'),
        ];

        $deltas = DerivedFacts::deltas(Capability::ControlProxy, '1', $series);

        self::assertCount(3, $deltas);
        foreach ($deltas as $i => $delta) {
            self::assertSame(FactKind::DerivedDelta, $delta->kind);
            $expected = round($series[$i + 1]->value->parsed - $series[$i]->value->parsed, 6);
            self::assertEqualsWithDelta($expected, $delta->value?->parsed, 0.0001);

            $citedPks = array_map(static fn ($c) => [$c->table, $c->pk], $delta->citations);
            self::assertContains(['procedure_result', $series[$i]->citations[0]->pk], $citedPks);
            self::assertContains(['procedure_result', $series[$i + 1]->citations[0]->pk], $citedPks);
        }
    }

    /**
     * Regression: number_format($delta, 2) with PHP's default ',' thousands
     * separator corrupted FactValue.raw for deltas >= 1000 (e.g. a glucose
     * swing during a DKA/HHS crisis, realistic for this population) into
     * "+1,150.00" -- a malformed number the LLM could quote verbatim. The
     * underlying parsed float was always correct; only the display string
     * was wrong.
     */
    public function testDeltaRawStringHasNoThousandsSeparatorForLargeMagnitudeValues(): void
    {
        $series = [
            CapabilityFactTestFactory::trendPoint(1, 1, 90.0, '2026-01-01', 'mg/dL'),
            CapabilityFactTestFactory::trendPoint(1, 2, 1240.0, '2026-01-02', 'mg/dL'),
        ];

        $deltas = DerivedFacts::deltas(Capability::ControlProxy, '1', $series);

        self::assertCount(1, $deltas);
        self::assertSame('+1150.00', $deltas[0]->value?->raw);
    }

    /**
     * Eval: derived_span is the first-to-last magnitude, not a sum of the
     * consecutive deltas (though for a monotonic series they happen to
     * agree) -- it must cite ONLY the two endpoints, not every intermediate
     * point.
     */
    public function testSpanIsFirstToLastAndCitesOnlyEndpoints(): void
    {
        $series = [
            CapabilityFactTestFactory::trendPoint(1, 1, 7.2, '2025-07-07'),
            CapabilityFactTestFactory::trendPoint(1, 2, 7.6, '2025-10-07'),
            CapabilityFactTestFactory::trendPoint(1, 3, 8.4, '2026-01-07'),
        ];

        $span = DerivedFacts::span(Capability::ControlProxy, '1', $series);

        self::assertNotNull($span);
        self::assertSame(FactKind::DerivedSpan, $span->kind);
        self::assertEqualsWithDelta(8.4 - 7.2, $span->value?->parsed, 0.0001);
        self::assertCount(2, $span->citations, 'span cites only the first and last point, not the middle one');
    }

    /**
     * Eval: span/deltas are null for a series with fewer than two points --
     * there is nothing to compute a change over.
     */
    public function testSpanAndDeltasNullForSinglePointSeries(): void
    {
        $series = [CapabilityFactTestFactory::trendPoint(1, 1, 7.2, '2025-07-07')];

        self::assertNull(DerivedFacts::span(Capability::ControlProxy, '1', $series));
        self::assertSame([], DerivedFacts::deltas(Capability::ControlProxy, '1', $series));
    }

    /**
     * Eval: derived_count equals the series length and cites every raw fact
     * in the series (the "N draws on file" claim).
     */
    public function testCountCitesEveryRawFact(): void
    {
        $series = [
            CapabilityFactTestFactory::trendPoint(1, 1, 7.2, '2025-07-07'),
            CapabilityFactTestFactory::trendPoint(1, 2, 7.6, '2025-10-07'),
            CapabilityFactTestFactory::trendPoint(1, 3, 8.0, '2026-01-07'),
        ];

        $count = DerivedFacts::count(Capability::ControlProxy, '1', $series);

        self::assertNotNull($count);
        self::assertSame(FactKind::DerivedCount, $count->kind);
        self::assertSame(3.0, $count->value?->parsed);
        self::assertCount(3, $count->citations);

        $citedPks = array_map(static fn ($c) => $c->pk, $count->citations);
        foreach ($series as $fact) {
            self::assertContains($fact->citations[0]->pk, $citedPks);
        }
    }

    public function testCountNullForEmptySeries(): void
    {
        self::assertNull(DerivedFacts::count(Capability::ControlProxy, '1', []));
    }

    /**
     * Eval: derived facts recompute deterministically -- calling deltas()
     * twice over the same series produces identical fact_ids (E6-style
     * determinism, at the capability layer rather than the digest layer).
     */
    public function testDeltaFactIdIsDeterministic(): void
    {
        $series = [
            CapabilityFactTestFactory::trendPoint(1, 1, 7.2, '2025-07-07'),
            CapabilityFactTestFactory::trendPoint(1, 2, 7.6, '2025-10-07'),
        ];

        $first = DerivedFacts::deltas(Capability::ControlProxy, '1', $series);
        $second = DerivedFacts::deltas(Capability::ControlProxy, '1', $series);

        self::assertSame($first[0]->factId, $second[0]->factId);
    }
}
