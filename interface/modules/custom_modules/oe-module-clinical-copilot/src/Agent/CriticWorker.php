<?php

/**
 * The critic worker: runs the deterministic V1-V6 verifier over a composed draft answer.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;
use OpenEMR\Modules\ClinicalCopilot\Verify\SessionFactSet;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationContext;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPath;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationResult;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;

/**
 * The Week 2 "critic agent" on the multi-agent path -- a thin wrapper around
 * the same deterministic {@see Verifier} the chat/synthesis paths use (V2
 * citation resolution rejects uncited claims, V5 banned-claim lint rejects
 * unsafe causation/recommendation/dosage/diagnosis/interaction language),
 * kept symmetric with the other workers: one `run()` per invocation, one
 * child span parented to the supervisor span. The span kind is `verify` (the
 * trace table's documented kind for verifier executions), so the handoff
 * graph reads `supervisor -> worker(s) -> verify` from the correlation id
 * alone. Span status: `ok` (all six checks passed), `degraded` (ordinary
 * rejection), `error` (V3 sev-1 wrong-patient citation).
 *
 * This class only judges; the block/refuse decision -- including honoring the
 * {@see \OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPolicy} QA switch
 * -- belongs to {@see Supervisor}, exactly as {@see Verifier} defers that
 * loop to its own callers on the other paths.
 */
final class CriticWorker
{
    public function __construct(
        private readonly Verifier $verifier,
        private readonly TraceRecorderInterface $tracer,
    ) {
    }

    public function name(): WorkerName
    {
        return WorkerName::Critic;
    }

    public function run(ComposedAnswer $draft, AgentRequest $request, string $parentSpanId): VerificationResult
    {
        $start = new \DateTimeImmutable();
        $t0 = microtime(true);

        $verification = $this->verifier->verify(
            $draft->rawClaimsJson,
            new VerificationContext(
                new SessionFactSet($request->pid, $draft->groundingFacts),
                VerificationPath::Chat,
            ),
        );

        $status = 'ok';
        if ($verification->hasSev1()) {
            $status = 'error';
        } elseif (!$verification->allPassed()) {
            $status = 'degraded';
        }

        $this->tracer->record(new TraceSpan(
            $request->correlationId,
            TraceSpan::newSpanId(),
            $parentSpanId,
            'verify',
            $start,
            (int)round((microtime(true) - $t0) * 1000),
            $status,
            $request->pid,
        ));

        return $verification;
    }
}
