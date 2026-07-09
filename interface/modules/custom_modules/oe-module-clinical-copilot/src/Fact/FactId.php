<?php

/**
 * Computes the content-addressed `fact_id`.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;

/**
 * `fact_id = hash(capability, kind, citations, canonical value)`
 * (ARCHITECTURE_COMPLETE.md "Fact object"). Deliberately EXCLUDES pid,
 * capability_version, clinical_date, date_source, status, and flags:
 *
 * - pid is implied by the cited rows themselves (a citation's (table, pk)
 *   already belongs to exactly one patient), so it adds no distinguishing
 *   power and would be redundant with I10's separate pid assertion.
 * - capability_version, status, and flags are metadata *about* the same
 *   underlying datum -- a config/threshold-version bump or a supersession
 *   flag flip must NOT mint a new fact_id for what is still "the same fact",
 *   or every version bump would silently orphan citations that reference the
 *   old id (V2 would stop resolving them).
 * - value IS included so a corrected re-fetch of the same (capability, kind,
 *   citations) datum with a different value never collides with the
 *   preloaded one (T19) -- this is the one field that must break identity
 *   when the datum itself changes.
 *
 * Pure and deterministic: sha256 over the canonical serialization of
 * {capability, kind, citations (sorted), value}. Citations are sorted before
 * hashing so citation-collection order never perturbs the id.
 */
final class FactId
{
    private function __construct()
    {
        // static-only
    }

    /**
     * @param list<Citation> $citations
     */
    public static function compute(
        Capability $capability,
        FactKind $kind,
        array $citations,
        ?FactValue $value,
    ): string {
        if ($citations === []) {
            throw new \DomainException('FactId::compute requires at least one citation');
        }

        $citationArrays = array_map(static fn (Citation $c): array => $c->toArray(), $citations);
        usort(
            $citationArrays,
            static function (array $a, array $b): int {
                return [$a['table'], $a['pk'], $a['field'] ?? '', $a['date_source']]
                    <=> [$b['table'], $b['pk'], $b['field'] ?? '', $b['date_source']];
            }
        );

        $payload = [
            'capability' => $capability->value,
            'kind' => $kind->value,
            'citations' => $citationArrays,
            'value' => $value?->toArray(),
        ];

        return hash('sha256', CanonicalSerializer::serializeValue($payload));
    }
}
