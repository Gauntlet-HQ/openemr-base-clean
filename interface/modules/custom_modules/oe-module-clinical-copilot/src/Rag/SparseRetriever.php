<?php

/**
 * Keyword (TF-IDF) retrieval over the guideline corpus — the offline half of hybrid RAG.
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
 * Pure-PHP, no embeddings, no vector store, no network — so it is the retrieval
 * floor that ALWAYS works, including the no-credentials default in this
 * environment. Dense retrieval + rerank ({@see HybridRetriever}) layer on top
 * when configured, but sparse-only still returns real, cited evidence so the
 * summarizer/chat augmentation never dead-ends. Deterministic: identical corpus
 * + query always yields identical ranking (ties broken by chunk id), which is
 * what makes it testable against a golden set.
 */
final class SparseRetriever implements RetrieverInterface
{
    /** Boost added per query-tag that matches a chunk tag (analyte-aware retrieval). */
    private const TAG_BOOST = 2.5;

    /** Tiny, domain-agnostic stopword set — enough to drop noise, not clinical terms. */
    private const STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'is', 'are', 'for',
        'on', 'with', 'at', 'by', 'be', 'this', 'that', 'it', 'as', 'from',
        'what', 'should', 'do', 'i', 'my', 'we',
    ];

    public function __construct(private readonly GuidelineCorpus $corpus)
    {
    }

    /**
     * @param list<string> $tags
     *
     * @return list<EvidenceSnippet>
     */
    public function retrieve(string $query, array $tags = [], int $topK = 4): array
    {
        $chunks = $this->corpus->all();
        if ($chunks === []) {
            return [];
        }

        $queryTerms = $this->tokenize($query);
        $idf = $this->inverseDocumentFrequency($chunks);
        $tagSet = array_map(static fn (string $t): string => strtolower($t), $tags);

        $scored = [];
        foreach ($chunks as $index => $chunk) {
            $score = $this->scoreChunk($chunk, $queryTerms, $idf, $tagSet);
            if ($score > 0.0) {
                // Encode the corpus order into the sort key so ties are stable.
                $scored[] = ['chunk' => $chunk, 'score' => $score, 'order' => $index];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'] ?: $a['order'] <=> $b['order'];
        });

        $out = [];
        foreach (array_slice($scored, 0, max(0, $topK)) as $row) {
            $out[] = EvidenceSnippet::forChunk($row['chunk'], $row['score']);
        }

        return $out;
    }

    /**
     * @param list<string> $queryTerms
     * @param array<string, float> $idf
     * @param list<string> $tagSet
     */
    private function scoreChunk(GuidelineChunk $chunk, array $queryTerms, array $idf, array $tagSet): float
    {
        $chunkTerms = $this->tokenize($chunk->title . ' ' . $chunk->section . ' ' . $chunk->text . ' ' . implode(' ', $chunk->tags));
        $termFreq = array_count_values($chunkTerms);

        $score = 0.0;
        foreach ($queryTerms as $term) {
            $tf = $termFreq[$term] ?? 0;
            if ($tf > 0) {
                $score += ($idf[$term] ?? 0.0) * (1.0 + log((float)$tf));
            }
        }

        foreach ($chunk->tags as $chunkTag) {
            if (in_array(strtolower($chunkTag), $tagSet, true)) {
                $score += self::TAG_BOOST;
            }
        }

        return $score;
    }

    /**
     * @param list<GuidelineChunk> $chunks
     *
     * @return array<string, float>
     */
    private function inverseDocumentFrequency(array $chunks): array
    {
        $docCount = count($chunks);
        $documentFrequency = [];
        foreach ($chunks as $chunk) {
            $seen = array_unique($this->tokenize($chunk->title . ' ' . $chunk->section . ' ' . $chunk->text));
            foreach ($seen as $term) {
                $documentFrequency[$term] = ($documentFrequency[$term] ?? 0) + 1;
            }
        }

        $idf = [];
        foreach ($documentFrequency as $term => $df) {
            // Smoothed IDF; always positive so a term present in every chunk
            // still contributes a little.
            $idf[$term] = log(1.0 + ($docCount / $df));
        }

        return $idf;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $lower = mb_strtolower($text);
        $parts = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $terms = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 2 || in_array($part, self::STOPWORDS, true)) {
                continue;
            }
            $terms[] = $part;
        }

        return $terms;
    }
}
