<?php

/**
 * Isolated evals for FactRekind: re-kinding recomputes fact_id, preserves everything else.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Capability;

use OpenEMR\Modules\ClinicalCopilot\Capability\Support\FactRekind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use PHPUnit\Framework\TestCase;

final class FactRekindTest extends TestCase
{
    /**
     * Eval: re-kinding a `result` fact into `trend_point` changes fact_id
     * (kind is a FactId input) but preserves pid/date/value/status/citations.
     */
    public function testRekindChangesFactIdButPreservesEverythingElse(): void
    {
        $original = CapabilityFactTestFactory::trendPoint(1, 1, 7.2, '2025-07-07');
        // Build the "result"-kind precursor U4 would have handed back, with
        // the SAME citations/value/status -- only kind differs.
        $resultKind = FactId::compute(
            $original->capability,
            FactKind::Result,
            $original->citations,
            $original->value,
        );
        $precursor = new Fact(
            $resultKind,
            $original->capability,
            $original->capabilityVersion,
            FactKind::Result,
            $original->pid,
            $original->clinicalDate,
            $original->dateSource,
            $original->value,
            $original->status,
            $original->flags,
            $original->citations,
        );

        $rekinded = FactRekind::withKind($precursor, FactKind::TrendPoint);

        self::assertSame(FactKind::TrendPoint, $rekinded->kind);
        self::assertNotSame($precursor->factId, $rekinded->factId, 'changing kind must mint a new fact_id');
        self::assertSame($original->factId, $rekinded->factId, 'a TrendPoint built directly and one re-kinded from an equivalent Result must produce the SAME fact_id (fact_id depends only on capability/kind/citations/value)');
        self::assertSame($precursor->pid, $rekinded->pid);
        self::assertSame($precursor->clinicalDate, $rekinded->clinicalDate);
        self::assertSame($precursor->value, $rekinded->value);
        self::assertSame($precursor->status, $rekinded->status);
        self::assertSame($precursor->citations, $rekinded->citations);
    }
}
