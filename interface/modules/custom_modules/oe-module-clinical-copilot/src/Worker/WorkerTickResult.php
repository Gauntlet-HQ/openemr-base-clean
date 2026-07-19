<?php

/**
 * What one Worker::runTick() call did -- for logging and tests, not persisted.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Worker;

use OpenEMR\Modules\ClinicalCopilot\Observability\Alert\AlertFinding;
use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaSweepSummary;

/**
 * I7 (worker failure degrades latency only, never correctness): every stage
 * has its own `*Ok` flag rather than one all-or-nothing success bit, because
 * {@see \OpenEMR\Modules\ClinicalCopilot\Worker::runTick()} deliberately runs
 * every stage even when an earlier one throws (see that method's docblock).
 * A test or a dashboard reading this back can see exactly which stage(s)
 * degraded on a given tick.
 */
final readonly class WorkerTickResult
{
    /**
     * @param list<AlertFinding> $alertFindings
     */
    public function __construct(
        public bool $heartbeatOk,
        public bool $warmOk,
        public int $warmedCount,
        public int $warmSkippedForBudgetOrBreaker,
        public bool $qaSweepOk,
        public ?QaSweepSummary $qaSweepSummary,
        public bool $qaRerunOk,
        public int $qaRerunsEnqueued,
        public bool $alertEvalOk,
        public array $alertFindings,
    ) {
    }
}
