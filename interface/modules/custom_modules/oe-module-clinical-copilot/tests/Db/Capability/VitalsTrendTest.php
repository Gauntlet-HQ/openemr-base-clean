<?php

/**
 * DB-backed U5 acceptance evals: VitalsTrend against the U2 seeded patients.
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
use OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use PHPUnit\Framework\TestCase;

/**
 * Requires the U2 seed. CCP-001 seeds exactly one `form_vitals` row (weight
 * + BP, no BMI), so this suite exercises the "flagged value must exist in
 * the row" invariant and the single-point (no delta/span, count-only) case.
 */
final class VitalsTrendTest extends TestCase
{
    private VitalsTrend $vitalsTrend;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->vitalsTrend = new VitalsTrend();
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
     * Eval: CCP-001's single form_vitals row (weight + BP, no BMI) produces
     * exactly one weight vital and one BP vital -- no BMI fact (invariant:
     * "flagged value must exist in the row") -- and, with only one point in
     * the weight series, a derived_count but no derived_delta/span.
     */
    public function testSingleVitalsRowNoBmiNoDeltaOnlyCount(): void
    {
        $pid = $this->pidFor('CCP-001');

        $result = $this->vitalsTrend->extract($pid);

        $weightFacts = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::Vital && $f->value?->unitOriginal === 'lb'));
        self::assertCount(1, $weightFacts);
        self::assertEqualsWithDelta(88.5, $weightFacts[0]->value?->parsed, 0.01);

        $bpFacts = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::Vital && $f->value?->unitOriginal === 'mmHg'));
        self::assertCount(1, $bpFacts);
        self::assertSame('132/84', $bpFacts[0]->value?->raw);
        self::assertNull($bpFacts[0]->value?->parsed, 'a composite BP reading makes no single numeric claim');

        $bmiFacts = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::Vital && $f->value?->unitOriginal === ''));
        self::assertCount(0, $bmiFacts, 'no BMI was recorded on the seeded row -- invariant: a flagged value must exist in the row');

        self::assertCount(0, array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::DerivedDelta)), 'a single-point series has no delta to compute');
        self::assertCount(0, array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::DerivedSpan)));

        $counts = array_values(array_filter($result->presented, static fn ($f) => $f->kind === FactKind::DerivedCount));
        self::assertCount(1, $counts, 'exactly one derived_count, for the weight series (BMI series is empty -> no count fact)');
        self::assertSame(1.0, $counts[0]->value?->parsed);
    }

    /**
     * I14 conservation eval: a form_vitals row this capability cannot date
     * (`date` NULL) must never be silently folded in -- it trips
     * `unaccountedCount() > 0` rather than being fabricated with a guessed
     * date or silently skipped without a trace.
     */
    public function testConservationUndatedRowTripsUnaccounted(): void
    {
        $pid = $this->pidFor('CCP-001');

        $before = $this->vitalsTrend->extract($pid);

        $uuid = (new UuidRegistry(['table_name' => 'form_vitals']))->createUuid();
        QueryUtils::sqlInsert(
            'INSERT INTO `form_vitals` (`uuid`, `date`, `pid`, `activity`, `weight`) VALUES (?, NULL, ?, 1, ?)',
            [$uuid, $pid, 999.0],
        );

        $after = $this->vitalsTrend->extract($pid);

        self::assertSame($before->rawInputCount + 1, $after->rawInputCount);
        self::assertGreaterThan(0, $after->unaccountedCount(), 'an undated form_vitals row must never be silently folded into the accounted set');

        $suspiciousWeight = array_values(array_filter($after->presented, static fn ($f) => $f->value?->parsed === 999.0));
        self::assertEmpty($suspiciousWeight, 'the undated row must never surface as a presented vital');
    }
}
