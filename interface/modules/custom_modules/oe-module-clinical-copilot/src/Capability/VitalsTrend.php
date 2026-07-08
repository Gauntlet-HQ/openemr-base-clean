<?php

/**
 * VitalsTrend capability: weight/BP/BMI from form_vitals.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Capability\Support\DerivedFacts;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;

/**
 * UC1, UC3 (weight/BP context for regimen changes).
 *
 * Code set / slice: `form_vitals`, pid-indexed
 * (`WHERE pid = ? AND activity = 1 ORDER BY date`), read directly via
 * `QueryUtils` rather than the host `VitalsService` -- `VitalsService`'s
 * `search()` layers in global-settings-dependent USA/metric unit conversion
 * (`units_of_measurement`), which would make the SAME stored row present a
 * different canonical value depending on a site setting neither this
 * capability's Facts nor its digest inputs account for; a direct, verbatim
 * column read keeps unit handling simple and deterministic for v1 (see the
 * accepted-limitation note in the U5 report on weight units).
 *
 * Threshold: none (no `mod_copilot_cadence` threshold config governs vitals
 * in v1 -- ARCHITECTURE_COMPLETE.md's capability table lists no threshold
 * for VitalsTrend, only "flagged value must exist in the row").
 *
 * Invariant: "flagged value must exist in the row" -- a `vital` Fact for a
 * given metric is only emitted when that row's own column(s) are actually
 * set; a row with a null/empty weight never produces a weight Fact, etc.
 * (implemented via {@see self::isSetNumeric()}). Unlike the lab slice
 * contract, there is no analogous exclusion-and-flag concept here (a row
 * simply not having a value is not a filtered datum -- I5 governs rows a
 * slice read decided NOT to present; an absent column value was never a
 * presentable row to begin with), so `exclusions` is always empty.
 *
 * Derived facts: `derived_delta`/`derived_span`/`derived_count` over the
 * weight series and, separately, the BMI series (both via
 * {@see DerivedFacts}, same math ControlProxy uses for lab trends). Blood
 * pressure is presented as a `vital` Fact per row (systolic/diastolic as one
 * composite raw string, e.g. "132/84") but is deliberately excluded from
 * derived-fact math in v1: a single scalar delta over a two-component
 * reading needs a documented convention (systolic-only? MAP?) this build
 * does not make a clinical call on -- an accepted scope limitation, not an
 * oversight (see the U5 report).
 */
final class VitalsTrend implements CapabilityInterface
{
    private const CAPABILITY = Capability::VitalsTrend;
    private const CAPABILITY_VERSION = '1';

    private const WEIGHT_UNIT = 'lb';
    private const BP_UNIT = 'mmHg';

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
        return $this->extractInternal($pid, null, null);
    }

    /**
     * U11 chat tool `get_vitals_trend` (ARCHITECTURE.md §1.2): a
     * metric-scoped, window-bounded variant of {@see self::extract()} for
     * follow-up drill-downs beyond the preloaded envelope. `$metric` is one
     * of the tool's enum values (`weight`/`bp`/`bmi`); `$windowMonths` bounds
     * `date` to the trailing window. A row outside the window is still
     * accounted (I14) -- it was fully classified, just not requested by this
     * narrower call -- and derived facts (delta/span/count) are recomputed
     * over the windowed series only.
     *
     * @throws \DomainException if `$metric` is not one of the tool's three enum values
     */
    public function extractForMetric(int $pid, string $metric, int $windowMonths): CapabilityResult
    {
        if (!in_array($metric, ['weight', 'bp', 'bmi'], true)) {
            throw new \DomainException("VitalsTrend::extractForMetric: unrecognized metric '{$metric}'");
        }

        $cutoff = (new \DateTimeImmutable('now'))->sub(new \DateInterval('P' . max(1, $windowMonths) . 'M'));

        return $this->extractInternal($pid, $metric, $cutoff);
    }

    private function extractInternal(int $pid, ?string $metric, ?\DateTimeImmutable $cutoff): CapabilityResult
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT `id`, `date`, `weight`, `bps`, `bpd`, `BMI` FROM `form_vitals` WHERE `pid` = ? AND `activity` = 1 ORDER BY `date` ASC',
            [$pid],
        );

        $presented = [];
        $weightSeries = [];
        $bmiSeries = [];
        $rawInputCount = count($rows);
        $accountedCount = 0;

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $date = self::parseDate($row['date'] ?? null);
            if ($id <= 0 || $date === null) {
                // I14: a form_vitals row this capability could not even
                // identify (no usable pk) or date (no parseable `date`
                // column) is left unaccounted rather than silently skipped
                // -- a real row with every vital column legitimately empty
                // still reaches the branches below and IS accounted (it just
                // produces zero `vital` Facts, which is not the same thing
                // as "this row could not be classified at all").
                continue;
            }
            $accountedCount++;

            if ($cutoff !== null && $date < $cutoff) {
                continue;
            }

            if (($metric === null || $metric === 'weight') && self::isSetNumeric($row['weight'] ?? null)) {
                $fact = self::buildNumericVital($pid, $id, $date, 'weight', (float)$row['weight'], self::WEIGHT_UNIT);
                $presented[] = $fact;
                $weightSeries[] = $fact;
            }

            if (($metric === null || $metric === 'bp') && self::isSetNumeric($row['bps'] ?? null) && self::isSetNumeric($row['bpd'] ?? null)) {
                $presented[] = self::buildBloodPressureVital($pid, $id, $date, (string)$row['bps'], (string)$row['bpd']);
            }

            if (($metric === null || $metric === 'bmi') && self::isSetNumeric($row['BMI'] ?? null)) {
                $fact = self::buildNumericVital($pid, $id, $date, 'BMI', (float)$row['BMI'], '');
                $presented[] = $fact;
                $bmiSeries[] = $fact;
            }
        }

        foreach ([$weightSeries, $bmiSeries] as $series) {
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

        return new CapabilityResult($presented, [], $rawInputCount, $accountedCount);
    }

    private static function isSetNumeric(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return is_numeric($value) && (float)$value > 0.0;
    }

    private static function buildNumericVital(int $pid, int $formVitalsId, \DateTimeImmutable $date, string $field, float $value, string $unit): Fact
    {
        $citations = [new Citation('form_vitals', $formVitalsId, $field, DateSource::Collected)];
        $rawValue = new FactValue(self::formatNumber($value), $value, Comparator::None, $unit, $unit !== '' ? $unit : null, null);
        $factId = FactId::compute(self::CAPABILITY, FactKind::Vital, $citations, $rawValue);

        return new Fact(
            $factId,
            self::CAPABILITY,
            self::CAPABILITY_VERSION,
            FactKind::Vital,
            $pid,
            $date,
            DateSource::Collected,
            $rawValue,
            FactStatus::Final,
            [],
            $citations,
        );
    }

    private static function buildBloodPressureVital(int $pid, int $formVitalsId, \DateTimeImmutable $date, string $bps, string $bpd): Fact
    {
        $citations = [
            new Citation('form_vitals', $formVitalsId, 'bps', DateSource::Collected),
            new Citation('form_vitals', $formVitalsId, 'bpd', DateSource::Collected),
        ];
        // Composite (systolic/diastolic) reading: no single numeric claim is
        // made (parsed stays null, C3's "no numeric claim without a parsed
        // numeric" spirit applied here even though BP is outside the C1-C4
        // lab contract proper) -- presented verbatim.
        $value = new FactValue("{$bps}/{$bpd}", null, Comparator::None, self::BP_UNIT, self::BP_UNIT, null);
        $factId = FactId::compute(self::CAPABILITY, FactKind::Vital, $citations, $value);

        return new Fact(
            $factId,
            self::CAPABILITY,
            self::CAPABILITY_VERSION,
            FactKind::Vital,
            $pid,
            $date,
            DateSource::Collected,
            $value,
            FactStatus::Final,
            [],
            $citations,
        );
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $parsed !== false ? $parsed : null;
    }
}
