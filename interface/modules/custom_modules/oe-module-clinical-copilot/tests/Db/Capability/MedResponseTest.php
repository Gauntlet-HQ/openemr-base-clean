<?php

/**
 * DB-backed U5 acceptance evals: MedResponse against the U2 seeded patients.
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
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedResponse;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use OpenEMR\Services\PrescriptionService;
use PHPUnit\Framework\TestCase;

/**
 * Requires the U2 seed. Cross-checked against
 * tests/Seed/fixtures/expected/landmines.json's
 * `med_dose_vs_response_mismatch` (CCP-001) and
 * `outside_and_in_house_med_union` (CCP-004) landmines.
 */
final class MedResponseTest extends TestCase
{
    private MedResponse $medResponse;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $reader = new LabSliceReader(new DbLabContractConfigProvider());
        $this->medResponse = new MedResponse(new PrescriptionService(), $reader);
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
     * Eval: CCP-001's metformin dose increase (500mg -> 1000mg) is detected
     * as a regimen CHANGE (the later of two same-drug-key rows) and its
     * med_event Fact cites BOTH the prescription row AND the subsequent A1c
     * draw -- the baseline (first, 500mg) row does not.
     */
    public function testDoseChangePairsWithSubsequentA1cCitingBothSides(): void
    {
        $pid = $this->pidFor('CCP-001');

        $result = $this->medResponse->extract($pid);

        $medEvents = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::MedEvent));
        self::assertCount(2, $medEvents, 'CCP-001 seeds two metformin prescription rows (baseline + dose increase)');

        usort($medEvents, static fn ($a, $b) => $a->clinicalDate <=> $b->clinicalDate);
        [$baseline, $change] = $medEvents;

        self::assertCount(1, $baseline->citations, 'the baseline (first) entry in a drug-name group is not itself a detected change');
        self::assertGreaterThan(1, count($change->citations), 'the later (dose-increase) entry must cite subsequent A1c movement in addition to its own row');

        $tables = array_map(static fn ($c) => $c->table, $change->citations);
        self::assertContains('prescriptions', $tables);
        self::assertContains('procedure_result', $tables, 'the dose-change fact must cite lab evidence too (never asserting causation, only juxtaposing)');
    }

    /**
     * Eval: CCP-004's outside/reconciled atorvastatin (`lists`) and in-house
     * lisinopril (`prescriptions`) both present via the host union (T4),
     * source-tagged by status: unstated (as-reported) vs. final
     * (authoritative in-house order).
     */
    public function testUnionOfBothMedSourcesSourceTagged(): void
    {
        $pid = $this->pidFor('CCP-004');

        $result = $this->medResponse->extract($pid);

        $medEvents = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::MedEvent));
        self::assertCount(2, $medEvents, 'CCP-004 seeds one outside med and one in-house Rx');

        $bySource = [];
        foreach ($medEvents as $fact) {
            $bySource[$fact->citations[0]->table][] = $fact;
        }

        self::assertArrayHasKey('lists', $bySource, 'the outside/reconciled med must be present');
        self::assertArrayHasKey('prescriptions', $bySource, 'the in-house Rx must be present');
        self::assertSame(FactStatus::Unstated, $bySource['lists'][0]->status);
        self::assertSame(FactStatus::Final, $bySource['prescriptions'][0]->status);
    }

    /**
     * I14 conservation eval: a prescription row this capability cannot
     * resolve a trusted clinical date for (NULL start_date) must never be
     * silently folded into the presented set -- it is left unaccounted
     * (surfacing as `unaccountedCount() > 0`), never fabricated with a
     * guessed date.
     */
    public function testConservationUnresolvableStartDateTripsUnaccounted(): void
    {
        $pid = $this->pidFor('CCP-001');

        $before = $this->medResponse->extract($pid);

        $uuid = (new UuidRegistry(['table_name' => 'prescriptions']))->createUuid();
        QueryUtils::sqlInsert(
            'INSERT INTO `prescriptions`
                (`uuid`, `patient_id`, `provider_id`, `start_date`, `end_date`, `drug`, `dosage`, `active`, `date_added`, `datetime`, `txDate`,
                 `usage_category`, `usage_category_title`, `request_intent`, `request_intent_title`)
             VALUES (?, ?, 1, NULL, NULL, ?, ?, 1, NOW(), NOW(), NOW(), \'community\', \'Home/Community\', \'order\', \'Order\')',
            [$uuid, $pid, 'ZZZ Unresolvable Test Med', '1 tab daily'],
        );

        $after = $this->medResponse->extract($pid);

        self::assertSame($before->rawInputCount + 1, $after->rawInputCount, 'the new row must be counted as raw input');
        self::assertGreaterThan(0, $after->unaccountedCount(), 'a row with no resolvable clinical date must never be silently folded into the accounted set');
        self::assertSame(
            count($before->presented),
            count($after->presented),
            'the unresolvable row must add zero presented med_event facts -- it is neither presented nor fabricated, only left unaccounted',
        );
    }
}
