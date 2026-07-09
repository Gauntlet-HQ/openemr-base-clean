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

use OpenEMR\Common\Logging\SystemLogger;

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
        private readonly SystemLogger $logger = new SystemLogger(),
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
            // Log the REAL cause (category + provider/transport detail) so an
            // operator can tell a missing key from a dead network without
            // guessing; the caller still gets the honest unavailable signal.
            $this->logger->error('Clinical Co-Pilot reduce: LLM call failed', [
                'reason' => $e->reason(),
                'detail' => $e->detail(),
                'session' => $request->sessionId,
                'correlation_id' => $request->correlationId,
                'exception' => $e,
            ]);

            return ReduceResult::unavailable($e->reason(), $e->detail());
        }

        // Log the raw model response even though it has NOT been verified yet:
        // "at least get a response we log, even if it does not pass QA." The
        // verifier gate runs downstream in VerifiedGeneration; this is the one
        // record of exactly what the model returned for this attempt.
        $this->logger->info('Clinical Co-Pilot reduce: model response received (pre-verification)', [
            'session' => $request->sessionId,
            'correlation_id' => $request->correlationId,
            'model' => $response->modelVersion,
            'tokens_in' => $response->tokensIn,
            'tokens_out' => $response->tokensOut,
            'raw_claims_json' => $response->rawJson,
        ]);

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
