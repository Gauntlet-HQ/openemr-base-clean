<?php

/**
 * Token/latency/model usage carried from one reduce attempt through to VerifiedGenerationResult.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

/**
 * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\ReduceResult} already carries
 * `tokensIn`/`tokensOut`/`latencyMs`/`modelVersion`, but {@see VerifiedGeneration}
 * previously discarded them once a {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\ReduceResult}
 * was consumed into an {@see AttemptOutcome} -- a gap against U8's acceptance
 * criteria (`mod_copilot_doc.llm_latency_ms`/`tokens_in`/`tokens_out` are real
 * columns {@see \OpenEMR\Modules\ClinicalCopilot\Doc\NewDoc} accepts) and
 * against ARCHITECTURE.md §3.3's cost/latency dashboard metrics, which need
 * this data on every attempt, not only on the ones the caller happens to
 * inspect. This DTO threads it through {@see AttemptOutcome} to
 * {@see VerifiedGenerationResult} without U8/U11 ever reaching past
 * {@see VerifiedGeneration} into {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer}
 * directly (that boundary stays exactly as ARCHITECTURE_COMPLETE.md's U10 row
 * describes it).
 *
 * All four fields are null together only for {@see AttemptOutcomeKind::LlmUnavailable}
 * (no response was ever generated, so there is nothing to meter) -- every
 * other outcome kind reflects a real provider call that consumed tokens and
 * time, whether or not verification ultimately passed.
 *
 * `costUsd` is deliberately NOT carried here: no per-model USD pricing table
 * exists anywhere in this module yet, and guessing one would be worse than
 * leaving `mod_copilot_doc.cost_usd` honestly NULL until a real pricing
 * config lands (an accepted, documented scope limitation -- see the U8
 * report).
 */
final readonly class ReduceUsage
{
    public function __construct(
        public ?int $tokensIn,
        public ?int $tokensOut,
        public ?int $latencyMs,
        public ?string $modelVersion,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null, null);
    }
}
