<?php

/**
 * Fail-closed chat turn: agent loop -> verify -> one retry -> degrade (I11).
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
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Verify\CheckId;
use OpenEMR\Modules\ClinicalCopilot\Verify\ReduceUsage;
use OpenEMR\Modules\ClinicalCopilot\Verify\Sev1Signal;
use OpenEMR\Modules\ClinicalCopilot\Verify\SessionFactSet;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationContext;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPath;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verdict;

/**
 * The chat-path analogue of U10's {@see \OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGeneration},
 * built as its OWN class rather than a direct reuse of that one because
 * `VerifiedGeneration` is wired concretely to
 * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer} (itself wired
 * concretely to {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler}),
 * neither of which has a seam for conversation history or tool
 * declarations -- there is no injection point to hand it {@see AgentLoop}'s
 * multi-round, tool-calling generation instead of a one-shot reduce call.
 * This class reuses everything that COULD be reused: U10's own
 * {@see Verifier} directly (unmodified, `VerificationPath::Chat` selecting
 * V6(i)-only per ARCHITECTURE.md §2.2), the same {@see Sev1Signal}/
 * {@see ReduceUsage}/{@see Verdict} DTOs, and implements the IDENTICAL
 * fail-closed policy `VerifiedGeneration::generate()` documents
 * (ARCHITECTURE.md §2.3): attempt 1 -> verify; LLM unavailable -> degrade
 * (I6); V3 fail -> sev-1, discard, freeze, alert, NEVER retried; all six
 * pass -> done; any other failure -> ONE regeneration with findings
 * appended -> verify again -> same three outcomes, but a second ordinary
 * failure degrades instead of retrying again.
 *
 * The one deliberate difference from the synthesis retry: this class's
 * retry calls {@see AgentLoop::answerWithFindings()} (no new tool access,
 * same accumulated facts) rather than restarting the whole tool-calling
 * loop -- re-running the full agent loop on a retry would let it spend a
 * SECOND full chaining budget on top of the first, which the 5-call/3-round
 * caps exist specifically to prevent.
 */
final class ChatAgent
{
    /**
     * @param (\Closure(string): void)|null $onStatus optional staged-status callback (see {@see AgentLoop}'s own docblock)
     */
    public function __construct(
        private readonly AgentLoop $agentLoop,
        private readonly Verifier $verifier,
        private readonly ?\Closure $onStatus = null,
    ) {
    }

    private function emitStatus(string $message): void
    {
        if ($this->onStatus !== null) {
            ($this->onStatus)($message);
        }
    }

    /**
     * @param list<Fact> $sessionFacts preloaded facts UNION every tool result from prior turns
     * @param list<Claim>|null $narrativeClaims the doc's own narrative, for context
     * @param list<string> $conversationTranscript pre-rendered prior turns, oldest first
     */
    public function answer(
        int $pid,
        string $correlationId,
        array $sessionFacts,
        ?array $narrativeClaims,
        array $conversationTranscript,
        string $userQuestion,
    ): ChatAnswer {
        try {
            $loopResult = $this->agentLoop->run($sessionFacts, $narrativeClaims, $conversationTranscript, $userQuestion);
        } catch (LlmUnavailableException $e) {
            // TEMPORARY debug aid: surface the rich cause in the return value
            // (not just the physician-safe banner) so "why isn't it answering"
            // is visible straight from the turn response. Revert to
            // $e->degradedMessage() before any real-PHI deployment.
            return ChatAnswer::degradedLlmUnavailable($sessionFacts, $e->reason(), [], $e->detail());
        }

        $this->emitStatus('verifying…');
        $verification = $this->verifier->verify(
            $loopResult->finalClaimsJson,
            new VerificationContext(new SessionFactSet($pid, $loopResult->accumulatedFacts), VerificationPath::Chat),
        );

        if ($verification->hasSev1()) {
            return ChatAnswer::frozen($loopResult, $verification->verdicts, self::sev1Signal($correlationId, $pid, $verification->find(CheckId::PatientIdentity)), self::usage($loopResult), 1);
        }

        if ($verification->allPassed() && ($verification->claims ?? []) !== []) {
            return ChatAnswer::passed($loopResult, $verification->claims ?? [], $verification->verdicts, self::usage($loopResult), 1);
        }

        // ONE regeneration, findings appended, no new tool calls (ARCHITECTURE.md §2.3).
        // An empty claim list trivially "passes" every check (nothing to fail),
        // but an answer with no claims conveys nothing and must never render as a
        // verified turn -- treat it like any other ordinary failure and hand the
        // retry an explicit no-claims finding (formatFindings yields nothing when
        // no verdict actually failed).
        $this->emitStatus('resolving verification findings…');
        $findings = self::formatFindings($verification->verdicts);
        if (trim($findings) === '') {
            $findings = '[empty_answer] The previous response contained no claims. Answer the question with grounded, cited claims, or return an explicit uncertainty or refusal claim if you cannot.';
        }
        try {
            $retryResult = $this->agentLoop->answerWithFindings(
                $loopResult->accumulatedFacts,
                $narrativeClaims,
                $conversationTranscript,
                $userQuestion,
                $findings,
            );
        } catch (LlmUnavailableException $e) {
            // TEMPORARY debug aid (see the first catch above): rich cause in the
            // return value; revert to $e->degradedMessage() before real-PHI.
            return ChatAnswer::degradedLlmUnavailable($loopResult->accumulatedFacts, $e->reason(), $loopResult->toolCallLog, $e->detail());
        }

        // The retry makes no new tool calls, so carry the first attempt's tool
        // log onto the retry result -- otherwise a retried turn persists zero
        // Tool rows/trace spans and the facts it fetched vanish from the next
        // turn's rebuilt fact set (ChatFactSetBuilder reads only Tool turns).
        $retryResult = $retryResult->withToolCallLog(
            array_merge($loopResult->toolCallLog, $retryResult->toolCallLog),
        );

        $this->emitStatus('verifying…');
        $retryVerification = $this->verifier->verify(
            $retryResult->finalClaimsJson,
            new VerificationContext(new SessionFactSet($pid, $retryResult->accumulatedFacts), VerificationPath::Chat),
        );

        if ($retryVerification->hasSev1()) {
            return ChatAnswer::frozen($retryResult, $retryVerification->verdicts, self::sev1Signal($correlationId, $pid, $retryVerification->find(CheckId::PatientIdentity)), self::usage($retryResult), 2);
        }

        if ($retryVerification->allPassed() && ($retryVerification->claims ?? []) !== []) {
            return ChatAnswer::passed($retryResult, $retryVerification->claims ?? [], $retryVerification->verdicts, self::usage($retryResult), 2);
        }

        // Still empty (or still failing) after the one retry -- degrade rather
        // than render an empty answer as verified.
        return ChatAnswer::degradedVerificationFailed($retryResult, $retryVerification->verdicts, self::usage($retryResult), 2);
    }

    private static function usage(AgentLoopResult $loopResult): ReduceUsage
    {
        return new ReduceUsage($loopResult->tokensIn, $loopResult->tokensOut, $loopResult->latencyMs, $loopResult->modelVersion);
    }

    private static function sev1Signal(string $correlationId, int $pid, ?Verdict $patientIdentityVerdict): Sev1Signal
    {
        return new Sev1Signal($correlationId, $pid, $patientIdentityVerdict?->findings ?? [], new \DateTimeImmutable());
    }

    /**
     * @param list<Verdict> $verdicts
     */
    private static function formatFindings(array $verdicts): string
    {
        $lines = [];
        foreach ($verdicts as $verdict) {
            if ($verdict->passed || $verdict->skipped) {
                continue;
            }
            foreach ($verdict->findings as $finding) {
                $lines[] = "[{$verdict->checkId->value}] {$finding}";
            }
        }

        return implode("\n", $lines);
    }
}
