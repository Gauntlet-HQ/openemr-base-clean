<?php

/**
 * The output of processing a raw lab slice: presented facts + exclusion facts.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

/**
 * I5: every filtered row is accounted for in `exclusions` -- nothing is
 * silently dropped between the raw join and this result.
 *
 * I14 (extraction conservation / parse-yield telemetry, docs/build-notes.md):
 * `rawInputCount` is the number of raw join rows {@see LabRowProcessor::process()}
 * received; `accountedCount` is the number of those rows it independently
 * tallied as classified into either a presented group or an exclusion (NOT
 * derived from `count($presented) + count($exclusions)` -- a supersession
 * group folds N physical rows into ONE presented Fact, so that arithmetic
 * would falsely flag a healthy correction as "unaccounted"). Callers
 * (U5 capabilities) aggregate these two counts across their per-code reads
 * into their own `CapabilityResult.rawInputCount`/`accountedCount`.
 * `rawInputCount == accountedCount` for every LabRowProcessor::process() call
 * today; a future code path that classifies a row into neither bucket would
 * make them diverge, which is exactly the regression I14 exists to catch.
 */
final readonly class LabSliceResult
{
    /**
     * @param list<PresentedLabFact> $presented
     * @param list<Fact> $exclusions
     */
    public function __construct(
        public array $presented,
        public array $exclusions,
        public int $rawInputCount = 0,
        public int $accountedCount = 0,
    ) {
    }
}
