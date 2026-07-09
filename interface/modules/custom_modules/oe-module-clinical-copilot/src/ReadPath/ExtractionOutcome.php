<?php

/**
 * The result of running all five capabilities' extract() for one patient.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

/**
 * Two shapes, both explicit (never a silent partial result):
 * {@see self::success()} carries the full accounted fact set (presented ∪
 * exclusions, across all five capabilities) plus every capability's version
 * (ALL five, per {@see ConfigVersionSnapshot}'s reasoning -- a digest input
 * even for a capability that contributed zero facts). {@see self::crashed()}
 * is the capability-crash rule (ARCHITECTURE.md §6.1): one or more
 * capabilities threw during extraction, so NO digest may ever be computed
 * over this outcome -- {@see SynthesisReadPath} checks {@see self::$crashed}
 * before it ever calls {@see \OpenEMR\Modules\ClinicalCopilot\Fact\Digest::compute()}.
 * `survivingFacts` is what the crashed-path banner still renders (I5's
 * spirit extended to a capability-level failure: partial data, clearly
 * labeled, beats a silently-incomplete narrative).
 */
final readonly class ExtractionOutcome
{
    /**
     * @param list<Fact> $survivingFacts
     * @param array<string, string> $capabilityVersions
     * @param array<string, int> $excludedCounts per-capability `{capability}_excluded`/`{capability}_unaccounted` counts (I5/I14) -- empty on the crashed path
     * @param list<CapabilityExtractionFailure> $failures
     */
    private function __construct(
        public bool $crashed,
        public array $survivingFacts,
        public array $capabilityVersions,
        public array $excludedCounts,
        public array $failures,
    ) {
    }

    /**
     * @param list<Fact> $allFacts
     * @param array<string, string> $capabilityVersions
     * @param array<string, int> $excludedCounts
     */
    public static function success(array $allFacts, array $capabilityVersions, array $excludedCounts): self
    {
        return new self(false, $allFacts, $capabilityVersions, $excludedCounts, []);
    }

    /**
     * @param list<Fact> $survivingFacts
     * @param list<CapabilityExtractionFailure> $failures
     */
    public static function crashed(array $survivingFacts, array $failures): self
    {
        return new self(true, $survivingFacts, [], [], $failures);
    }

    /**
     * The physician-facing banner text (ARCHITECTURE.md §6.1's own example:
     * "VitalsTrend unavailable -- synthesis paused"), naming every crashed
     * capability, not just the first.
     */
    public function crashBanner(): string
    {
        $labels = array_map(
            static fn (CapabilityExtractionFailure $f): string => $f->capability->value,
            $this->failures,
        );

        return implode(', ', $labels) . ' unavailable -- synthesis paused';
    }
}
