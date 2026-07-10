<?php

/**
 * Lab contract C2: supersession within (patient, result_code, clinical date).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

/**
 * Pure, DB-independent. `corrected > final > '' > preliminary`; ties break
 * on the highest `procedure_result_id`. Covers both correction variants
 * uniformly: an in-place correction (one row, `result_status` UPDATEd) never
 * reaches this resolver with more than one candidate (there is only ever one
 * physical row), while a new-row correction hands this resolver two rows
 * sharing (patient, result_code, clinical_date).
 */
final class SupersessionResolver
{
    private function __construct()
    {
        // static-only
    }

    /**
     * @param list<SupersessionCandidate> $candidates all rows sharing the same (patient, result_code, clinical_date) group; must be non-empty
     */
    public static function resolve(array $candidates): SupersessionResult
    {
        if ($candidates === []) {
            throw new \DomainException('SupersessionResolver::resolve requires at least one candidate');
        }

        $sorted = $candidates;
        usort(
            $sorted,
            static fn (SupersessionCandidate $a, SupersessionCandidate $b): int =>
                [$b->supersessionRank, $b->procedureResultId] <=> [$a->supersessionRank, $a->procedureResultId]
        );

        $winner = $sorted[0];
        $superseded = array_map(
            static fn (SupersessionCandidate $c): int => $c->procedureResultId,
            array_slice($sorted, 1),
        );

        return new SupersessionResult($winner->procedureResultId, $superseded);
    }
}
