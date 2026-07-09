<?php

/**
 * What AgentLoop::run() hands back: a candidate final claims JSON plus everything spent getting there.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Reduce\RedactionMap;

/**
 * `finalClaimsJson` is UNVERIFIED and still egress-redacted (tokenized) --
 * {@see \OpenEMR\Modules\ClinicalCopilot\Chat\ChatAgent} is the only caller,
 * and it is the one place that runs {@see \OpenEMR\Modules\ClinicalCopilot\Verify\Verifier}
 * over this string and only rehydrates identifiers into the claim text AFTER
 * that gate passes (ARCHITECTURE.md §4: "called AFTER verification ... never
 * on anything shown to the model"). `accumulatedFacts` is the full session
 * fact set INCLUDING every tool result this call made -- exactly what
 * {@see \OpenEMR\Modules\ClinicalCopilot\Verify\SessionFactSet} must be built
 * from for V2/V3/V4 to resolve every citation this answer could possibly
 * make.
 */
final readonly class AgentLoopResult
{
    /**
     * @param list<Fact> $accumulatedFacts
     * @param list<ToolCallLogEntry> $toolCallLog
     */
    public function __construct(
        public string $finalClaimsJson,
        public RedactionMap $redactionMap,
        public array $accumulatedFacts,
        public array $toolCallLog,
        public int $tokensIn,
        public int $tokensOut,
        public int $latencyMs,
        public string $modelVersion,
        public bool $budgetExhausted,
    ) {
    }

    /**
     * The verify-then-retry pass (AgentLoop::answerWithFindings) makes no new
     * tool calls, so its own toolCallLog is empty; the turn's real tool calls
     * happened on the first attempt. This wither lets ChatAgent carry the
     * first attempt's log onto the retry result so a retried turn still
     * persists its Tool rows / trace spans and the fetched facts survive into
     * the next turn's rebuilt fact set.
     *
     * @param list<ToolCallLogEntry> $toolCallLog
     */
    public function withToolCallLog(array $toolCallLog): self
    {
        return new self(
            $this->finalClaimsJson,
            $this->redactionMap,
            $this->accumulatedFacts,
            $toolCallLog,
            $this->tokensIn,
            $this->tokensOut,
            $this->latencyMs,
            $this->modelVersion,
            $this->budgetExhausted,
        );
    }
}
