<?php

/**
 * End-to-end lab contract evals (C1-C4 + exclusion accounting) over in-memory rows.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabRowProcessor;
use PHPUnit\Framework\TestCase;

/**
 * These tests are named after (and directly satisfy) the U4 acceptance
 * row's six named contract evals, cross-checked against the shapes recorded
 * in tests/Seed/fixtures/expected/landmines.json. Each documents the failure
 * mode it guards.
 */
final class LabRowProcessorTest extends TestCase
{
    /**
     * Eval: comparator censoring. Guards a censored "<7.0" value ever being
     * presented as an exact reading or entering a strict numeric threshold
     * comparison unmarked.
     */
    public function testComparatorCensoring(): void
    {
        $rows = [RawLabRowBuilder::collected(3, 12, '4548-4', 'N', '<7.0', '%', 'final', '2025-06-22')];

        $result = LabRowProcessor::process($rows, LabContractTestConfig::default(), Capability::ControlProxy, '1');

        self::assertCount(1, $result->presented);
        self::assertCount(0, $result->exclusions);
        $fact = $result->presented[0]->fact;
        self::assertSame('lt', $fact->value->comparator->value);
        self::assertSame(7.0, $fact->value->parsed);
        self::assertTrue($fact->hasFlag(Flag::censored()));
    }

    /**
     * Eval: supersession, in-place variant. Guards against fabricating a
     * "superseded" citation for a datum whose only prior state is
     * unrecoverable (procedure_result has no row-versioning column, T5/T6) --
     * a single UPDATEd row must present under its final (corrected) state
     * with exactly the one real citation it has, never an invented second one.
     */
    public function testSupersessionInPlaceVariant(): void
    {
        $rows = [RawLabRowBuilder::collected(3, 20, '4548-4', 'N', '8.1', '%', 'corrected', '2025-05-08')];

        $result = LabRowProcessor::process($rows, LabContractTestConfig::default(), Capability::ControlProxy, '1');

        self::assertCount(1, $result->presented);
        $fact = $result->presented[0]->fact;
        self::assertSame(FactStatus::Corrected, $fact->status);
        self::assertCount(1, $fact->citations);
        self::assertSame(20, $fact->citations[0]->pk);
    }

    /**
     * Eval: supersession, new-row variant. Guards the original (lower-rank
     * or lower-id) row ever winning, or the winner failing to cite the row
     * it supersedes.
     */
    public function testSupersessionNewRowVariant(): void
    {
        $rows = [
            RawLabRowBuilder::collected(3, 10, '4548-4', 'N', '7.5', '%', 'final', '2025-06-07'),
            RawLabRowBuilder::collected(3, 11, '4548-4', 'N', '7.8', '%', 'corrected', '2025-06-07'),
        ];

        $result = LabRowProcessor::process($rows, LabContractTestConfig::default(), Capability::ControlProxy, '1');

        self::assertCount(1, $result->presented, 'the two rows must resolve into ONE presented fact, not two');
        self::assertCount(0, $result->exclusions, 'the superseded row is not an exclusion -- it is cited by the winner');
        $fact = $result->presented[0]->fact;
        self::assertSame(FactStatus::Corrected, $fact->status);
        self::assertSame(7.8, $fact->value->parsed);
        self::assertTrue($fact->hasFlag(Flag::supersededCount(1)));
        $pks = array_map(static fn ($c) => $c->pk, $fact->citations);
        self::assertEqualsCanonicalizing([10, 11], $pks);
    }

    /**
     * Eval: cannot-be-done != clock reset. Guards an order that was never
     * actually completed ("cannot be done", "pending", ...) from ever
     * resetting the OverdueTests clock -- an unperformed test can't prove
     * the patient isn't overdue.
     */
    public function testCannotBeDoneStatusIsExcludedAndNeverResetsClock(): void
    {
        $rows = [RawLabRowBuilder::collected(5, 30, '4548-4', 'N', '', '%', 'cannot be done', '2025-01-01')];

        $result = LabRowProcessor::process($rows, LabContractTestConfig::default(), Capability::ControlProxy, '1');

        self::assertCount(0, $result->presented, 'an unperformed test must never enter the presented/clock-eligible pool');
        self::assertCount(1, $result->exclusions);
        $exclusion = $result->exclusions[0];
        self::assertSame(FactStatus::Excluded, $exclusion->status);
        self::assertTrue($exclusion->hasFlag(Flag::excludedReason(ExclusionReason::UnresultedStatus)));
    }

    /**
     * Eval: unitless excluded-but-visible. Guards I5 (no silent exclusion)
     * AND C4 (no unit, no math): the row must produce a Fact a physician can
     * see was excluded and why, but must never carry a bare number with no
     * unit as if it were a usable numeric claim.
     */
    public function testUnitlessValueIsExcludedButVisible(): void
    {
        $rows = [RawLabRowBuilder::collected(3, 13, '2345-7', 'N', '110', '', 'final', '2025-07-02')];

        $result = LabRowProcessor::process($rows, LabContractTestConfig::default(), Capability::ControlProxy, '1');

        self::assertCount(0, $result->presented);
        self::assertCount(1, $result->exclusions, 'I5: the row must still be visible as an exclusion Fact');
        $exclusion = $result->exclusions[0];
        self::assertSame('110', $exclusion->value->raw, 'raw text preserved for transparency');
        self::assertNull($exclusion->value->parsed, 'C4: no numeric claim survives a missing unit, even though "110" parses cleanly');
        self::assertTrue($exclusion->hasFlag(Flag::excludedReason(ExclusionReason::Unitless)));
        self::assertSame('units', $exclusion->citations[0]->field);
    }

    /**
     * Eval: mmol/mol conversion. Guards the IFCC->NGSP formula, and that
     * both the original and canonical units plus the conversion version
     * survive onto the Fact (T9: a physician must be able to see the
     * conversion happened, not just its result).
     */
    public function testMmolMolConversion(): void
    {
        $rows = [RawLabRowBuilder::collected(4, 15, '4548-4', 'N', '58', 'mmol/mol', 'final', '2025-06-17')];

        $result = LabRowProcessor::process($rows, LabContractTestConfig::default(), Capability::ControlProxy, '1');

        self::assertCount(1, $result->presented);
        self::assertCount(0, $result->exclusions);
        $value = $result->presented[0]->fact->value;
        self::assertEqualsWithDelta(7.5, $value->parsed, 0.001);
        self::assertSame('mmol/mol', $value->unitOriginal);
        self::assertSame('%', $value->unitCanonical);
        self::assertSame('v1', $value->conversionVersion);
    }

    /**
     * Eval: unrecognized-status visible exclusion. Guards a status string
     * the contract has never seen ("amended") from either (a) silently
     * vanishing or (b) being guessed into a known status.
     */
    public function testUnrecognizedStatusIsVisibleExclusion(): void
    {
        $rows = [RawLabRowBuilder::collected(4, 16, '18262-6', 'N', '135', 'mg/dL', 'amended', '2025-05-28')];

        $result = LabRowProcessor::process($rows, LabContractTestConfig::default(), Capability::ControlProxy, '1');

        self::assertCount(0, $result->presented);
        self::assertCount(1, $result->exclusions);
        $exclusion = $result->exclusions[0];
        self::assertTrue($exclusion->hasFlag(Flag::excludedReason(ExclusionReason::UnrecognizedStatus)));
        // The unit itself was fine (mg/dL is already canonical for cholesterol),
        // so the value is still fully resolved even though presentation is
        // suppressed for a status reason, not a value/unit reason.
        self::assertSame(135.0, $exclusion->value->parsed);
        self::assertSame('result_status', $exclusion->citations[0]->field);
    }

    /**
     * Not one of the six named evals, but the same landmine set: preliminary
     * results are presented (in-flight), never excluded, and never reset
     * the clock.
     */
    public function testPreliminaryResultIsPresentedInFlightAndNeverResetsClock(): void
    {
        $rows = [RawLabRowBuilder::collected(4, 17, '4548-4', 'N', '8.2', '%', 'preliminary', '2025-07-04')];

        $result = LabRowProcessor::process($rows, LabContractTestConfig::default(), Capability::ControlProxy, '1');

        self::assertCount(1, $result->presented);
        self::assertCount(0, $result->exclusions);
        self::assertTrue($result->presented[0]->inFlight);
        self::assertFalse($result->presented[0]->resetsClock);
        self::assertSame(FactStatus::Preliminary, $result->presented[0]->fact->status);
    }

    /**
     * Guards the C4-governance boundary: an analyte outside the conversion
     * whitelist (ACR) must NOT be excluded merely for lacking conversion
     * config -- only a truly empty/unrecognized unit is excluded. This is
     * the fix for a real bug this suite caught during development: applying
     * "no unit, no math" universally would have wrongly excluded every ACR
     * result (which has no unit_conversion entry at all), breaking the
     * overdue-ACR landmines, which are never excluded.
     */
    public function testUngovernedAnalyteWithARealUnitIsPresentedNotExcluded(): void
    {
        $rows = [RawLabRowBuilder::collected(1, 5, '14957-5', 'N', '45', 'mg/g', 'final', '2024-05-07')];

        $result = LabRowProcessor::process($rows, LabContractTestConfig::default(), Capability::OverdueTests, '1');

        self::assertCount(1, $result->presented);
        self::assertCount(0, $result->exclusions);
        $value = $result->presented[0]->fact->value;
        self::assertSame(45.0, $value->parsed);
        self::assertSame('mg/g', $value->unitOriginal);
        self::assertSame('mg/g', $value->unitCanonical);
        self::assertNull($value->conversionVersion);
    }

    /**
     * Rising A1c trend landmine: four ascending final draws, no exclusions,
     * every one resets the clock (used by OverdueTests to find the true
     * most-recent draw).
     */
    public function testRisingTrendSeriesAllPresentedNoExclusions(): void
    {
        $rows = [
            RawLabRowBuilder::collected(1, 1, '4548-4', 'N', '7.2', '%', 'final', '2024-07-07'),
            RawLabRowBuilder::collected(1, 2, '4548-4', 'N', '7.6', '%', 'final', '2024-10-07'),
            RawLabRowBuilder::collected(1, 3, '4548-4', 'N', '8.0', '%', 'final', '2025-01-07'),
            RawLabRowBuilder::collected(1, 4, '4548-4', 'N', '8.4', '%', 'final', '2025-04-07'),
        ];

        $result = LabRowProcessor::process($rows, LabContractTestConfig::default(), Capability::ControlProxy, '1');

        self::assertCount(4, $result->presented);
        self::assertCount(0, $result->exclusions);
        $parsedValues = array_map(static fn ($p) => $p->fact->value->parsed, $result->presented);
        sort($parsedValues);
        self::assertSame([7.2, 7.6, 8.0, 8.4], $parsedValues);
        foreach ($result->presented as $presentedFact) {
            self::assertTrue($presentedFact->resetsClock);
        }
    }

    /**
     * I5 exclusion accounting: a mixed batch of presented and excluded rows
     * must account for every single row -- none silently dropped, none
     * double-counted.
     */
    public function testExclusionAccountingCoversEveryRow(): void
    {
        $rows = [
            RawLabRowBuilder::collected(9, 1, '4548-4', 'N', '7.2', '%', 'final', '2025-01-01'),
            RawLabRowBuilder::collected(9, 2, '4548-4', 'N', '', '%', 'pending', '2025-02-01'),
            RawLabRowBuilder::collected(9, 3, '2345-7', 'N', '90', '', 'final', '2025-03-01'),
            RawLabRowBuilder::collected(9, 4, '18262-6', 'N', '100', 'mg/dL', 'weird_status', '2025-04-01'),
        ];

        $result = LabRowProcessor::process($rows, LabContractTestConfig::default(), Capability::ControlProxy, '1');

        self::assertCount(1, $result->presented);
        self::assertCount(3, $result->exclusions);
        self::assertCount(4, [...$result->presented, ...$result->exclusions], 'every raw row must be accounted for exactly once');
    }

    public function testEmptyInputProducesEmptyResult(): void
    {
        $result = LabRowProcessor::process([], LabContractTestConfig::default(), Capability::ControlProxy, '1');

        self::assertSame([], $result->presented);
        self::assertSame([], $result->exclusions);
    }

    /**
     * Every presented Fact's pid must match the row it was built from (I10
     * groundwork -- U4 does not itself enforce cross-session pinning, but it
     * must never emit a Fact whose pid disagrees with its own citation).
     */
    public function testPresentedFactsCarryTheCorrectPid(): void
    {
        $rows = [RawLabRowBuilder::collected(42, 1, '4548-4', 'N', '7.2', '%', 'final', '2025-01-01')];

        $result = LabRowProcessor::process($rows, LabContractTestConfig::default(), Capability::ControlProxy, '1');

        self::assertSame(42, $result->presented[0]->fact->pid);
    }
}
