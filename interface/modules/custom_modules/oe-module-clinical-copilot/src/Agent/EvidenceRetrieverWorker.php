<?php

/**
 * The evidence-retriever worker: retrieves cited guideline evidence for a request.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

use OpenEMR\Modules\ClinicalCopilot\Rag\EvidenceSnippet;
use OpenEMR\Modules\ClinicalCopilot\Rag\RetrieverInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;

/**
 * One of the two Week 2 workers. It wraps the RAG retriever and records its
 * invocation as a `worker` span whose parent is the supervisor span — that is
 * the inspectable handoff — plus a `retrieve` child span (parented to the
 * worker span) around the retrieval call itself, so the trace reads
 * `supervisor -> worker -> retrieve` from the correlation id alone (the
 * spec's 4-level tree). Its output (guideline {@see EvidenceSnippet}s) is
 * kept structurally separate from patient-record facts by its `SourceType::Guideline`
 * citations, so the assembled answer never conflates the two evidence classes.
 *
 * Span metadata note: `mod_copilot_trace` has no free-form attrs column, and
 * the module convention (see {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath})
 * populates `error_class`/`error_detail` only on non-ok spans. So the
 * `retrieve` span carries status + duration on the happy path, and on an
 * empty result degrades with a PHI-free detail line (query term count, topK,
 * hit count = 0) — never the query text itself.
 */
final class EvidenceRetrieverWorker
{
    public function __construct(
        private readonly RetrieverInterface $retriever,
        private readonly TraceRecorderInterface $tracer,
    ) {
    }

    public function name(): WorkerName
    {
        return WorkerName::EvidenceRetriever;
    }

    /**
     * @return list<EvidenceSnippet>
     */
    public function run(AgentRequest $request, string $parentSpanId, int $topK = 4): array
    {
        // Minted up front so the `retrieve` child can parent to the worker
        // span even though the worker span is recorded after it (children
        // finish first; the recorder is append-only, parentage is by id).
        $workerSpanId = TraceSpan::newSpanId();
        $start = new \DateTimeImmutable();
        $t0 = microtime(true);

        $retrieveStart = new \DateTimeImmutable();
        $tRetrieve = microtime(true);
        $snippets = $this->retriever->retrieve($request->evidenceQuery(), $request->tags, $topK);
        $status = $snippets === [] ? 'degraded' : 'ok';

        $queryTerms = count(preg_split('/\s+/', trim($request->evidenceQuery()), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $this->tracer->record(new TraceSpan(
            $request->correlationId,
            TraceSpan::newSpanId(),
            $workerSpanId,
            'retrieve',
            $retrieveStart,
            (int)round((microtime(true) - $tRetrieve) * 1000),
            $status,
            $request->pid,
            null,
            $snippets === [] ? 'EmptyRetrieval' : null,
            // PHI-free counts only — never the query/question text.
            $snippets === [] ? "query_terms={$queryTerms} top_k={$topK} hits=0" : null,
        ));

        $this->tracer->record(new TraceSpan(
            $request->correlationId,
            $workerSpanId,
            $parentSpanId,
            'worker',
            $start,
            (int)round((microtime(true) - $t0) * 1000),
            $status,
            $request->pid,
        ));

        return $snippets;
    }
}
