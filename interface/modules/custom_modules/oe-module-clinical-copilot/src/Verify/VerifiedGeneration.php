<?php

/**
 * The fail-closed entry point: reduce -> verify -> one retry -> degrade (I11).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

use OpenEMR\Modules\ClinicalCopilot\Reduce\ReduceRequest;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer;

/**
 * This is the ONE entry point both U8 (synthesis read path, on a digest
 * miss) and U11 (chat turn controller, every turn) call -- neither calls
 * {@see Reducer} or {@see Verifier} directly. Implements
 * ARCHITECTURE.md §2.3 exactly:
 *
 *   attempt 1: reduce -> verify
 *     LLM unavailable        -> degrade (I6), no verification ran
 *     V3 (patient identity)  -> sev-1: discard, freeze, alert -- NEVER retried
 *     all six pass           -> done, verified
 *     some other check fails -> ONE regeneration, findings appended to the prompt
 *   attempt 2 (only on an ordinary attempt-1 failure):
 *     LLM unavailable        -> degrade (I6)
 *     V3                     -> sev-1 (a retry CAN newly trip V3; still no attempt 3)
 *     all six pass           -> done, verified
 *     still failing          -> discard, degrade -- "narrative unavailable" (synthesis)
 *                                or "couldn't produce a verifiable answer" (chat)
 *
 * Never more than one regeneration, ever (I11: "one regeneration ... then
 * facts-only degradation"). The caller is responsible for actually
 * rendering facts-only / freezing the chat session / persisting
 * `verify_status`/`regen_reason` -- this class only decides the outcome and
 * raises the sev-1 signal; it does not touch DocStore, chat session
 * storage, or the trace table itself (U8/U11/U12's jobs respectively).
 */
final class VerifiedGeneration
{
    public function __construct(
        private readonly Reducer $reducer,
        private readonly Verifier $verifier,
    ) {
    }

    public function generate(VerifiedGenerationRequest $request): VerifiedGenerationResult
    {
        $first = $this->attempt($request->reduceRequest, $request->verificationContext);

        $resolved = self::resolveTerminal($first, 1);
        if ($resolved instanceof VerifiedGenerationResult) {
            return $resolved;
        }

        // First attempt failed an ordinary (non-V3) check -- the one
        // regeneration ARCHITECTURE.md §2.3 allows, with the specific
        // findings appended to the prompt.
        $retryRequest = new ReduceRequest(
            $request->reduceRequest->sessionId,
            $request->reduceRequest->correlationId,
            $request->reduceRequest->facts,
            $request->reduceRequest->identifiers,
            $request->reduceRequest->context,
            self::formatFindings($first->verdicts),
            // Carry the analyte / medication labels into the retry too, or the
            // regeneration's per-item reading guide would collapse to "no
            // recent samples" for every lab and lose the fix.
            $request->reduceRequest->factLabels,
        );

        $second = $this->attempt($retryRequest, $request->verificationContext);

        $resolved = self::resolveTerminal($second, 2);
        if ($resolved instanceof VerifiedGenerationResult) {
            return $resolved;
        }

        // Second failure -> discard, degrade. Message differs by surface
        // (ARCHITECTURE.md §2.3): the caller tells us which via the path on
        // the verification context it already supplied.
        $message = $request->verificationContext->path === VerificationPath::Synthesis
            ? 'narrative unavailable'
            : "couldn't produce a verifiable answer";

        return VerifiedGenerationResult::degradedVerificationFailed($second->verdicts, 2, $message, $second->usage);
    }

    private function attempt(ReduceRequest $reduceRequest, VerificationContext $context): AttemptOutcome
    {
        $reduceResult = $this->reducer->reduce($reduceRequest);

        if (!$reduceResult->isAvailable()) {
            return AttemptOutcome::llmUnavailable($reduceResult->unavailableDetail);
        }

        $usage = new ReduceUsage(
            $reduceResult->tokensIn,
            $reduceResult->tokensOut,
            $reduceResult->latencyMs,
            $reduceResult->modelVersion,
        );

        $verification = $this->verifier->verify((string)$reduceResult->rawClaimsJson, $context);

        if ($verification->hasSev1()) {
            $signal = new Sev1Signal(
                $reduceRequest->correlationId,
                $context->factSet->pinnedPid,
                $verification->find(CheckId::PatientIdentity)?->findings ?? [],
                new \DateTimeImmutable(),
            );

            return AttemptOutcome::sev1($verification->verdicts, $signal, $usage);
        }

        // QA-only relaxation (CLINICAL_COPILOT_VERIFY_ENFORCE=0): accept the
        // produced claims as-is instead of gating, retrying (a second LLM
        // call), and degrading. The gate is ENFORCED by default
        // (VerificationPolicy); even when relaxed, the verifier still ran
        // (verdicts recorded) and the sev-1 wrong-patient freeze above still
        // applies.
        if (!VerificationPolicy::gateEnforced()) {
            return AttemptOutcome::passed(
                $verification->verdicts,
                $verification->claims ?? [],
                $reduceResult->redactionMap ?? throw new \LogicException('an available ReduceResult always carries a RedactionMap'),
                $usage,
            );
        }

        if ($verification->allPassed()) {
            return AttemptOutcome::passed(
                $verification->verdicts,
                $verification->claims ?? [],
                $reduceResult->redactionMap ?? throw new \LogicException('an available ReduceResult always carries a RedactionMap'),
                $usage,
            );
        }

        return AttemptOutcome::failed($verification->verdicts, $usage);
    }

    /**
     * Turns an AttemptOutcome into a final VerifiedGenerationResult when the
     * outcome is terminal (unavailable / sev-1 / passed); returns null when
     * the caller should proceed to the retry (an ordinary failure on
     * attempt 1 only -- {@see self::generate()} never calls this a third
     * time).
     */
    private static function resolveTerminal(AttemptOutcome $outcome, int $attemptNumber): ?VerifiedGenerationResult
    {
        return match ($outcome->kind) {
            AttemptOutcomeKind::LlmUnavailable => VerifiedGenerationResult::degradedLlmUnavailable($attemptNumber, $outcome->llmUnavailableDetail),
            AttemptOutcomeKind::Sev1 => VerifiedGenerationResult::frozen(
                $outcome->verdicts,
                $attemptNumber,
                $outcome->sev1Signal ?? throw new \LogicException('a Sev1 AttemptOutcome always carries a Sev1Signal'),
                $outcome->usage,
            ),
            AttemptOutcomeKind::Passed => VerifiedGenerationResult::passed(
                $outcome->claims ?? throw new \LogicException('a Passed AttemptOutcome always carries claims'),
                $outcome->verdicts,
                $attemptNumber,
                $outcome->redactionMap ?? throw new \LogicException('a Passed AttemptOutcome always carries a RedactionMap'),
                $outcome->usage,
            ),
            AttemptOutcomeKind::Failed => null,
        };
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
