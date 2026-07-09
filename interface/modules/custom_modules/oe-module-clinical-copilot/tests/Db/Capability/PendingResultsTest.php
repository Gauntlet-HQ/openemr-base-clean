<?php

/**
 * DB-backed U5 acceptance evals: PendingResults against the U2 seeded patients.
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
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\DbLabTurnaroundConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Capability\PendingResults;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use PHPUnit\Framework\TestCase;

/**
 * Requires the U2 seed. Cross-checked against
 * tests/Seed/fixtures/expected/landmines.json's `drawn_but_unresulted_order`
 * (CCP-002) and `preliminary_result` (CCP-004) landmines.
 */
final class PendingResultsTest extends TestCase
{
    private PendingResults $pendingResults;
    private LabSliceReader $reader;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->reader = new LabSliceReader(new DbLabContractConfigProvider());
        $this->pendingResults = new PendingResults($this->reader, new DbLabTurnaroundConfigProvider());
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
     * Eval: CCP-002's active, drawn-but-unresulted ACR order becomes a
     * `pending_order` Fact AND an `expected_result_date` Fact derived from
     * the seeded `lab_turnaround` config (acr: 3 days).
     */
    public function testDrawnButUnresultedOrderAndExpectedResultDate(): void
    {
        $pid = $this->pidFor('CCP-002');

        $result = $this->pendingResults->extract($pid);

        $pendingOrders = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::PendingOrder));
        self::assertCount(1, $pendingOrders, 'CCP-002 seeds exactly one drawn-but-unresulted ACR order');
        self::assertSame(FactStatus::Unstated, $pendingOrders[0]->status);

        $expected = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::ExpectedResultDate));
        self::assertCount(1, $expected);
        self::assertNotNull($expected[0]->clinicalDate);
        self::assertNotNull($pendingOrders[0]->clinicalDate);
        $expectedDays = $expected[0]->clinicalDate->diff($pendingOrders[0]->clinicalDate)->days;
        self::assertSame(3, $expectedDays, 'lab_turnaround config seeds acr at 3 days');
    }

    /**
     * Eval: CCP-004's preliminary A1c draw becomes a `preliminary_result`
     * Fact (re-kinded from U4's `result`, value/status/citations preserved),
     * never a trend point, never resetting the overdue clock.
     */
    public function testPreliminaryResultRekindedAndLabeled(): void
    {
        $pid = $this->pidFor('CCP-004');

        $result = $this->pendingResults->extract($pid);

        $preliminary = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::PreliminaryResult));
        self::assertCount(1, $preliminary, 'CCP-004 seeds exactly one preliminary A1c draw');
        self::assertSame(FactStatus::Preliminary, $preliminary[0]->status);
        self::assertEqualsWithDelta(8.2, $preliminary[0]->value?->parsed, 0.01);
    }

    /**
     * I14 conservation eval: an explicitly cancelled pending order must
     * become a visible `exclusion` Fact (never a bare skip) -- verifies the
     * fix that replaced PendingResults' original silent `continue` on
     * terminal order statuses.
     */
    public function testConservationCancelledOrderBecomesVisibleExclusion(): void
    {
        $pid = $this->pidFor('CCP-001');
        $loinc = '2093-3'; // total cholesterol -- unused elsewhere in CCP-001's fixture set
        $date = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');

        $orderId = QueryUtils::sqlInsert(
            'INSERT INTO `procedure_order` (`provider_id`, `patient_id`, `encounter_id`, `date_collected`, `date_ordered`, `order_status`, `activity`, `procedure_order_type`)
             VALUES (1, ?, 0, ?, ?, \'cancelled\', 1, \'laboratory_test\')',
            [$pid, $date, $date],
        );
        QueryUtils::sqlInsert(
            'INSERT INTO `procedure_order_code` (`procedure_order_id`, `procedure_order_seq`, `procedure_code`, `procedure_name`, `procedure_source`)
             VALUES (?, 1, ?, \'Total Cholesterol\', \'1\')',
            [$orderId, $loinc],
        );
        // No procedure_report row -- this is what makes it "pending" (T10) to begin with.

        $result = $this->pendingResults->extract($pid);

        self::assertSame(0, $result->unaccountedCount(), 'a cancelled pending order must be accounted as a visible exclusion, never a silent drop');

        $cancelledExclusion = array_values(array_filter(
            $result->exclusions,
            static fn ($f) => $f->kind === FactKind::Exclusion
                && $f->hasFlag(Flag::excludedReason(ExclusionReason::UnresultedStatus))
                && $f->citations[0]->table === 'procedure_order',
        ));
        self::assertNotEmpty($cancelledExclusion, 'the cancelled order must surface as a visible procedure_order exclusion');

        $pendingOrdersForCode = array_values(array_filter(
            $result->presented,
            static fn ($f) => $f->kind === FactKind::PendingOrder && $f->citations[0]->pk === $orderId,
        ));
        self::assertEmpty($pendingOrdersForCode, 'a cancelled order must never be presented as an active pending_order');
    }
}
