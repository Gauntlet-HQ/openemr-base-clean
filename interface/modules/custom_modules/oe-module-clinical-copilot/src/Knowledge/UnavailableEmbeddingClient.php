<?php

/**
 * No-op embedding client used when no embedding provider is configured.
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
 * The default in an unconfigured environment: reports unavailable and returns no
 * vectors, so ingestion stores chunks without an embedding and retrieval falls
 * back to Postgres full-text search. Vector search simply switches on once an
 * embedding key is configured.
 */
final class UnavailableEmbeddingClient implements EmbeddingClientInterface
{
    public function __construct(private readonly int $dimension = 1536)
    {
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function dimension(): int
    {
        return $this->dimension;
    }

    public function embed(string $text): ?array
    {
        return null;
    }

    /**
     * @param list<string> $texts
     *
     * @return list<null>
     */
    public function embedBatch(array $texts): array
    {
        return array_fill(0, count($texts), null);
    }
}
