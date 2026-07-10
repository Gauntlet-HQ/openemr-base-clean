<?php

/**
 * The output of one Capability's extract(): presented facts + exclusion facts.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

/**
 * Mirrors U4's {@see \OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceResult}
 * shape one level up: I5 (no silent exclusion) holds at the capability
 * boundary the same way it holds at the lab-slice boundary -- every row a
 * capability decided not to present is a visible `exclusion` Fact in
 * `$exclusions`, never a silent drop.
 *
 * I14 (extraction conservation / parse-yield telemetry, docs/build-notes.md):
 * `rawInputCount` and `accountedCount` guard the case I5 alone cannot see --
 * a source row dropped BEFORE it was ever classified (schema drift, a join
 * edge, an unmapped shape), so it never became even an exclusion Fact.
 * `rawInputCount` is the number of raw rows this capability's source
 * query/service returned (aggregated across however many internal reads one
 * `extract()` call makes); `accountedCount` is the number of those rows
 * independently tallied as having been classified into a presented Fact or
 * an exclusion Fact (NEVER derived from `count($presented) + count($exclusions)`
 * -- a capability may legitimately fold multiple raw rows into one Fact, e.g.
 * a lab supersession group, or add Facts that do not correspond 1:1 to a raw
 * row at all, e.g. `derived_*` facts). `unaccountedCount()` is the parse-yield
 * shortfall: it must be 0 in a healthy extraction; a positive value does NOT
 * abort synthesis (the accounted Facts are still valid) -- it is a signal
 * for U12's telemetry/alerting to surface loudly, never a thrown exception.
 */
final readonly class CapabilityResult
{
    /**
     * @param list<Fact> $presented
     * @param list<Fact> $exclusions
     */
    public function __construct(
        public array $presented,
        public array $exclusions,
        public int $rawInputCount = 0,
        public int $accountedCount = 0,
    ) {
    }

    /**
     * All Facts this capability produced, presented and excluded alike --
     * the shape U7/U8 fold into the full per-patient fact set before
     * canonicalizing and digesting (ARCHITECTURE_COMPLETE.md "Compute model").
     *
     * @return list<Fact>
     */
    public function allFacts(): array
    {
        return [...$this->presented, ...$this->exclusions];
    }

    /**
     * I14: the parse-yield shortfall. 0 in a healthy extraction; positive
     * means one or more raw rows vanished before classification (never
     * negative by construction -- accountedCount can exceed the number of
     * PRESENTED facts, e.g. via supersession folding, but never exceed the
     * number of raw rows it was tallied against).
     */
    public function unaccountedCount(): int
    {
        return max(0, $this->rawInputCount - $this->accountedCount);
    }
}
