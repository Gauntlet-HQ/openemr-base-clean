<?php

/**
 * The LLM-backed production AnswerComposerInterface: composes a draft via the chat path's AgentLoop.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ParsedExtraction;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\RedactionMap;

/**
 * The production wiring behind {@see AnswerComposerInterface}: one
 * {@see AgentLoop} run (the SAME prompt discipline, redaction, tool-calling
 * and §2.1 claims contract the chat path uses) turned into a
 * {@see ComposedAnswer} the supervisor's {@see CriticWorker} hard-gates.
 * The loop's accumulated facts (its tool results) ARE the grounding fact
 * set -- exactly the "preloaded facts ∪ this session's tool results" rule
 * V2 resolves citations against on the chat path, so a claim citing
 * anything the loop did not actually fetch is uncited by construction.
 *
 * Two deliberate boundaries, both mirroring the module's separation rules:
 *
 * - `$extraction` and `$evidence` are NOT fed to the model. Guideline
 *   evidence and document extractions are surfaced verbatim by the caller
 *   in their own deterministically-cited response sections
 *   ({@see SupervisorResult} keeps them structurally separate); the model
 *   only narrates over chart facts its tools fetched, which keeps every
 *   composed claim resolvable by the critic (a guideline excerpt or an
 *   uncommitted extraction field has no fact_id a claim could cite).
 * - LLM unavailability degrades, never crashes: the I6 convention. A null
 *   return means "no draft" -- the supervisor skips the critic and the
 *   caller renders the facts/evidence-only outcome, with the machine
 *   reason retrievable via {@see self::lastUnavailableReason()}.
 *
 * Per-request object (the {@see AgentLoop} inside is pid + correlation-id
 * bound): {@see self::lastRedactionMap()} exposes the loop's redaction map
 * so the caller can rehydrate claim text for display, exactly as
 * {@see \OpenEMR\Modules\ClinicalCopilot\Controller\ChatController} does --
 * the supervisor seam itself deliberately carries no presentation state.
 */
final class AgentLoopAnswerComposer implements AnswerComposerInterface
{
    private ?RedactionMap $lastRedactionMap = null;

    private ?string $lastUnavailableReason = null;

    public function __construct(
        private readonly AgentLoop $agentLoop,
        private readonly SystemLogger $logger = new SystemLogger(),
    ) {
    }

    public function compose(AgentRequest $request, ?ParsedExtraction $extraction, array $evidence): ?ComposedAnswer
    {
        $this->lastRedactionMap = null;
        $this->lastUnavailableReason = null;

        $question = trim($request->question ?? '');
        if ($question === '') {
            // Document-only / tags-only request: nothing to answer, so the
            // supervisor skips the critic rather than verifying an empty draft.
            return null;
        }

        try {
            $loopResult = $this->agentLoop->run([], null, [], $question);
        } catch (LlmUnavailableException $e) {
            // I6: an explicit, checkable degradation -- the supervisor result
            // simply carries no answer. Log the rich cause for operators
            // (category + transport detail, never the question text).
            $this->logger->error('Clinical Co-Pilot agent: answer composition LLM call failed', [
                'reason' => $e->reason(),
                'detail' => $e->detail(),
                'correlation_id' => $request->correlationId,
                'pid' => $request->pid,
                'exception' => $e,
            ]);
            $this->lastUnavailableReason = $e->reason();

            return null;
        }

        $this->lastRedactionMap = $loopResult->redactionMap;

        return new ComposedAnswer($loopResult->finalClaimsJson, $loopResult->accumulatedFacts);
    }

    /**
     * The redaction map of the most recent successful {@see self::compose()},
     * for rehydrating claim text at the presentation layer.
     */
    public function lastRedactionMap(): ?RedactionMap
    {
        return $this->lastRedactionMap;
    }

    /**
     * Machine reason (never provider internals) when the most recent
     * {@see self::compose()} returned null because the LLM was unavailable;
     * null when it succeeded or there was nothing to answer.
     */
    public function lastUnavailableReason(): ?string
    {
        return $this->lastUnavailableReason;
    }
}
