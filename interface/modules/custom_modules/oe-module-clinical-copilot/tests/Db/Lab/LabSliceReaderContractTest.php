<?php

/**
 * DB-backed U4 acceptance evals: the full lab contract read against real
 * `procedure_order`/`procedure_report`/`procedure_result` rows.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Lab;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use PHPUnit\Framework\TestCase;

/**
 * Requires the U2 seed to have been run against the dev stack
 * (`php tests/Seed/SeedClinicalCopilot.php --force`, see CONTRIBUTING.md /
 * CLAUDE.md) so patients CCP-001..CCP-004 and their landmine rows exist.
 * Cross-checked against tests/Seed/fixtures/expected/landmines.json.
 *
 * Named per the U4 acceptance row (ARCHITECTURE_COMPLETE.md): comparator
 * censoring; supersession (both variants); cannot-be-done != clock reset;
 * unitless excluded-but-visible; mmol/mol conversion; unrecognized-status
 * visible exclusion. Each test documents the failure mode it guards.
 *
 * `testCannotBeDoneNeverResetsClock` inserts (and rolls back) its own
 * fixture row: the U2 seed's landmine set does not include a "cannot be
 * done"/"pending"/"incomplete"/"error"/"canceled" result_status example, so
 * this eval is self-contained rather than relying on seeded data -- see the
 * U4 report for why this is flagged as a seed-coverage gap rather than
 * silently assumed.
 */
final class LabSliceReaderContractTest extends TestCase
{
    private const LOINC_A1C = '4548-4';
    private const LOINC_LDL = '18262-6';
    private const LOINC_GLUCOSE = '2345-7';

    private LabSliceReader $reader;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->reader = new LabSliceReader(new DbLabContractConfigProvider());
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
     * Eval: comparator censoring. CCP-003's "<7.0" A1c draw.
     */
    public function testComparatorCensoring(): void
    {
        $pid = $this->pidFor('CCP-003');

        $result = $this->reader->read($pid, [self::LOINC_A1C], Capability::ControlProxy, '1');

        $censored = array_values(array_filter(
            $result->presented,
            static fn ($p) => $p->fact->value?->comparator->isCensored() === true,
        ));
        self::assertCount(1, $censored, 'exactly one censored A1c draw is seeded for CCP-003');
        self::assertSame('lt', $censored[0]->fact->value->comparator->value);
        self::assertSame(7.0, $censored[0]->fact->value->parsed);
        self::assertTrue($censored[0]->fact->hasFlag(Flag::censored()));
    }

    /**
     * Eval: supersession, both variants. CCP-003 seeds an in-place
     * correction (one physical row, UPDATEd) and a new-row correction (two
     * physical rows).
     */
    public function testSupersessionBothVariants(): void
    {
        $pid = $this->pidFor('CCP-003');

        $result = $this->reader->read($pid, [self::LOINC_A1C], Capability::ControlProxy, '1');

        $correctedFacts = array_values(array_filter(
            $result->presented,
            static fn ($p) => $p->fact->status === FactStatus::Corrected,
        ));
        self::assertCount(2, $correctedFacts, 'CCP-003 seeds exactly two corrected A1c facts: in-place and new-row');

        $singleCitation = array_values(array_filter($correctedFacts, static fn ($p) => count($p->fact->citations) === 1));
        $twoCitations = array_values(array_filter($correctedFacts, static fn ($p) => count($p->fact->citations) === 2));

        self::assertCount(1, $singleCitation, 'in-place variant: one physical row, no fabricated superseded citation');
        self::assertCount(1, $twoCitations, 'new-row variant: winner cites the row it supersedes');
        self::assertTrue($twoCitations[0]->fact->hasFlag(Flag::supersededCount(1)));
    }

    /**
     * Eval: cannot-be-done != clock reset. Self-contained fixture (see class
     * docblock): inserts one procedure_order/report/result chain with
     * result_status='cannot be done' for CCP-001, verifies it is excluded
     * and never resets the clock, then rolls back.
     */
    public function testCannotBeDoneNeverResetsClock(): void
    {
        $pid = $this->pidFor('CCP-001');
        $loinc = '2093-3'; // total cholesterol -- unused elsewhere in CCP-001's fixture set
        $date = (new \DateTimeImmutable('-1 month'))->format('Y-m-d H:i:s');

        $orderId = QueryUtils::sqlInsert(
            'INSERT INTO `procedure_order` (`provider_id`, `patient_id`, `encounter_id`, `date_collected`, `date_ordered`, `order_status`, `activity`, `procedure_order_type`)
             VALUES (1, ?, 0, ?, ?, ?, 1, \'laboratory_test\')',
            [$pid, $date, $date, 'complete'],
        );
        QueryUtils::sqlInsert(
            'INSERT INTO `procedure_order_code` (`procedure_order_id`, `procedure_order_seq`, `procedure_code`, `procedure_name`, `procedure_source`)
             VALUES (?, 1, ?, ?, \'1\')',
            [$orderId, $loinc, 'Total Cholesterol'],
        );
        $reportId = QueryUtils::sqlInsert(
            'INSERT INTO `procedure_report` (`procedure_order_id`, `procedure_order_seq`, `date_collected`, `date_report`, `report_status`, `review_status`)
             VALUES (?, 1, ?, ?, \'complete\', \'reviewed\')',
            [$orderId, $date, $date],
        );
        QueryUtils::sqlInsert(
            'INSERT INTO `procedure_result` (`procedure_report_id`, `result_data_type`, `result_code`, `result_text`, `date`, `units`, `result`, `range`, `abnormal`, `result_status`)
             VALUES (?, \'N\', ?, \'Total Cholesterol\', ?, \'mg/dL\', \'\', \'\', \'\', \'cannot be done\')',
            [$reportId, $loinc, $date],
        );

        $result = $this->reader->read($pid, [$loinc], Capability::ControlProxy, '1');

        self::assertCount(0, $result->presented, 'an unperformed test must never enter the clock-eligible pool');
        self::assertCount(1, $result->exclusions);
        self::assertTrue($result->exclusions[0]->hasFlag(Flag::excludedReason(ExclusionReason::UnresultedStatus)));
    }

    /**
     * Eval: unitless excluded-but-visible. CCP-003's unitless glucose draw.
     */
    public function testUnitlessExcludedButVisible(): void
    {
        $pid = $this->pidFor('CCP-003');

        $result = $this->reader->read($pid, [self::LOINC_GLUCOSE], Capability::ControlProxy, '1');

        self::assertCount(0, $result->presented, 'the unitless glucose draw must never be presented');
        self::assertCount(1, $result->exclusions);
        $exclusion = $result->exclusions[0];
        self::assertTrue($exclusion->hasFlag(Flag::excludedReason(ExclusionReason::Unitless)));
        self::assertNull($exclusion->value?->parsed, 'no numeric claim survives a missing unit');
        self::assertSame('units', $exclusion->citations[0]->field);
    }

    /**
     * Eval: mmol/mol conversion. CCP-004's IFCC A1c draw (58 mmol/mol).
     */
    public function testMmolMolConversion(): void
    {
        $pid = $this->pidFor('CCP-004');

        $result = $this->reader->read($pid, [self::LOINC_A1C], Capability::ControlProxy, '1');

        $converted = array_values(array_filter(
            $result->presented,
            static fn ($p) => $p->fact->value?->unitOriginal === 'mmol/mol',
        ));
        self::assertCount(1, $converted, 'CCP-004 seeds exactly one IFCC mmol/mol A1c draw');
        self::assertSame('%', $converted[0]->fact->value->unitCanonical);
        self::assertEqualsWithDelta(7.5, $converted[0]->fact->value->parsed, 0.001);
        self::assertNotNull($converted[0]->fact->value->conversionVersion);
    }

    /**
     * Eval: unrecognized-status visible exclusion. CCP-004's LDL draw with
     * result_status='amended'.
     */
    public function testUnrecognizedStatusVisibleExclusion(): void
    {
        $pid = $this->pidFor('CCP-004');

        $result = $this->reader->read($pid, [self::LOINC_LDL], Capability::ControlProxy, '1');

        self::assertCount(0, $result->presented, 'an amended-status result must never be presented');
        self::assertCount(1, $result->exclusions);
        $exclusion = $result->exclusions[0];
        self::assertTrue($exclusion->hasFlag(Flag::excludedReason(ExclusionReason::UnrecognizedStatus)));
        self::assertSame(135.0, $exclusion->value?->parsed, 'the value itself was valid -- only the status caused exclusion');
    }
}
