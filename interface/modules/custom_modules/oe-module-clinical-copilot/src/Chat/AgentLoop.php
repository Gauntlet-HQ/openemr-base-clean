<?php

/**
 * The bounded LLM-tool round trip: ARCHITECTURE_COMPLETE.md's "agent loop (≤5 tool calls, ≤3 rounds)".
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ChainBudget;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCatalog;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolExecutorInterface;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ClaimType;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptContext;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor;

/**
 * ARCHITECTURE_COMPLETE.md CHAT PATH: "agent loop (≤5 tool calls, ≤3
 * rounds): LLM ⇄ capability tools ... each tool call: schema-validate args →
 * inject session pid → run capability fresh (I2) → assert returned facts'
 * pid (I10) → add to session fact set." This class drives exactly that loop
 * and stops there -- it does NOT verify (that is {@see ChatAgent}, which
 * treats {@see AgentLoopResult::$finalClaimsJson} as one candidate answer to
 * gate, retry, or degrade).
 *
 * Every round is egress-redacted via {@see Redactor} before the model ever
 * sees it (ARCHITECTURE.md §4) -- not only the round that produces the final
 * answer. `LlmUnavailableException` from any round propagates straight out
 * (I6: the caller degrades to the facts browser); it is never caught here.
 *
 * **Budget-exhaustion degradation is synthesized, not modeled.** ARCHITECTURE.md
 * §1.2: "hitting the budget degrades transparently ('I retrieved X and Y; I
 * did not retrieve Z -- ask again to continue')." Rather than spending one
 * more LLM round asking the model to summarize under a budget it has
 * already exhausted (cost with no guarantee of a clean, on-schema answer),
 * this class constructs that exact message itself as a zero-citation
 * `uncertainty_statement` claim (legal under V2 -- it asserts no clinical
 * content) listing which tools ran and which did not. It still passes
 * through {@see ChatAgent}'s verifier gate like any other candidate answer,
 * so the "citations checked" badge and verdict ledger stay uniform across
 * every turn outcome.
 */
final class AgentLoop
{
    /**
     * @param PatientIdentifiers $identifiers used only for egress redaction (ARCHITECTURE.md §4)
     * @param (\Closure(string): void)|null $onStatus optional staged-status callback
     *        (ARCHITECTURE.md §1.3: "SSE streams staged status ('retrieving
     *        labs… verifying…')") -- a no-op by default so every non-SSE
     *        caller (including every test) is unaffected; `public/chat.php`
     *        is the one caller that supplies a real emitter.
     */
    public function __construct(
        private readonly ChatLlmClientInterface $llmClient,
        private readonly ToolExecutorInterface $toolExecutor,
        private readonly ChatPromptAssembler $promptAssembler,
        private readonly Redactor $redactor,
        private readonly string $sessionId,
        private readonly PatientIdentifiers $identifiers,
        private readonly PromptContext $context,
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
     *
     * @throws LlmUnavailableException propagated verbatim (I6) -- the caller degrades
     */
    public function run(array $sessionFacts, ?array $narrativeClaims, array $conversationTranscript, string $userQuestion): AgentLoopResult
    {
        $budget = new ChainBudget();
        $facts = $sessionFacts;
        $toolLog = [];
        $transcript = $conversationTranscript;
        $tokensIn = 0;
        $tokensOut = 0;
        $latencyMs = 0;
        $modelVersion = $this->context->model;
        $redactionMap = null;

        while ($budget->startRound()) {
            $this->emitStatus($budget->roundsUsed() === 1 ? 'thinking…' : 'checking whether more data is needed…');
            $toolsOffered = $budget->remainingCalls() > 0 ? ToolCatalog::all() : [];

            $request = $this->promptAssembler->assemble(
                $facts,
                $narrativeClaims,
                $transcript,
                $userQuestion,
                $toolsOffered,
                null,
                $this->context,
                $this->identifiers,
            );

            $redacted = $this->redactor->redactPrompt($this->sessionId, $this->identifiers, $request->prompt);
            $redactionMap = $redacted->map;

            $response = $this->llmClient->converse($request->withPrompt($redacted->request));
            $tokensIn += $response->tokensIn;
            $tokensOut += $response->tokensOut;
            $latencyMs += $response->latencyMs;
            $modelVersion = $response->modelVersion;

            if (!$response->isToolCall()) {
                return new AgentLoopResult(
                    (string)$response->finalClaimsJson,
                    $redactionMap,
                    $facts,
                    $toolLog,
                    $tokensIn,
                    $tokensOut,
                    $latencyMs,
                    $modelVersion,
                    budgetExhausted: false,
                );
            }

            [$facts, $roundLog, $observation] = $this->runToolCalls($response, $budget, $facts);
            $toolLog = [...$toolLog, ...$roundLog];
            if ($observation !== '') {
                $transcript[] = $observation;
            }
        }

        // Rounds exhausted (or the call budget hit 0 before the model
        // stopped requesting more) without the model producing a final
        // answer -- synthesize the transparent degradation message.
        // $redactionMap is always set by this point: ChainBudget::MAX_ROUNDS
        // is a positive constant, so the loop body above always runs at
        // least once before this line is reachable.
        return new AgentLoopResult(
            self::budgetExhaustedClaimsJson($toolLog),
            $redactionMap ?? throw new \LogicException('AgentLoop: no round ever ran despite a positive ChainBudget::MAX_ROUNDS'),
            $facts,
            $toolLog,
            $tokensIn,
            $tokensOut,
            $latencyMs,
            $modelVersion,
            budgetExhausted: true,
        );
    }

    /**
     * One additional, tool-free round used ONLY by {@see ChatAgent}'s
     * single fail-closed retry (ARCHITECTURE.md §2.3): resolve the
     * verifier's findings using the facts already accumulated, no new tool
     * access -- keeping the retry's shape identical to U10's synthesis
     * retry (one regeneration, findings appended, nothing else changes).
     *
     * @param list<Fact> $sessionFacts
     * @param list<Claim>|null $narrativeClaims
     * @param list<string> $conversationTranscript
     *
     * @throws LlmUnavailableException propagated verbatim (I6)
     */
    public function answerWithFindings(
        array $sessionFacts,
        ?array $narrativeClaims,
        array $conversationTranscript,
        string $userQuestion,
        string $priorFindings,
    ): AgentLoopResult {
        $request = $this->promptAssembler->assemble(
            $sessionFacts,
            $narrativeClaims,
            $conversationTranscript,
            $userQuestion,
            [],
            $priorFindings,
            $this->context,
            $this->identifiers,
        );

        $redacted = $this->redactor->redactPrompt($this->sessionId, $this->identifiers, $request->prompt);
        $response = $this->llmClient->converse($request->withPrompt($redacted->request));

        return new AgentLoopResult(
            (string)$response->finalClaimsJson,
            $redacted->map,
            $sessionFacts,
            [],
            $response->tokensIn,
            $response->tokensOut,
            $response->latencyMs,
            $response->modelVersion,
            budgetExhausted: false,
        );
    }

    /**
     * @param list<Fact> $facts
     * @return array{0: list<Fact>, 1: list<ToolCallLogEntry>, 2: string}
     */
    private function runToolCalls(ChatLlmResponse $response, ChainBudget $budget, array $facts): array
    {
        $roundLog = [];
        $observationLines = [];

        foreach ($response->toolCalls ?? [] as $callRequest) {
            if ($budget->remainingCalls() <= 0) {
                $observationLines[] = "(tool-call budget exhausted -- '{$callRequest->name}' was not executed)";
                continue;
            }

            $this->emitStatus("retrieving {$callRequest->name}…");
            $outcome = $this->toolExecutor->execute($callRequest);
            $budget->recordCall();
            $roundLog[] = new ToolCallLogEntry($callRequest, $outcome);

            if ($outcome->ok) {
                $byId = [];
                foreach ($facts as $fact) {
                    $byId[$fact->factId] = $fact;
                }
                foreach ($outcome->facts as $fact) {
                    $byId[$fact->factId] = $fact;
                }
                $facts = array_values($byId);
                $observationLines[] = "Tool '{$callRequest->name}' returned " . count($outcome->facts) . ' fact(s).';
            } else {
                $observationLines[] = "Tool '{$callRequest->name}' FAILED: {$outcome->errorMessage}";
            }
        }

        return [$facts, $roundLog, implode("\n", $observationLines)];
    }

    /**
     * @param list<ToolCallLogEntry> $toolLog
     */
    private static function budgetExhaustedClaimsJson(array $toolLog): string
    {
        $succeeded = [];
        $failed = [];
        foreach ($toolLog as $entry) {
            $label = self::toolDisplayName($entry->request->name);
            if ($entry->outcome->ok) {
                $succeeded[] = $label;
            } else {
                $failed[] = $label;
            }
        }

        $text = 'I retrieved ' . (implode(', ', $succeeded) ?: 'no additional data')
            . ' but reached the per-turn retrieval limit before finishing. Ask again to continue.';
        if ($failed !== []) {
            $text .= ' (Not retrieved: ' . implode(', ', $failed) . '.)';
        }

        $claim = [
            'text' => $text,
            'claim_type' => ClaimType::UncertaintyStatement->value,
            'citation_ids' => [],
            'numeric_values' => [],
            'flags' => [],
            'order' => 0,
            'emphasis' => null,
        ];

        return json_encode([$claim], JSON_THROW_ON_ERROR);
    }

    /**
     * Deliberately NOT the raw tool name: two of the five (`get_overdue`,
     * `get_pending`) contain substrings ({@see \OpenEMR\Modules\ClinicalCopilot\Verify\Config\ClinicalMentionLexicon}'s
     * plain `str_contains` match on "overdue"/"pending") that would flip
     * {@see \OpenEMR\Modules\ClinicalCopilot\Verify\Config\ClinicalMentionLexicon::mentionsClinicalContent()}
     * to true for this purely meta, retrieval-status message -- which would
     * revoke V2's zero-citation exemption for an `uncertainty_statement`
     * claim that asserts no clinical content at all, and ironically fail the
     * very message meant to degrade transparently. These labels are chosen
     * to name each tool's domain without tripping any lexicon term.
     */
    private static function toolDisplayName(string $toolName): string
    {
        return match ($toolName) {
            'get_control_trend' => 'the lab-trend lookup',
            'get_med_history' => 'the medication-history lookup',
            'get_vitals_trend' => 'the vitals-trend lookup',
            'get_overdue' => 'the monitoring-gap check',
            'get_pending' => 'the in-flight-orders check',
            default => "the '{$toolName}' lookup",
        };
    }
}
