<?php

/**
 * ChatAgent::answer()'s final, single outcome -- the chat-path analogue of VerifiedGenerationResult.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\RedactionMap;
use OpenEMR\Modules\ClinicalCopilot\Verify\ReduceUsage;
use OpenEMR\Modules\ClinicalCopilot\Verify\Sev1Signal;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verdict;

/**
 * Deliberately mirrors {@see \OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGenerationResult}'s
 * shape and states (`Passed`/`Degraded`/`frozen`) -- reusing its enums
 * ({@see VerifyStatus}) and DTOs ({@see Verdict}, {@see ReduceUsage},
 * {@see Sev1Signal}) so a chat turn's verdict is recorded on
 * `mod_copilot_chat_turn` with EXACTLY the same vocabulary a synthesis
 * attempt is recorded with on `mod_copilot_doc` -- one dashboard metric
 * (verification pass/fail rate, ARCHITECTURE.md §3.3) covers both surfaces
 * without a translation layer.
 *
 * It is a SEPARATE class, not a reuse of `VerifiedGenerationResult` itself,
 * because a chat turn carries fields that outcome has no place for: the tool
 * calls this turn made ({@see ToolCallLogEntry}, for the ledger's `tool_calls`
 * column and the user-visible tool-failure banner) and the full accumulated
 * fact set (for {@see ChatFactSetBuilder} to persist forward into the next
 * turn).
 */
final readonly class ChatAnswer
{
    /**
     * @param list<Claim>|null $claims
     * @param list<Verdict> $verdicts
     * @param list<ToolCallLogEntry> $toolCallLog
     * @param list<Fact> $accumulatedFacts
     */
    private function __construct(
        public VerifyStatus $verifyStatus,
        public ?array $claims,
        public array $verdicts,
        public int $attempts,
        public bool $frozen,
        public ?Sev1Signal $sev1Signal,
        public ?string $degradedReason,
        public ?string $degradedMessage,
        public ?RedactionMap $redactionMap,
        public ReduceUsage $usage,
        public array $toolCallLog,
        public array $accumulatedFacts,
    ) {
    }

    /**
     * @param list<Claim> $claims
     * @param list<Verdict> $verdicts
     */
    public static function passed(AgentLoopResult $loopResult, array $claims, array $verdicts, ReduceUsage $usage, int $attempts): self
    {
        return new self(
            VerifyStatus::Passed,
            $claims,
            $verdicts,
            $attempts,
            false,
            null,
            null,
            null,
            $loopResult->redactionMap,
            $usage,
            $loopResult->toolCallLog,
            $loopResult->accumulatedFacts,
        );
    }

    /**
     * @param list<Fact> $accumulatedFacts
     * @param list<ToolCallLogEntry> $toolCallLog
     */
    public static function degradedLlmUnavailable(array $accumulatedFacts, string $reason, array $toolCallLog = [], ?string $degradedMessage = null): self
    {
        return new self(
            VerifyStatus::Degraded,
            null,
            [],
            1,
            false,
            null,
            'llm_unavailable',
            $degradedMessage ?? 'narrative unavailable -- the assistant is temporarily unreachable; the chart is still current',
            null,
            ReduceUsage::none(),
            $toolCallLog,
            $accumulatedFacts,
        );
    }

    /**
     * @param list<Verdict> $verdicts
     */
    public static function degradedVerificationFailed(AgentLoopResult $loopResult, array $verdicts, ReduceUsage $usage, int $attempts): self
    {
        return new self(
            VerifyStatus::Degraded,
            null,
            $verdicts,
            $attempts,
            false,
            null,
            'verification_failed',
            "couldn't produce a verifiable answer",
            $loopResult->redactionMap,
            $usage,
            $loopResult->toolCallLog,
            $loopResult->accumulatedFacts,
        );
    }

    /**
     * @param list<Verdict> $verdicts
     */
    public static function frozen(AgentLoopResult $loopResult, array $verdicts, Sev1Signal $signal, ReduceUsage $usage, int $attempts): self
    {
        return new self(
            VerifyStatus::Degraded,
            null,
            $verdicts,
            $attempts,
            true,
            $signal,
            'patient_identity_sev1',
            "couldn't produce a verifiable answer",
            null,
            $usage,
            $loopResult->toolCallLog,
            $loopResult->accumulatedFacts,
        );
    }

    /**
     * The circuit-breaker-open / rate-limited path never reaches
     * {@see \OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop} at all
     * (ARCHITECTURE.md §3.7: "skip the LLM entirely") -- this factory
     * produces the same observable shape as an LLM-unavailable degrade
     * without ever constructing an {@see AgentLoopResult}.
     *
     * @param list<Fact> $accumulatedFacts
     */
    public static function degradedBreakerOpen(array $accumulatedFacts): self
    {
        return new self(
            VerifyStatus::Degraded,
            null,
            [],
            0,
            false,
            null,
            'circuit_breaker_open',
            'the assistant is temporarily unavailable (spend limit reached) -- the chart is still current',
            null,
            ReduceUsage::none(),
            [],
            $accumulatedFacts,
        );
    }
}
