<?php

/**
 * Retrieves guideline evidence from the external medical-knowledge Postgres.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Rag\EvidenceSnippet;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineChunk;
use OpenEMR\Modules\ClinicalCopilot\Rag\HeuristicReranker;
use OpenEMR\Modules\ClinicalCopilot\Rag\RerankerInterface;
use OpenEMR\Modules\ClinicalCopilot\Rag\RetrieverInterface;

/**
 * The Week 2 "tool the summarizer calls to pull knowledge from the separate DB."
 * It is a drop-in {@see RetrieverInterface} — the summarizer, the evidence
 * worker, and the tests already depend on that seam, so this swaps in behind it
 * with no consumer change (the offline {@see \OpenEMR\Modules\ClinicalCopilot\Rag\HybridRetriever}
 * remains the fallback the factory wires when the store is not configured).
 *
 * Two guarantees hold the PHI/knowledge segregation:
 *   - The query AND the tags are scrubbed to non-PHI terms by
 *     {@see KnowledgeQueryScrubber} before they leave this process (free text
 *     via the clinical-keyword allowlist, tags via the closed tag vocabulary in
 *     {@see KnowledgeQueryScrubber::scrubTags()}), so nothing
 *     patient-identifying reaches the non-BAA store.
 *   - Only a parameterized SELECT is issued (via the read-only
 *     {@see KnowledgeQueryRunner}); this class can neither write to the store nor
 *     reach OpenEMR's PHI database.
 *
 * Ranking is Postgres full-text (`ts_rank` over a `websearch_to_tsquery`) plus a
 * fixed boost for chunks whose `tags` overlap the requested analytes — the same
 * "ground THIS out-of-range fact" behaviour the offline retriever gives, but
 * over a store that can grow past what ships in the repo.
 *
 * Like the offline {@see \OpenEMR\Modules\ClinicalCopilot\Rag\HybridRetriever},
 * first-stage SQL ranking is followed by a second-stage rerank: candidates are
 * over-fetched ({@see self::candidateLimit()}) from BOTH the vector and the
 * full-text stage, merged/deduped by id, and passed through the configured
 * {@see RerankerInterface} (default {@see HeuristicReranker} — runs in-process,
 * so nothing beyond the already-scrubbed query is involved) which reorders by
 * cross-pair relevance and truncates to topK.
 */
final class PostgresGuidelineRetriever implements RetrieverInterface
{
    /** Boost added to a chunk's text-rank when its tags overlap the query tags. */
    private const TAG_OVERLAP_BOOST = 0.5;

    /** Over-fetch multiplier: rerank sees up to this many × topK candidates. */
    private const CANDIDATE_FACTOR = 3;

    /** Floor / ceiling for the over-fetched candidate pool. */
    private const CANDIDATE_MIN = 8;
    private const CANDIDATE_MAX = 50;

    private readonly EmbeddingClientInterface $embedder;

    private readonly RerankerInterface $reranker;

    public function __construct(
        private readonly KnowledgeQueryRunner $runner,
        private readonly KnowledgeQueryScrubber $scrubber,
        private readonly string $table = 'guideline_chunks',
        ?EmbeddingClientInterface $embedder = null,
        ?RerankerInterface $reranker = null,
    ) {
        KnowledgeTableName::assertValid($this->table);
        // No embedder ⇒ full-text search only (the vector path is skipped).
        $this->embedder = $embedder ?? new UnavailableEmbeddingClient();
        // Default to the offline cross-pair rerank — the same second stage the
        // offline HybridRetriever applies, so production ordering quality does
        // not silently regress to raw ts_rank/cosine order.
        $this->reranker = $reranker ?? new HeuristicReranker();
    }

    /**
     * @param list<string> $tags
     *
     * @return list<EvidenceSnippet>
     */
    public function retrieve(string $query, array $tags = [], int $topK = 4): array
    {
        if (!$this->runner->isAvailable()) {
            return [];
        }

        $safeQuery = $this->scrubber->scrub($query, $tags);
        $safeTags = $this->safeTags($tags);
        if ($safeQuery === '' && $safeTags === []) {
            return [];
        }

        $limit = max(1, min(self::CANDIDATE_MAX, $topK));
        $candidateLimit = $this->candidateLimit($limit);

        // VECTOR-FIRST, HYBRID: embed the scrubbed (non-PHI) query and rank by
        // pgvector cosine similarity, boosted by tag overlap. Vector search only
        // sees rows that HAVE an embedding, so when the store is mixed (e.g. a
        // seeded corpus with NULL embeddings alongside embedded uploads) we top up
        // the remaining slots from full-text — otherwise the non-embedded rows
        // would be silently unreachable. With no embeddings configured (or the
        // query embed fails), it is full-text only. Both stages fetch up to
        // $candidateLimit (not $limit) so the reranker has a real pool to reorder.
        $queryVector = $this->embedder->isAvailable() && $safeQuery !== '' ? $this->embedder->embed($safeQuery) : null;
        if ($queryVector !== null) {
            $rows = $this->vectorSearch($queryVector, $safeTags, $candidateLimit);
            if (count($rows) < $candidateLimit) {
                $rows = $this->mergeById($rows, $this->fullTextSearch($safeQuery, $safeTags, $candidateLimit), $candidateLimit);
            }
        } else {
            $rows = $this->fullTextSearch($safeQuery, $safeTags, $candidateLimit);
        }

        // Second-stage rerank over the SCRUBBED query (the terms actually
        // searched): reorders best-first and truncates to $limit. With fewer
        // candidates than topK the reranker just returns them all, reordered;
        // an empty scrubbed query (tags-only request) degrades to the blended
        // first-stage score, preserving the SQL order.
        return $this->reranker->rerank($safeQuery, $this->mapRows($rows), $limit);
    }

    /**
     * Over-fetch size for the rerank candidate pool: CANDIDATE_FACTOR × topK,
     * floored at CANDIDATE_MIN (a tiny topK still deserves a pool worth
     * reordering) and capped at CANDIDATE_MAX (bounds SQL work and payload).
     */
    private function candidateLimit(int $limit): int
    {
        return min(self::CANDIDATE_MAX, max($limit * self::CANDIDATE_FACTOR, self::CANDIDATE_MIN));
    }

    /**
     * pgvector cosine-similarity search (`embedding <=> query`), plus the same
     * tag-overlap boost. Only rows that have an embedding are candidates.
     *
     * @param list<float>  $queryVector
     * @param list<string> $safeTags
     *
     * @return list<array<string, mixed>>
     */
    private function vectorSearch(array $queryVector, array $safeTags, int $limit): array
    {
        [$tagsExpr, $tagParams] = $this->tagsArrayExpression($safeTags);
        $vectorLiteral = PgLiteral::vector($queryVector);

        // pgvector <=> is cosine DISTANCE, so (1 - distance) is cosine similarity
        // (range [-1,1]); add the tag-overlap boost, order by the combined score.
        $sql = sprintf(
            'SELECT id, title, source, section, body, tags, url,'
            . ' (1 - (embedding <=> :qvec::vector))'
            . ' + (CASE WHEN tags && %1$s THEN %2$s ELSE 0 END) AS score'
            . ' FROM %3$s'
            . ' WHERE embedding IS NOT NULL'
            . ' ORDER BY score DESC, id ASC'
            . ' LIMIT %4$d',
            $tagsExpr,
            self::TAG_OVERLAP_BOOST,
            $this->table,
            $limit,
        );

        return $this->runner->select($sql, ['qvec' => $vectorLiteral] + $tagParams);
    }

    /**
     * Postgres full-text fallback: OR the scrubbed keywords rather than AND-ing
     * them. websearch_to_tsquery defaults to AND, which for a multi-word clinical
     * question ("a1c high") demands a single chunk carrying every term and misses
     * obviously relevant guidance. RAG wants recall: ts_rank + the tag boost order
     * the matches and topK caps them. "or" is websearch_to_tsquery's OR operator;
     * scrubbed terms are >=2 chars of [a-z0-9-] and can never collide with it.
     *
     * @param list<string> $safeTags
     *
     * @return list<array<string, mixed>>
     */
    private function fullTextSearch(string $safeQuery, array $safeTags, int $limit): array
    {
        $tsQuery = $safeQuery === '' ? '' : implode(' or ', explode(' ', $safeQuery));
        [$tagsExpr, $tagParams] = $this->tagsArrayExpression($safeTags);
        $sql = sprintf(
            'SELECT id, title, source, section, body, tags, url,'
            . ' ts_rank(search_vector, websearch_to_tsquery(\'english\', :q))'
            . ' + (CASE WHEN tags && %1$s THEN %2$s ELSE 0 END) AS score'
            . ' FROM %3$s'
            . ' WHERE search_vector @@ websearch_to_tsquery(\'english\', :q) OR tags && %1$s'
            . ' ORDER BY score DESC, id ASC'
            . ' LIMIT %4$d',
            $tagsExpr,
            self::TAG_OVERLAP_BOOST,
            $this->table,
            $limit,
        );

        return $this->runner->select($sql, ['q' => $tsQuery] + $tagParams);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<EvidenceSnippet>
     */
    private function mapRows(array $rows): array
    {
        $snippets = [];
        foreach ($rows as $row) {
            $chunk = $this->chunkFromRow($row);
            if ($chunk === null) {
                continue;
            }
            $score = is_numeric($row['score'] ?? null) ? (float)$row['score'] : 0.0;
            $snippets[] = EvidenceSnippet::forChunk($chunk, $score);
        }

        return $snippets;
    }

    /**
     * @param list<string> $safeTags
     *
     * @return array{0: string, 1: array<string, string>} [sql expression, bind params]
     */
    private function tagsArrayExpression(array $safeTags): array
    {
        if ($safeTags === []) {
            return ["'{}'::text[]", []];
        }

        $placeholders = [];
        $params = [];
        foreach ($safeTags as $i => $tag) {
            $placeholders[] = ":t{$i}";
            $params["t{$i}"] = $tag;
        }

        return ['ARRAY[' . implode(', ', $placeholders) . ']::text[]', $params];
    }

    /**
     * Tags cross the segregation boundary as SQL bind params, so they go
     * through the scrubber's tag allowlist — not bare normalization — before
     * leaving the process (closes SECURITY.md finding #12: the `tags`
     * parameter previously bypassed {@see KnowledgeQueryScrubber} entirely).
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private function safeTags(array $tags): array
    {
        return $this->scrubber->scrubTags($tags);
    }

    /**
     * Merge full-text rows in after the vector rows to fill up to $limit, skipping
     * ids already present — so a mixed store's non-embedded rows stay reachable
     * without disturbing the vector ranking of the rows that had embeddings.
     *
     * @param list<array<string, mixed>> $primary
     * @param list<array<string, mixed>> $secondary
     *
     * @return list<array<string, mixed>>
     */
    private function mergeById(array $primary, array $secondary, int $limit): array
    {
        $merged = $primary;
        $seen = [];
        foreach ($primary as $row) {
            if (is_string($row['id'] ?? null)) {
                $seen[$row['id']] = true;
            }
        }
        foreach ($secondary as $row) {
            if (count($merged) >= $limit) {
                break;
            }
            $id = $row['id'] ?? null;
            if (is_string($id) && !isset($seen[$id])) {
                $merged[] = $row;
                $seen[$id] = true;
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function chunkFromRow(array $row): ?GuidelineChunk
    {
        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        $body = is_string($row['body'] ?? null) ? $row['body'] : '';
        $source = is_string($row['source'] ?? null) ? $row['source'] : '';
        if ($id === '' || $body === '' || $source === '') {
            return null;
        }

        return new GuidelineChunk(
            id: $id,
            title: is_string($row['title'] ?? null) ? $row['title'] : '',
            source: $source,
            section: is_string($row['section'] ?? null) ? $row['section'] : '',
            text: $body,
            tags: $this->parseTagsColumn($row['tags'] ?? null),
            url: is_string($row['url'] ?? null) && $row['url'] !== '' ? $row['url'] : null,
        );
    }

    /**
     * Postgres returns a `text[]` column as a literal like `{a1c,ldl}` over the
     * PDO pgsql driver. Parse it back to a list; anything else yields [].
     *
     * @return list<string>
     */
    private function parseTagsColumn(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }
        if (!is_string($value) || $value === '' || $value === '{}') {
            return [];
        }

        $inner = trim($value, '{}');
        if ($inner === '') {
            return [];
        }

        $tags = [];
        foreach (explode(',', $inner) as $part) {
            $part = trim($part, " \"");
            if ($part !== '') {
                $tags[] = $part;
            }
        }

        return $tags;
    }
}
