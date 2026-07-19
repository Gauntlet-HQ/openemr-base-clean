<?php

/**
 * The deterministic supervisor: routes a request to workers with logged handoffs.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineRetrieverFactory;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPolicy;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;

/**
 * Satisfies the Week 2 "supervisor + 2 workers" requirement with a DETERMINISTIC
 * router rather than an LLM — a deliberate design choice recorded in
 * W2_ARCHITECTURE.md. The route is a pure function of the request's shape
 * ({@see AgentRequest::hasDocument()} / {@see AgentRequest::needsEvidence()}),
 * so an LLM router would add latency, cost, and the "black box" the spec's own
 * pitfalls warn against, buying nothing. Instead the routing is loud and
 * inspectable: the supervisor opens one `supervisor` span, every gather worker
 * it invokes records a `worker` child span, and the critic records a `verify`
 * child span, so the full handoff graph is reconstructable from the
 * correlation id alone.
 *
 * The supervisor gathers — it does not write. Extracted facts here are for
 * answering; committing them to the chart is the separate, human-gated
 * ingestion lock flow ({@see \OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionReview}).
 *
 * CRITIC HARD GATE: when an {@see AnswerComposerInterface} is wired and
 * produces a draft, the draft NEVER leaves this class unverified — the
 * {@see CriticWorker} runs the deterministic V1-V6 verifier over it, and a
 * rejection degrades the result to a refusal (mirroring the chat path's
 * "couldn't produce a verifiable answer") with the draft's claims discarded.
 * A V3 wrong-patient citation freezes unconditionally (sev-1), gate policy
 * notwithstanding. Unlike the chat path there is no regeneration retry here:
 * the composer seam has no findings-feedback method yet, so a rejected draft
 * degrades immediately (fail-closed, never fail-open).
 */
final class Supervisor
{
    private const REFUSAL_MESSAGE = "couldn't produce a verifiable answer";

    public function __construct(
        private readonly IntakeExtractorWorker $intakeExtractor,
        private readonly EvidenceRetrieverWorker $evidenceRetriever,
        private readonly CriticWorker $critic,
        private readonly TraceRecorderInterface $tracer,
        private readonly ?AnswerComposerInterface $answerComposer = null,
    ) {
    }

    /**
     * The production composition root. `$answerComposer` is per-request
     * state (the {@see AgentLoopAnswerComposer} wraps a pid/correlation-id
     * bound AgentLoop), so the caller that owns the request builds it and
     * passes it in -- see
     * {@see \OpenEMR\Modules\ClinicalCopilot\Controller\AgentController};
     * omitting it wires the gather-only graph (no composition, no critic).
     */
    public static function createDefault(?AnswerComposerInterface $answerComposer = null): self
    {
        $tracer = new TraceRecorder();
        $extractionClient = new ExtractionClient(LlmClientFactory::create(), LlmRuntimeConfig::synthesisModel());

        return new self(
            new IntakeExtractorWorker($extractionClient, $tracer),
            new EvidenceRetrieverWorker(GuidelineRetrieverFactory::createDefault(), $tracer),
            new CriticWorker(new Verifier(), $tracer),
            $tracer,
            $answerComposer,
        );
    }

    public function handle(AgentRequest $request): SupervisorResult
    {
        $spanId = TraceSpan::newSpanId();
        $start = new \DateTimeImmutable();
        $t0 = microtime(true);

        $routed = [];
        $extraction = null;
        $evidence = [];

        // Deterministic routing decision — knowable from the request alone.
        if ($request->hasDocument()) {
            $routed[] = WorkerName::IntakeExtractor;
            $outcome = $this->intakeExtractor->run($request, $spanId);
            $extraction = $outcome?->extraction;
        }

        if ($request->needsEvidence()) {
            $routed[] = WorkerName::EvidenceRetriever;
            $evidence = $this->evidenceRetriever->run($request, $spanId);
        }

        // Answer composition + critic hard gate. The composed draft is only
        // ever visible to the critic; the returned result carries claims only
        // when the critic let them through.
        $answerStatus = null;
        $answer = null;
        $verdicts = [];
        $refusalMessage = null;
        $supervisorStatus = 'ok';

        $draft = $this->answerComposer?->compose($request, $extraction, $evidence);
        if ($draft !== null) {
            $routed[] = WorkerName::Critic;
            $verification = $this->critic->run($draft, $request, $spanId);
            $verdicts = $verification->verdicts;
            $claims = $verification->claims ?? [];

            if ($verification->hasSev1()) {
                // Mirror the chat path's freeze: a wrong-patient citation is
                // sev-1 — discard unconditionally, even when the content gate
                // is QA-relaxed.
                $answerStatus = AnswerStatus::FrozenSev1;
                $refusalMessage = self::REFUSAL_MESSAGE;
                $supervisorStatus = 'error';
            } elseif ($claims !== [] && ($verification->allPassed() || !VerificationPolicy::gateEnforced())) {
                // Enforced (the default): only a fully-passing, non-empty
                // claim set flows through. QA-relaxed (VERIFY_ENFORCE=0):
                // parseable claims flow with their verdicts recorded, same as
                // the chat path's relaxation.
                $answerStatus = AnswerStatus::Answered;
                $answer = $claims;
            } else {
                // Rejected (uncited claim, banned unsafe language, ungrounded
                // number, schema failure, or an empty answer): refuse rather
                // than emit — the chat path's degrade, without its one-retry.
                $answerStatus = AnswerStatus::Refused;
                $refusalMessage = self::REFUSAL_MESSAGE;
                $supervisorStatus = 'degraded';
            }
        }

        $this->tracer->record(new TraceSpan(
            $request->correlationId,
            $spanId,
            null,
            'supervisor',
            $start,
            (int)round((microtime(true) - $t0) * 1000),
            $supervisorStatus,
            $request->pid,
        ));

        return new SupervisorResult($routed, $extraction, $evidence, $answerStatus, $answer, $verdicts, $refusalMessage);
    }
}
