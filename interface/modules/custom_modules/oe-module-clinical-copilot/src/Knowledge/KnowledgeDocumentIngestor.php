<?php

/**
 * Orchestrates knowledge ingestion: extract text → chunk → (review) → write.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineChunk;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory;

/**
 * The composition root for "push a document into the knowledge base." It owns no
 * logic itself — it wires the extractor, chunker, and writer and exposes the two
 * steps the web page and CLI both use:
 *
 *   1. {@see preview()} / {@see previewFromText()} — extract + chunk, NO write, so
 *      the operator can review the proposed chunks first.
 *   2. {@see commit()} — write the (possibly reviewed/edited) chunks.
 *
 * Splitting preview from commit lets the web flow carry the proposed chunks
 * through a hidden field and write them on confirm WITHOUT re-transcribing the
 * document (no second model call). The CLI simply calls both back to back.
 */
final class KnowledgeDocumentIngestor
{
    public function __construct(
        private readonly DocumentTextExtractor $extractor,
        private readonly DocumentChunker $chunker,
        private readonly KnowledgeChunkWriter $writer,
    ) {
    }

    public static function createDefault(): self
    {
        $config = KnowledgeBaseConfig::fromEnv();
        $transcriber = new DocumentTranscriber(LlmClientFactory::create(), LlmRuntimeConfig::synthesisModel());

        return new self(
            new DocumentTextExtractor($transcriber),
            new DocumentChunker(),
            new KnowledgeChunkWriter(new KnowledgeWriteConnection($config), $config->table, EmbeddingClientFactory::create()),
        );
    }

    /**
     * Extract text from an uploaded document, then chunk it. No write.
     *
     * @param list<string> $baseTags
     *
     * @return list<GuidelineChunk>
     */
    public function preview(
        string $bytes,
        string $mimeType,
        DocumentMetadata $meta,
        array $baseTags = [],
        ?ChunkOptions $options = null,
    ): array {
        $text = $this->extractor->extract($bytes, $mimeType);

        return $this->chunker->chunk($text, $meta, $baseTags, $options);
    }

    /**
     * Chunk already-plain text (the pasted-text path — no extraction/model call).
     *
     * @param list<string> $baseTags
     *
     * @return list<GuidelineChunk>
     */
    public function previewFromText(string $text, DocumentMetadata $meta, array $baseTags = [], ?ChunkOptions $options = null): array
    {
        return $this->chunker->chunk($text, $meta, $baseTags, $options);
    }

    /**
     * Write reviewed chunks to the knowledge store (replacing the document's prior
     * chunks by default). Returns the number written.
     *
     * @param list<GuidelineChunk> $chunks
     */
    public function commit(array $chunks, bool $replaceExisting = true): int
    {
        return $this->writer->write($chunks, $replaceExisting);
    }

    public function isStoreAvailable(): bool
    {
        return $this->writer->isAvailable();
    }
}
