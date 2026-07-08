<?php

/**
 * One reduce pass: assemble the prompt, redact it, call the LLM, hand back raw output.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

/**
 * Deliberately thin and deliberately NOT a gate (ARCHITECTURE_COMPLETE.md,
 * U7 row: "Output-schema validation, the fail-closed retry, and conflict
 * passthrough are owned by U10's verifier -- U7 hands raw model output to the
 * gate, it does not gate"). One call in, one {@see ReduceResult} out:
 *
 *   assemble (PromptAssembler, over the canonical fact set)
 *     -> redact (Redactor, direct identifiers -> per-session tokens)
 *     -> call the LLM client
 *     -> return the model's raw claims JSON + token usage, unparsed
 *
 * {@see \OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGeneration} (U10) is
 * the orchestrator that calls this class, feeds the raw output through
 * {@see \OpenEMR\Modules\ClinicalCopilot\Verify\Verifier}, and drives the
 * one-retry-then-degrade loop (I11) -- calling {@see self::reduce()} a
 * second time, with `$request->priorFindings` set, IS that retry.
 */
final class Reducer
{
    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly PromptAssembler $promptAssembler,
        private readonly Redactor $redactor,
    ) {
    }

    public function reduce(ReduceRequest $request): ReduceResult
    {
        $assembled = $this->promptAssembler->assemble(
            $request->facts,
            $request->context,
            $request->identifiers,
            $request->priorFindings,
        );

        $redacted = $this->redactor->redactPrompt($request->sessionId, $request->identifiers, $assembled);

        try {
            $response = $this->llmClient->generateStructured($redacted->request);
        } catch (LlmUnavailableException $e) {
            // I6: surfaced as an explicit, checkable degradation signal --
            // never caught-log-continue, never a fabricated empty success.
            return ReduceResult::unavailable($e->reason());
        }

        return ReduceResult::generated(
            $response->rawJson,
            $response->modelVersion,
            $response->tokensIn,
            $response->tokensOut,
            $response->latencyMs,
            $redacted->map,
        );
    }
}
