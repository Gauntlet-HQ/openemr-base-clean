<?php

/**
 * Writes chunked knowledge to Postgres — the ONLY writer on the request side.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineChunk;

/**
 * The write half of knowledge ingestion. It upserts a document's chunks into the
 * same `guideline_chunks` table the seed script and the retriever use, in one
 * transaction, and — by default — first deletes the document's previous chunks by
 * `source`, so re-uploading a corrected guideline cleanly SUPERSEDES the old
 * version rather than leaving stale chunks behind (the chunk count for a shrunk
 * document would otherwise never go down).
 *
 * It is confined to the ingestion flow; the retrieval path has no reference to
 * it. Table identifiers are validated (never bound) exactly as in the retriever.
 */
final class KnowledgeChunkWriter
{
    private readonly EmbeddingClientInterface $embedder;

    public function __construct(
        private readonly KnowledgeWriteRunner $runner,
        private readonly string $table = 'guideline_chunks',
        ?EmbeddingClientInterface $embedder = null,
    ) {
        KnowledgeTableName::assertValid($this->table);
        // No embedder configured ⇒ store chunks with a NULL embedding; retrieval
        // then uses full-text search until an embedding key is set.
        $this->embedder = $embedder ?? new UnavailableEmbeddingClient();
    }

    public function isAvailable(): bool
    {
        return $this->runner->isAvailable();
    }

    /**
     * Upsert a document's chunks. When $replaceExisting is true (the default) the
     * document's prior chunks (same `source`) are removed first, all in one
     * transaction. Returns the number of chunks written.
     *
     * @param list<GuidelineChunk> $chunks all sharing one document `source`
     */
    public function write(array $chunks, bool $replaceExisting = true): int
    {
        if ($chunks === []) {
            return 0;
        }
        if (!$this->runner->isAvailable()) {
            throw new \RuntimeException('Knowledge store is not configured or its driver is unavailable');
        }

        // Embed every chunk in one batch (the "store in the vector db" step). A
        // null vector for any chunk simply leaves that row's embedding NULL.
        $vectors = $this->embedder->embedBatch(array_map(
            fn (GuidelineChunk $c): string => $this->embedText($c),
            $chunks,
        ));

        $upsert = sprintf(
            'INSERT INTO %s (id, title, source, section, body, tags, url, embedding) '
            . 'VALUES (:id, :title, :source, :section, :body, :tags::text[], :url, :embedding::vector) '
            . 'ON CONFLICT (id) DO UPDATE SET '
            . 'title = EXCLUDED.title, source = EXCLUDED.source, section = EXCLUDED.section, '
            . 'body = EXCLUDED.body, tags = EXCLUDED.tags, url = EXCLUDED.url, '
            . 'embedding = EXCLUDED.embedding, updated_at = now()',
            $this->table,
        );

        $this->runner->begin();
        try {
            if ($replaceExisting) {
                $this->runner->execute(
                    sprintf('DELETE FROM %s WHERE source = :source', $this->table),
                    ['source' => $chunks[0]->source],
                );
            }

            $written = 0;
            foreach ($chunks as $i => $chunk) {
                $this->runner->execute($upsert, [
                    'id' => $chunk->id,
                    'title' => $chunk->title,
                    'source' => $chunk->source,
                    'section' => $chunk->section,
                    'body' => $chunk->text,
                    'tags' => PgLiteral::textArray($chunk->tags),
                    'url' => $chunk->url,
                    'embedding' => ($v = $vectors[$i] ?? null) !== null && $v !== [] ? PgLiteral::vector($v) : null,
                ]);
                $written++;
            }

            $this->runner->commit();

            return $written;
        } catch (\Throwable $e) {
            $this->runner->rollback();

            throw $e;
        }
    }

    /**
     * The text embedded for a chunk: title + section + body, so a topical query
     * matches on the heading as well as the prose (mirrors the full-text weights).
     */
    private function embedText(GuidelineChunk $chunk): string
    {
        return trim(implode("\n", array_filter([$chunk->title, $chunk->section, $chunk->text])));
    }

}
