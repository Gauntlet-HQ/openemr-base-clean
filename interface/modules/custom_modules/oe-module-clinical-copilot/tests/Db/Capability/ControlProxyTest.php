<?php

/**
 * DB-backed U5 acceptance evals: ControlProxy against the U2 seeded patients.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Capability;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Capability\ControlProxy;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use PHPUnit\Framework\TestCase;

/**
 * Requires the U2 seed (`php tests/Seed/SeedClinicalCopilot.php --force`,
 * see CONTRIBUTING.md/CLAUDE.md) and U5's `threshold:a1c` config row
 * (table.sql) to have been applied. Cross-checked against
 * tests/Seed/fixtures/expected/landmines.json.
 */
final class ControlProxyTest extends TestCase
{
    private ControlProxy $controlProxy;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->controlProxy = new ControlProxy(new LabSliceReader(new DbLabContractConfigProvider()));
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    private function pidFor(string $pubpid): int
    {
        $pid = QueryUtils::fetchSingleValue('SELECT `pid` FROM `patient_data` WHERE `pubpid` = ?', 'pid', [$pubpid]);
        self::assertNotNull($pid, "Seed patient {$pubpid} not found -- run tests/Seed/SeedClinicalCopilot.php --force first.");

        return (int)$pid;
    }

    /**
     * Eval: CCP-001's four rising, final A1c draws all become `trend_point`
     * facts (no corrections/censoring in this fixture) with correct
     * derived_delta/span/count, and the out-of-range threshold (seeded by
     * U5) flags every draw above 7.0%.
     */
    public function testRisingA1cTrendWithDerivedFactsAndThreshold(): void
    {
        $pid = $this->pidFor('CCP-001');

        $result = $this->controlProxy->extract($pid);

        $trendPoints = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::TrendPoint && $f->value?->unitCanonical === '%'));
        self::assertCount(4, $trendPoints, 'CCP-001 seeds exactly 4 final A1c draws, all trend-eligible');

        usort($trendPoints, static fn ($a, $b) => $a->clinicalDate <=> $b->clinicalDate);
        $values = array_map(static fn ($f) => $f->value->parsed, $trendPoints);
        self::assertSame([7.2, 7.6, 8.0, 8.4], $values, 'trend must read as rising, oldest to newest');

        foreach ($trendPoints as $tp) {
            self::assertTrue($tp->hasFlag(Flag::outOfRangeByValue()), 'every seeded A1c draw is above the seeded threshold:a1c (7.0, high)');
        }

        $deltas = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::DerivedDelta));
        self::assertCount(3, $deltas, 'N points -> N-1 consecutive deltas');

        $spans = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::DerivedSpan));
        self::assertCount(1, $spans);
        self::assertEqualsWithDelta(1.2, $spans[0]->value?->parsed, 0.01, 'span = last(8.4) - first(7.2)');

        $counts = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::DerivedCount));
        self::assertCount(1, $counts);
        self::assertSame(4.0, $counts[0]->value?->parsed);
    }

    /**
     * Eval: CCP-003's corrected and censored A1c draws stay `kind: result`,
     * never `trend_point` -- the re-kinding rule must not blend a correction
     * or a censored value into an ascending-trend narrative.
     */
    public function testCorrectedAndCensoredNeverBecomeTrendPoints(): void
    {
        $pid = $this->pidFor('CCP-003');

        $result = $this->controlProxy->extract($pid);

        $corrected = array_values(array_filter($result->presented, static fn ($f) => $f->status === FactStatus::Corrected));
        self::assertCount(2, $corrected, 'CCP-003 seeds two corrected A1c facts (in-place + new-row)');
        foreach ($corrected as $fact) {
            self::assertSame(FactKind::Result, $fact->kind, 'a correction must never be re-kinded into trend_point');
        }

        $censored = array_values(array_filter($result->presented, static fn ($f) => $f->hasFlag(Flag::censored())));
        self::assertCount(1, $censored);
        self::assertSame(FactKind::Result, $censored[0]->kind, 'a censored value must never be re-kinded into trend_point');

        $unitlessExclusion = array_values(array_filter(
            $result->exclusions,
            static fn ($f) => $f->kind === FactKind::Exclusion && $f->value?->unitOriginal === '',
        ));
        self::assertCount(1, $unitlessExclusion, 'the unitless glucose draw stays a visible exclusion (I5), unchanged by ControlProxy');
    }

    /**
     * Eval: CCP-004's IFCC mmol/mol A1c converts and becomes a trend_point
     * above threshold; its preliminary A1c is skipped entirely by
     * ControlProxy (ceded to PendingResults, C2); its unrecognized-status LDL
     * draw remains a visible exclusion.
     */
    public function testIfccConversionPreliminarySkipAndUnrecognizedStatusExclusion(): void
    {
        $pid = $this->pidFor('CCP-004');

        $result = $this->controlProxy->extract($pid);

        $converted = array_values(array_filter($result->presented, static fn ($f) => $f->value?->unitOriginal === 'mmol/mol'));
        self::assertCount(1, $converted);
        self::assertSame(FactKind::TrendPoint, $converted[0]->kind);
        self::assertEqualsWithDelta(7.5, $converted[0]->value?->parsed, 0.01);
        self::assertTrue($converted[0]->hasFlag(Flag::outOfRangeByValue()), 'converted value 7.5% is above the seeded 7.0 threshold');

        $preliminaryLeak = array_values(array_filter($result->presented, static fn ($f) => $f->status === FactStatus::Preliminary));
        self::assertCount(0, $preliminaryLeak, 'ControlProxy must never present a preliminary result under any kind -- PendingResults owns it');

        $unrecognized = array_values(array_filter(
            $result->exclusions,
            static fn ($f) => $f->hasFlag(Flag::excludedReason(ExclusionReason::UnrecognizedStatus)),
        ));
        self::assertCount(1, $unrecognized, 'the amended-status LDL draw is a visible exclusion under ControlProxy too (it shares the lipids code set)');
    }

    /**
     * I14 conservation eval: a garbage/unmapped `result_status` value must
     * never silently vanish -- it either becomes a visible exclusion
     * (accounted) or trips `unaccountedCount() > 0`. Today it becomes an
     * exclusion (`unrecognized_status`, already covered by
     * `testIfccConversionPreliminarySkipAndUnrecognizedStatusExclusion`
     * above for CCP-004's seeded case); this eval independently verifies the
     * counting itself stays whole for a freshly-inserted garbage row, not
     * just that the Fact shows up.
     */
    public function testConservationGarbageStatusRowNeverVanishes(): void
    {
        $pid = $this->pidFor('CCP-002');
        $loinc = '2093-3'; // total cholesterol -- unused elsewhere in CCP-002's fixture set
        $date = (new \DateTimeImmutable('-2 months'))->format('Y-m-d H:i:s');

        $orderId = QueryUtils::sqlInsert(
            'INSERT INTO `procedure_order` (`provider_id`, `patient_id`, `encounter_id`, `date_collected`, `date_ordered`, `order_status`, `activity`, `procedure_order_type`)
             VALUES (1, ?, 0, ?, ?, \'complete\', 1, \'laboratory_test\')',
            [$pid, $date, $date],
        );
        QueryUtils::sqlInsert(
            'INSERT INTO `procedure_order_code` (`procedure_order_id`, `procedure_order_seq`, `procedure_code`, `procedure_name`, `procedure_source`)
             VALUES (?, 1, ?, \'Total Cholesterol\', \'1\')',
            [$orderId, $loinc],
        );
        $reportId = QueryUtils::sqlInsert(
            'INSERT INTO `procedure_report` (`procedure_order_id`, `procedure_order_seq`, `date_collected`, `date_report`, `report_status`, `review_status`)
             VALUES (?, 1, ?, ?, \'complete\', \'reviewed\')',
            [$orderId, $date, $date],
        );
        QueryUtils::sqlInsert(
            'INSERT INTO `procedure_result` (`procedure_report_id`, `result_data_type`, `result_code`, `result_text`, `date`, `units`, `result`, `range`, `abnormal`, `result_status`)
             VALUES (?, \'N\', ?, \'Total Cholesterol\', ?, \'mg/dL\', \'210\', \'\', \'\', \'this-status-does-not-exist-in-c2\')',
            [$reportId, $loinc, $date],
        );

        $result = $this->controlProxy->extract($pid);

        self::assertSame(0, $result->unaccountedCount(), 'a garbage result_status must surface as a visible exclusion, never a silent drop');
        self::assertGreaterThanOrEqual(1, $result->rawInputCount);

        $garbage = array_values(array_filter(
            $result->exclusions,
            static fn ($f) => $f->hasFlag(Flag::excludedReason(ExclusionReason::UnrecognizedStatus)),
        ));
        self::assertNotEmpty($garbage, 'the garbage-status row must appear as an unrecognized_status exclusion');
    }
}
