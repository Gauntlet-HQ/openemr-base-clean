<?php

/**
 * Deterministic derived-fact math shared by capabilities with a numeric trend series.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability\Support;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;

/**
 * `derived_delta`/`derived_span`/`derived_count` Facts (ARCHITECTURE_COMPLETE.md
 * "Fact object": "computed DETERMINISTICALLY by capabilities ... and cite the
 * raw facts they derive from"). Because the Fact schema's `citations` only
 * ever point at physical DB rows ({@see \OpenEMR\Modules\ClinicalCopilot\Fact\Citation}),
 * "cite the raw facts" is implemented here as "cite the union of the raw
 * facts' own citations" -- every derived value traces back to the same
 * physical evidence the raw facts themselves cite, which is exactly what
 * lets a test recompute the derived value from the cited rows and assert
 * equality (U5 acceptance criteria).
 *
 * Reused by {@see \OpenEMR\Modules\ClinicalCopilot\Capability\ControlProxy}
 * (A1c/glucose/lipid trend series) and
 * {@see \OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend} (weight/BMI
 * series) -- both hand in a `list<Fact>` ordered ascending by `clinicalDate`,
 * each carrying a non-null `value.parsed`.
 */
final class DerivedFacts
{
    private function __construct()
    {
        // static-only
    }

    /**
     * One `derived_delta` Fact per consecutive pair in the series.
     *
     * @param list<Fact> $series ascending by clinicalDate; each must carry a non-null value.parsed
     * @return list<Fact>
     */
    public static function deltas(Capability $capability, string $capabilityVersion, array $series): array
    {
        $deltas = [];
        for ($i = 1; $i < count($series); $i++) {
            $deltas[] = self::buildDelta($capability, $capabilityVersion, $series[$i - 1], $series[$i], FactKind::DerivedDelta);
        }

        return $deltas;
    }

    /**
     * A single `derived_span` Fact from the first to the last point in the
     * series -- the whole-series magnitude of change, as distinct from the
     * consecutive-pair deltas above. Null when the series has fewer than two
     * points (no span to compute).
     *
     * @param list<Fact> $series ascending by clinicalDate; each must carry a non-null value.parsed
     */
    public static function span(Capability $capability, string $capabilityVersion, array $series): ?Fact
    {
        if (count($series) < 2) {
            return null;
        }

        return self::buildDelta($capability, $capabilityVersion, $series[0], $series[count($series) - 1], FactKind::DerivedSpan);
    }

    /**
     * A `derived_count` Fact citing every raw fact in the series -- "N draws
     * on file", the claim a physician-facing "3 A1cs, all rising" sentence
     * grounds its count in. Null for an empty series (nothing to count).
     *
     * @param list<Fact> $series
     */
    public static function count(Capability $capability, string $capabilityVersion, array $series): ?Fact
    {
        if ($series === []) {
            return null;
        }

        $citations = [];
        foreach ($series as $fact) {
            $citations = [...$citations, ...$fact->citations];
        }

        $countValue = (float)count($series);
        $value = new FactValue((string)count($series), $countValue, Comparator::None, '', null, null);
        $last = $series[count($series) - 1];
        $factId = FactId::compute($capability, FactKind::DerivedCount, $citations, $value);

        return new Fact(
            $factId,
            $capability,
            $capabilityVersion,
            FactKind::DerivedCount,
            $last->pid,
            $last->clinicalDate,
            $last->dateSource,
            $value,
            FactStatus::Final,
            [],
            $citations,
        );
    }

    private static function buildDelta(Capability $capability, string $capabilityVersion, Fact $earlier, Fact $later, FactKind $kind): Fact
    {
        $earlierValue = $earlier->value ?? throw new \LogicException('DerivedFacts series entries must carry a value');
        $laterValue = $later->value ?? throw new \LogicException('DerivedFacts series entries must carry a value');

        if ($earlierValue->parsed === null || $laterValue->parsed === null) {
            throw new \LogicException('DerivedFacts series entries must carry a non-null parsed value');
        }

        $delta = $laterValue->parsed - $earlierValue->parsed;
        $raw = ($delta >= 0.0 ? '+' : '') . number_format($delta, 2, '.', '');
        $value = new FactValue($raw, $delta, Comparator::None, $laterValue->unitOriginal, $laterValue->unitCanonical, $laterValue->conversionVersion);
        $citations = [...$earlier->citations, ...$later->citations];
        $factId = FactId::compute($capability, $kind, $citations, $value);

        return new Fact(
            $factId,
            $capability,
            $capabilityVersion,
            $kind,
            $later->pid,
            $later->clinicalDate,
            $later->dateSource,
            $value,
            FactStatus::Final,
            [],
            $citations,
        );
    }
}
