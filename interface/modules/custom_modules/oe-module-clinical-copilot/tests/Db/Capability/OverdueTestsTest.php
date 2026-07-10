<?php

/**
 * DB-backed U5 acceptance evals: OverdueTests against the U2 seeded patients.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Capability;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Capability\OverdueTests;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use PHPUnit\Framework\TestCase;

/**
 * Requires the U2 seed and U5's cadence/threshold config rows. Cross-checked
 * against tests/Seed/fixtures/expected/landmines.json's
 * `overdue_acr_no_pending_order` (CCP-001) and
 * `overdue_acr_with_pending_order_reorder_suppression` (CCP-002) landmines.
 */
final class OverdueTestsTest extends TestCase
{
    private OverdueTests $overdueTests;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $reader = new LabSliceReader(new DbLabContractConfigProvider());
        $this->overdueTests = new OverdueTests($reader, new DbLabContractConfigProvider(), ServiceContainer::getClock());
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
     * Eval: CCP-001's ACR, last drawn 14 months ago (cadence P1Y, so overdue
     * by ~2 months) with NO pending order, produces an `overdue_item` Fact
     * citing ONLY the last-draw result -- no procedure_order citation, so no
     * reorder-suppression claim is citable.
     */
    public function testAcrOverdueNoPendingOrder(): void
    {
        $pid = $this->pidFor('CCP-001');

        $result = $this->overdueTests->extract($pid);

        $overdue = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::OverdueItem));
        self::assertCount(1, $overdue, 'ACR is the only overdue code seeded for CCP-001');

        $tables = array_map(static fn ($c) => $c->table, $overdue[0]->citations);
        self::assertSame(['procedure_result'], array_values(array_unique($tables)), 'no pending order exists -- the overdue_item must not carry a procedure_order citation');
    }

    /**
     * Eval: CCP-002's ACR, last drawn 13 months ago, WITH an active pending
     * order (drawn 2 days ago) composes into an `overdue_item` Fact whose
     * citations include BOTH the last-draw result AND the pending order --
     * this is the "cite BOTH sides" mechanism the reorder-suppression note
     * downstream depends on.
     */
    public function testAcrOverdueWithPendingOrderCitesBothSides(): void
    {
        $pid = $this->pidFor('CCP-002');

        $result = $this->overdueTests->extract($pid);

        $overdue = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::OverdueItem));
        self::assertCount(1, $overdue, 'ACR is the only overdue code seeded for CCP-002');

        $tables = array_map(static fn ($c) => $c->table, $overdue[0]->citations);
        self::assertContains('procedure_result', $tables);
        self::assertContains('procedure_order', $tables, 'an active pending order exists -- the overdue_item must cite it too, proving the suppression note');
    }

    /**
     * I14 conservation eval: a garbage `result_status` row on a
     * cadence-governed code must never silently vanish from OverdueTests'
     * own accounting either (it shares LabSliceReader's per-row tally).
     */
    public function testConservationGarbageStatusRowNeverVanishes(): void
    {
        $pid = $this->pidFor('CCP-001');
        $loinc = '2093-3'; // in the lipid panel -- cadence-governed
        $date = (new \DateTimeImmutable('-1 month'))->format('Y-m-d H:i:s');

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

        $result = $this->overdueTests->extract($pid);

        self::assertSame(0, $result->unaccountedCount());
        $garbage = array_values(array_filter(
            $result->exclusions,
            static fn ($f) => $f->hasFlag(Flag::excludedReason(ExclusionReason::UnrecognizedStatus)),
        ));
        self::assertNotEmpty($garbage);
    }
}
