<?php

/**
 * ControlProxy capability: A1c/glucose/lipid trajectory against thresholds.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Modules\ClinicalCopilot\Capability\Config\AnalyteCodeSets;
use OpenEMR\Modules\ClinicalCopilot\Capability\Support\DerivedFacts;
use OpenEMR\Modules\ClinicalCopilot\Capability\Support\FactRekind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;

/**
 * UC1, UC2 ("Is control on target?").
 *
 * Code set: {@see AnalyteCodeSets::controlProxyCodes()} -- A1c, glucose, and
 * the four lipid-panel LOINC codes. Slice: U4's
 * {@see LabSliceReader} (the `procedure_order`/`procedure_report`/
 * `procedure_result` 3-table join, activity=1, full C1-C4 contract). One
 * `read()` call per LOINC code (not one call across the whole set) so each
 * code's presented rows can be grouped into their OWN trend series --
 * {@see \OpenEMR\Modules\ClinicalCopilot\Lab\PresentedLabFact} does not
 * carry the source `result_code` back out, so per-code series identity has
 * to come from the call boundary itself.
 *
 * Threshold: C3 proof (a)/(b) out-of-range flags
 * (`out_of_range_by_value`/`out_of_range_by_lab_flag`/`conflict`) are already
 * resolved onto the underlying `result`-kind Fact by U4's
 * {@see \OpenEMR\Modules\ClinicalCopilot\Lab\LabRowProcessor} +
 * {@see \OpenEMR\Modules\ClinicalCopilot\Lab\OutOfRangeEvaluator} as long as a
 * `threshold:<analyte>` config row exists (U5 seeds `threshold:a1c`,
 * `threshold:glucose`, `threshold:cholesterol`, `threshold:triglycerides` in
 * table.sql/sql/install.sql) -- ControlProxy re-kinds those facts but never
 * re-evaluates out-of-range itself; the flags and their citations survive
 * re-kinding unchanged via {@see FactRekind}.
 *
 * Output schema / invariant (ARCHITECTURE_COMPLETE.md "Capabilities" table):
 * out-of-range only via the two admissible C3 proofs (already true of every
 * Fact this capability re-kinds, per the paragraph above).
 *
 * Re-kinding rule (resolves the "when is a result a trend_point" ambiguity
 * U4 deliberately left open, see {@see \OpenEMR\Modules\ClinicalCopilot\Lab\PresentedLabFact}):
 * - `inFlight` (preliminary) rows are skipped here entirely -- C2: a
 *   preliminary result renders beside PendingResults, never as a trend
 *   point, and PendingResults (not ControlProxy) is the capability that
 *   presents it (as `preliminary_result`), so ControlProxy does not
 *   duplicate it under a different kind.
 * - A presented, non-in-flight row becomes `trend_point` only when its
 *   status is NOT `corrected` AND its value is an exact (non-censored),
 *   non-null parsed number. A correction stays `kind: result`: a corrected
 *   value is exactly the "silently replacing what she remembers" failure
 *   mode named in USERS.md -- it is presented as itself, not blended into an
 *   ascending trend line the physician would read as an uninterrupted
 *   series. A censored value ("<7.0") also stays `kind: result`: C3/C2 both
 *   say a censored value "supports only the claim its direction proves...
 *   never a trend point in the strict numeric sense."
 *
 * Derived facts: for each LOINC code's trend-point series (ascending by
 * clinical date), `derived_delta` per consecutive pair, one `derived_span`
 * (first-to-last), and one `derived_count` -- all via
 * {@see DerivedFacts}, which cites the raw trend_points' own citations.
 */
final class ControlProxy implements CapabilityInterface
{
    private const CAPABILITY = Capability::ControlProxy;
    private const CAPABILITY_VERSION = '1';

    public function __construct(
        private readonly LabSliceReader $labSliceReader,
    ) {
    }

    public function capability(): Capability
    {
        return self::CAPABILITY;
    }

    public function capabilityVersion(): string
    {
        return self::CAPABILITY_VERSION;
    }

    public function extract(int $pid): CapabilityResult
    {
        return $this->extractCodes($pid, AnalyteCodeSets::controlProxyCodes(), null);
    }

    /**
     * U11 chat tool `get_control_trend` (ARCHITECTURE.md §1.2): an
     * analyte-scoped, window-bounded variant of {@see self::extract()} for
     * follow-up drill-downs beyond the preloaded envelope ("list every A1c
     * value, not just the trend" -- USERS.md UC6). `$analyte` selects the
     * SAME LOINC subset {@see self::extract()} already decomposes into
     * per-code series (`a1c`/`glucose`/`lipids`); `$windowMonths` bounds
     * `clinical_date` to the trailing window. Derived facts
     * (delta/span/count) are recomputed over the WINDOWED series, never the
     * full-history one, so a narrower window never cites a delta computed
     * from data outside it.
     *
     * @throws \DomainException if `$analyte` is not one of the tool's three enum values
     */
    public function extractForAnalyte(int $pid, string $analyte, int $windowMonths): CapabilityResult
    {
        $codes = match ($analyte) {
            'a1c' => [AnalyteCodeSets::LOINC_A1C],
            'glucose' => [AnalyteCodeSets::LOINC_GLUCOSE],
            'lipids' => AnalyteCodeSets::LIPIDS,
            default => throw new \DomainException("ControlProxy::extractForAnalyte: unrecognized analyte '{$analyte}'"),
        };

        return $this->extractCodes($pid, $codes, self::windowCutoff($windowMonths));
    }

    /**
     * @param list<string> $codes
     */
    private function extractCodes(int $pid, array $codes, ?\DateTimeImmutable $cutoff): CapabilityResult
    {
        $presented = [];
        $exclusions = [];
        $rawInputCount = 0;
        $accountedCount = 0;

        foreach ($codes as $loincCode) {
            $slice = $this->labSliceReader->read($pid, [$loincCode], self::CAPABILITY, self::CAPABILITY_VERSION);
            $exclusions = [...$exclusions, ...$slice->exclusions];
            // I14: aggregated straight from LabSliceResult's own independent
            // per-row tally -- derived_* facts added below are NOT raw rows
            // and never enter this count. A window cutoff narrows what is
            // PRESENTED, never what is ACCOUNTED (the row was still fully
            // classified; the caller just asked for a trailing slice of it).
            $rawInputCount += $slice->rawInputCount;
            $accountedCount += $slice->accountedCount;

            $series = [];
            foreach ($slice->presented as $presentedLabFact) {
                if ($presentedLabFact->inFlight) {
                    // Ceded to PendingResults (C2) -- never duplicated here.
                    continue;
                }

                if ($cutoff !== null && $presentedLabFact->fact->clinicalDate !== null && $presentedLabFact->fact->clinicalDate < $cutoff) {
                    continue;
                }

                $kind = self::isTrendEligible($presentedLabFact->fact) ? FactKind::TrendPoint : FactKind::Result;
                $fact = FactRekind::withKind($presentedLabFact->fact, $kind);
                $presented[] = $fact;

                if ($kind === FactKind::TrendPoint) {
                    $series[] = $fact;
                }
            }

            usort($series, static fn (Fact $a, Fact $b): int => self::dateKey($a) <=> self::dateKey($b));

            $presented = [...$presented, ...DerivedFacts::deltas(self::CAPABILITY, self::CAPABILITY_VERSION, $series)];

            $span = DerivedFacts::span(self::CAPABILITY, self::CAPABILITY_VERSION, $series);
            if ($span !== null) {
                $presented[] = $span;
            }

            $count = DerivedFacts::count(self::CAPABILITY, self::CAPABILITY_VERSION, $series);
            if ($count !== null) {
                $presented[] = $count;
            }
        }

        return new CapabilityResult($presented, $exclusions, $rawInputCount, $accountedCount);
    }

    private static function windowCutoff(int $windowMonths): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now'))->sub(new \DateInterval('P' . max(1, $windowMonths) . 'M'));
    }

    private static function isTrendEligible(Fact $fact): bool
    {
        // A fact with no clinical date can't be placed on the timeline: it
        // would bypass the window cutoff (which only excludes dated facts) and
        // sort to epoch 0 (dateKey ?? 0), landing first in the series and
        // corrupting the derived deltas/span with data outside the window.
        return $fact->clinicalDate !== null
            && $fact->status !== FactStatus::Corrected
            && $fact->value?->comparator === Comparator::None
            && $fact->value?->parsed !== null;
    }

    private static function dateKey(Fact $fact): int
    {
        return $fact->clinicalDate?->getTimestamp() ?? 0;
    }
}
