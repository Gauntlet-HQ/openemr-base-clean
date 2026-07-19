<?php

/**
 * The seam for turning text into an embedding vector.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

/**
 * The knowledge ingestion (embed each chunk) and retrieval (embed the query)
 * paths depend on THIS, never a concrete provider — so tests bind a deterministic
 * fake and the real Gemini client drops in behind it. Everything degrades: when
 * no embedding provider is configured {@see isAvailable()} is false and the
 * knowledge store falls back to Postgres full-text search, so vector search is an
 * upgrade layered on top, never a hard dependency.
 */
interface EmbeddingClientInterface
{
    public function isAvailable(): bool;

    /** The fixed dimensionality of the vectors this client returns (the pgvector column width). */
    public function dimension(): int;

    /**
     * Embed one text. Returns null on any provider/transport failure so the
     * caller can store/search without a vector rather than erroring.
     *
     * @return list<float>|null
     */
    public function embed(string $text): ?array;

    /**
     * Embed many texts in one call. The result is index-aligned with $texts; any
     * element that failed is null.
     *
     * @param list<string> $texts
     *
     * @return list<list<float>|null>
     */
    public function embedBatch(array $texts): array;
}
