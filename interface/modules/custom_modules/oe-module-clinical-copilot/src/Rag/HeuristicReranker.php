<?php

/**
 * Heuristic reranker: a dependency-free second-stage relevance pass.
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
 * The rerank stage of the hybrid retriever — the "Cohere rerank or equivalent"
 * requirement, done offline. A hosted cross-encoder reranker (Cohere, Vertex
 * ranking) re-scores each (query, candidate) pair with features the first-stage
 * retrievers never saw; this computes an equivalent cross-pair signal locally:
 *
 *  - query-term COVERAGE: fraction of the distinct query terms the candidate
 *    actually contains (a candidate that hits 4/5 query terms outranks one that
 *    hit a single high-IDF term hard),
 *  - PHRASE adjacency: a bonus when query terms appear as a contiguous run in
 *    the candidate (proximity the bag-of-words stages ignore),
 *  - TITLE / TAG hits: a term matched in the chunk title or a topic tag is
 *    worth more than one buried in the body,
 *
 * blended with the incoming fused score. It reorders best-first and truncates
 * to topK. Deterministic; ties break on the incoming order so the pass is
 * stable. It swaps for a hosted reranker behind the SAME {@see RerankerInterface}
 * seam with no other change ({@see PassthroughReranker} remains the no-op floor).
 * Used only by the summarizer's evidence retrieval — the sole RAG consumer.
 */
final class HeuristicReranker implements RerankerInterface
{
    private const COVERAGE_WEIGHT = 1.0;
    private const PHRASE_BONUS = 0.5;
    private const TITLE_BONUS = 0.4;
    private const FUSED_WEIGHT = 0.3;

    public function rerank(string $query, array $candidates, int $topK): array
    {
        $queryTerms = $this->tokenize($query);
        $distinct = array_values(array_unique($queryTerms));

        $ranked = [];
        foreach ($candidates as $order => $snippet) {
            $chunk = $snippet->chunk;
            $bodyTerms = $this->tokenize($chunk->title . ' ' . $chunk->section . ' ' . $chunk->text . ' ' . implode(' ', $chunk->tags));
            $bodySet = array_flip($bodyTerms);
            $titleSet = array_flip($this->tokenize($chunk->title . ' ' . implode(' ', $chunk->tags)));

            $covered = 0;
            $titleHits = 0;
            foreach ($distinct as $term) {
                if (isset($bodySet[$term])) {
                    $covered++;
                }
                if (isset($titleSet[$term])) {
                    $titleHits++;
                }
            }
            $coverage = $distinct === [] ? 0.0 : $covered / count($distinct);

            $rerankScore =
                self::COVERAGE_WEIGHT * $coverage
                + self::TITLE_BONUS * ($distinct === [] ? 0.0 : $titleHits / count($distinct))
                + self::PHRASE_BONUS * ($this->containsPhrase($bodyTerms, $queryTerms) ? 1.0 : 0.0)
                + self::FUSED_WEIGHT * $snippet->score;

            $ranked[] = ['snippet' => $snippet->withScore($rerankScore), 'order' => $order];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['snippet']->score <=> $a['snippet']->score ?: $a['order'] <=> $b['order']);

        return array_map(static fn (array $r) => $r['snippet'], array_slice($ranked, 0, max(0, $topK)));
    }

    /**
     * @param list<string> $haystack
     * @param list<string> $needle
     */
    private function containsPhrase(array $haystack, array $needle): bool
    {
        $needle = array_values(array_filter($needle, static fn (string $t): bool => $t !== ''));
        $n = count($needle);
        if ($n < 2) {
            return false;
        }
        $limit = count($haystack) - $n;
        for ($i = 0; $i <= $limit; $i++) {
            $match = true;
            for ($j = 0; $j < $n; $j++) {
                if (($haystack[$i + $j] ?? null) !== $needle[$j]) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function tokenize(string $text): array
    {
        $lower = mb_strtolower($text);

        return preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
