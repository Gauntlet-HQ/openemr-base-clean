<?php

/**
 * One target's outcome from a QaReviewer::sweep() run.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Qa;

use OpenEMR\Modules\ClinicalCopilot\Doc\QaStatus;

/**
 * T22 (docs/build-notes.md "Warm timing + QA-driven rerun"): U9's worker
 * reads this list back from {@see QaReviewer::sweep()} to decide, for each
 * `target_type = doc` outcome, whether `qaStatus === QaStatus::Low` (and the
 * versioned `qa_threshold` config -- already folded into how this class's
 * `qaStatus` was derived) warrants enqueueing ONE bounded regeneration of
 * that `(pid, fact_digest)`, subject to the freshness guard, the 2-per-digest
 * cap, and the breaker (all T22 rules U9 owns, not this class). This class
 * intentionally carries `factDigest` so the worker never has to re-query
 * `mod_copilot_doc` just to learn which digest a `doc_id` belongs to.
 */
final readonly class QaSweepOutcome
{
    public function __construct(
        public QaTargetType $targetType,
        public int $targetId,
        public int $pid,
        public ?string $factDigest,
        public string $status,
        public ?float $qaScore,
        public QaStatus $qaStatus,
        public ?bool $concurs,
        public ?bool $salienceOk,
    ) {
    }
}
