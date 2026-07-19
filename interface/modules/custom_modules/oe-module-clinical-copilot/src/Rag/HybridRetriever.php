<?php

/**
 * Hybrid retrieval: fuse sparse + (optional) dense candidates, then rerank.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Rag;

/**
 * The Week 2 "keyword + dense retrieval, rerank the candidates" requirement,
 * built to degrade one layer at a time rather than all-or-nothing:
 *
 *  - Always: sparse keyword retrieval ({@see SparseRetriever}, offline).
 *  - Dense retrieval fused with Reciprocal Rank Fusion — order-based, so it
 *    needs no score calibration between two very different scoring schemes. The
 *    DEFAULT dense retriever is {@see LocalDenseRetriever} (an offline hashing
 *    embedding), so the fusion runs with zero credentials; a hosted embeddings
 *    provider drops in behind the same {@see RetrieverInterface} seam.
 *  - Then rerank ({@see RerankerInterface}). The DEFAULT is
 *    {@see HeuristicReranker} (an offline cross-pair relevance pass — the
 *    "Cohere rerank or equivalent"); {@see PassthroughReranker} remains the
 *    no-op floor, and a hosted reranker swaps in behind the same seam.
 *
 * So {@see self::createDefault()} is a full keyword + dense + rerank pipeline
 * that runs entirely offline — real, cited evidence, no network — which is what
 * grounds the summarizer's guideline-evidence section (the sole RAG consumer).
 * Passing `dense: null` / a {@see PassthroughReranker} explicitly degrades a
 * layer at a time for testing or constrained environments.
 */
final class HybridRetriever implements RetrieverInterface
{
    /** Reciprocal Rank Fusion constant; 60 is the standard default. */
    private const RRF_K = 60;

    public function __construct(
        private readonly RetrieverInterface $sparse,
        private readonly RerankerInterface $reranker,
        private readonly ?RetrieverInterface $dense = null,
    ) {
    }

    public static function createDefault(): self
    {
        $corpus = GuidelineCorpus::createDefault();

        // Full offline pipeline: keyword (sparse) + dense fused, then reranked.
        return new self(
            new SparseRetriever($corpus),
            new HeuristicReranker(),
            new LocalDenseRetriever($corpus),
        );
    }

    /**
     * @param list<string> $tags
     *
     * @return list<EvidenceSnippet>
     */
    public function retrieve(string $query, array $tags = [], int $topK = 4): array
    {
        // Over-fetch candidates so the reranker has something to work with.
        $candidateK = max($topK * 3, 8);
        $sparseHits = $this->sparse->retrieve($query, $tags, $candidateK);

        if ($this->dense === null) {
            return $this->reranker->rerank($query, $sparseHits, $topK);
        }

        $denseHits = $this->dense->retrieve($query, $tags, $candidateK);
        $fused = $this->reciprocalRankFusion($sparseHits, $denseHits);

        return $this->reranker->rerank($query, array_slice($fused, 0, $candidateK), $topK);
    }

    /**
     * Merge two ranked lists by chunk id; each list contributes 1/(k + rank).
     *
     * @param list<EvidenceSnippet> $a
     * @param list<EvidenceSnippet> $b
     *
     * @return list<EvidenceSnippet>
     */
    private function reciprocalRankFusion(array $a, array $b): array
    {
        /** @var array<string, array{snippet: EvidenceSnippet, score: float, order: int}> $byId */
        $byId = [];
        $order = 0;

        foreach ([$a, $b] as $list) {
            foreach (array_values($list) as $rank => $snippet) {
                $id = $snippet->chunk->id;
                $contribution = 1.0 / (self::RRF_K + $rank + 1);
                if (isset($byId[$id])) {
                    $byId[$id]['score'] += $contribution;
                } else {
                    $byId[$id] = ['snippet' => $snippet, 'score' => $contribution, 'order' => $order++];
                }
            }
        }

        $rows = array_values($byId);
        usort($rows, static function (array $x, array $y): int {
            return $y['score'] <=> $x['score'] ?: $x['order'] <=> $y['order'];
        });

        return array_map(
            static fn (array $row): EvidenceSnippet => $row['snippet']->withScore($row['score']),
            $rows,
        );
    }
}
