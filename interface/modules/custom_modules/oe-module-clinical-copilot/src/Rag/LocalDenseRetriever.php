<?php

/**
 * Local dense retriever: a dependency-free vector-space stand-in for embeddings.
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
 * The "dense" half of the hybrid retriever ({@see HybridRetriever}). Rather than
 * call an embeddings API (none is configured here — the deployment is Gemini
 * API-key only, no Vertex text-embeddings, no Cohere), this builds a fixed-
 * dimension, L2-normalised vector per chunk with the hashing trick over word
 * unigrams AND character trigrams, and scores query-vs-chunk by cosine
 * similarity. The character-trigram features give it genuine sub-word / fuzzy
 * overlap (e.g. "glycaemic" ↔ "glycemic", "microalbumin" ↔ "albuminuria")
 * that the exact-term sparse retriever misses — a different signal to fuse,
 * which is the whole point of going hybrid.
 *
 * It is deterministic, offline, and has no dependencies, so the hybrid path
 * works with zero credentials. It is a stand-in, not a transformer embedding:
 * when a real embeddings provider is available it drops in behind the SAME
 * {@see RetrieverInterface} seam with no other change. Scoped to the small
 * committed guideline corpus that grounds the summarizer's evidence section —
 * the only place RAG is used.
 */
final class LocalDenseRetriever implements RetrieverInterface
{
    private const DIM = 256;
    private const TAG_BOOST = 0.15;

    /** @var array<string, list<float>>|null lazily-built, L2-normalised chunk vectors keyed by chunk id */
    private ?array $chunkVectors = null;

    public function __construct(private readonly GuidelineCorpus $corpus)
    {
    }

    public static function createDefault(): self
    {
        return new self(GuidelineCorpus::createDefault());
    }

    public function retrieve(string $query, array $tags = [], int $topK = 4): array
    {
        $queryVector = $this->embed($query . ' ' . implode(' ', $tags));
        $tagSet = array_map('strtolower', $tags);

        $scored = [];
        foreach ($this->corpus->all() as $index => $chunk) {
            $score = self::cosine($queryVector, $this->vectorFor($chunk));
            foreach ($chunk->tags as $chunkTag) {
                if (in_array(strtolower($chunkTag), $tagSet, true)) {
                    $score += self::TAG_BOOST;
                }
            }
            if ($score > 0.0) {
                $scored[] = ['chunk' => $chunk, 'score' => $score, 'order' => $index];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['order'] <=> $b['order']);

        $out = [];
        foreach (array_slice($scored, 0, max(0, $topK)) as $row) {
            $out[] = EvidenceSnippet::forChunk($row['chunk'], $row['score']);
        }

        return $out;
    }

    /** @return list<float> the L2-normalised vector for a chunk (built once, cached) */
    private function vectorFor(GuidelineChunk $chunk): array
    {
        if ($this->chunkVectors === null) {
            $this->chunkVectors = [];
            foreach ($this->corpus->all() as $c) {
                $this->chunkVectors[$c->id] = $this->embed($c->title . ' ' . $c->section . ' ' . $c->text . ' ' . implode(' ', $c->tags));
            }
        }

        return $this->chunkVectors[$chunk->id] ?? $this->embed($chunk->text);
    }

    /**
     * Hashing-trick embedding: each word unigram and character trigram is
     * hashed to a bucket in [0, DIM) and accumulated, then the vector is
     * L2-normalised so cosine reduces to a dot product.
     *
     * @return list<float>
     */
    private function embed(string $text): array
    {
        $vector = array_fill(0, self::DIM, 0.0);

        $lower = mb_strtolower($text);
        $words = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $word) {
            $vector[self::bucket('w:' . $word)] += 1.0;
            $padded = ' ' . $word . ' ';
            $len = strlen($padded);
            for ($i = 0; $i + 3 <= $len; $i++) {
                $vector[self::bucket('c:' . substr($padded, $i, 3))] += 0.5;
            }
        }

        $norm = 0.0;
        foreach ($vector as $v) {
            $norm += $v * $v;
        }
        $norm = sqrt($norm);
        if ($norm > 0.0) {
            foreach ($vector as $i => $v) {
                $vector[$i] = $v / $norm;
            }
        }

        return $vector;
    }

    private static function bucket(string $feature): int
    {
        return crc32($feature) % self::DIM;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function cosine(array $a, array $b): float
    {
        // Both are already L2-normalised, so cosine == dot product.
        $dot = 0.0;
        foreach ($a as $i => $va) {
            $dot += $va * ($b[$i] ?? 0.0);
        }

        return $dot;
    }
}
