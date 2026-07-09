<?php

/**
 * Versioned lab-turnaround configuration for PendingResults' expected_result_date.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability\Config;

/**
 * Backs the `mod_copilot_cadence` `lab_turnaround` config row (already
 * seeded by U1/table.sql:
 * `{"default_days":3,"per_analyte_days":{"a1c":2,"acr":3,"lipids":2}}`).
 * `perAnalyteDaysByBucket` is keyed by the SAME cadence-bucket granularity as
 * {@see AnalyteCodeSets::cadenceBucketForLoinc()} (`a1c`/`acr`/`lipids`), not
 * the finer unit-conversion buckets.
 *
 * IMPORTANT for U7/U8: `version` is a digest input the SAME way cadence and
 * threshold versions are (ARCHITECTURE_COMPLETE.md "Compute model" /
 * `Digest::compute()`'s `$configVersions` map) -- whoever assembles the full
 * digest must fold this version in under its own key (e.g.
 * `lab_turnaround`), or a turnaround-days config bump would silently fail to
 * invalidate existing docs carrying `expected_result_date` facts (E5).
 */
final readonly class LabTurnaroundConfig
{
    /**
     * @param array<string, int> $perAnalyteDaysByBucket cadence-bucket key (a1c/acr/lipids) => expected turnaround days
     */
    public function __construct(
        public int $defaultDays,
        public array $perAnalyteDaysByBucket,
        public string $version,
    ) {
    }

    public function daysForBucket(?string $bucket): int
    {
        if ($bucket === null) {
            return $this->defaultDays;
        }

        return $this->perAnalyteDaysByBucket[$bucket] ?? $this->defaultDays;
    }
}
