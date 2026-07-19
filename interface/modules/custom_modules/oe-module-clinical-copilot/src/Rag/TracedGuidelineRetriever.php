<?php

/**
 * Scrub-then-retrieve decorator that records each retrieval as a `retrieve` trace span.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Rag;

use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeQueryScrubber;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;

/**
 * The per-request retrieval seam the chat and summarizer surfaces share. It
 * wraps any {@see RetrieverInterface} with the two guarantees the supervisor
 * path already gives ({@see \OpenEMR\Modules\ClinicalCopilot\Agent\EvidenceRetrieverWorker}):
 *
 *  - EVERY query and tag list is reduced by {@see KnowledgeQueryScrubber}
 *    BEFORE it reaches the inner retriever. The Postgres retriever scrubs
 *    again internally (defense in depth; the scrub is idempotent), but
 *    applying it at this seam means a raw chat message or topic query is
 *    already PHI-free at the retriever boundary, whichever backend the
 *    factory selected — and a test can assert it with a plain spy retriever.
 *  - Every attempted retrieval records ONE `retrieve` span under the
 *    caller's correlation id, so chat-turn and summary retrievals land in
 *    the same dashboard waterfall and retrieval-hit-rate tile as the agent
 *    path. Same span discipline: `ok` on hits; `degraded` +
 *    `EmptyRetrieval` + a PHI-free count line (query term count, topK,
 *    hits=0) on an empty result — never the query text itself.
 *
 * When NOTHING survives the scrub (a non-clinical message like "thanks!"),
 * no retrieval is attempted and no span is recorded — mirroring the
 * supervisor path, where the retriever worker only runs when the request
 * needs evidence ({@see \OpenEMR\Modules\ClinicalCopilot\Agent\AgentRequest::needsEvidence()}).
 * Small talk therefore neither leaks to the store nor drags the hit-rate
 * tile down with retrievals that were never clinically askable.
 *
 * Per-request object: the correlation id / pid / user id are bound at
 * construction, exactly like the pid-bound AgentLoop the controllers build
 * per request.
 */
final class TracedGuidelineRetriever implements RetrieverInterface
{
    public function __construct(
        private readonly RetrieverInterface $inner,
        private readonly KnowledgeQueryScrubber $scrubber,
        private readonly TraceRecorderInterface $tracer,
        private readonly string $correlationId,
        private readonly int $pid,
        private readonly ?int $userId = null,
        private readonly ?string $parentSpanId = null,
    ) {
    }

    /**
     * @param list<string> $tags
     *
     * @return list<EvidenceSnippet>
     */
    public function retrieve(string $query, array $tags = [], int $topK = 4): array
    {
        $safeQuery = $this->scrubber->scrub($query, $tags);
        $safeTags = $this->scrubber->scrubTags($tags);
        if ($safeQuery === '' && $safeTags === []) {
            // Nothing clinical to ground: skip retrieval entirely (see class
            // docblock) rather than sending an empty query downstream.
            return [];
        }

        $start = new \DateTimeImmutable();
        $t0 = microtime(true);
        $snippets = $this->inner->retrieve($safeQuery, $safeTags, $topK);

        $queryTerms = count(preg_split('/\s+/', trim($safeQuery), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $this->tracer->record(new TraceSpan(
            $this->correlationId,
            TraceSpan::newSpanId(),
            $this->parentSpanId,
            'retrieve',
            $start,
            (int)round((microtime(true) - $t0) * 1000),
            $snippets === [] ? 'degraded' : 'ok',
            $this->pid,
            $this->userId,
            $snippets === [] ? 'EmptyRetrieval' : null,
            // PHI-free counts only — never the query/message text.
            $snippets === [] ? "query_terms={$queryTerms} top_k={$topK} hits=0" : null,
        ));

        return $snippets;
    }
}
