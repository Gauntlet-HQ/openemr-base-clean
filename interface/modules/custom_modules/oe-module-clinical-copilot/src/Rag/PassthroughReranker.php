<?php

/**
 * The no-rerank default: keep the retriever's order, truncate to topK.
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
 * Used whenever no reranker is configured (the default in this environment).
 * Retrieval quality then rests entirely on the retriever's own scoring, which
 * is exactly the honest degraded behaviour — evidence is still returned, just
 * not cross-encoder-refined.
 */
final class PassthroughReranker implements RerankerInterface
{
    /**
     * @param list<EvidenceSnippet> $candidates
     *
     * @return list<EvidenceSnippet>
     */
    public function rerank(string $query, array $candidates, int $topK): array
    {
        return array_slice($candidates, 0, max(0, $topK));
    }
}
