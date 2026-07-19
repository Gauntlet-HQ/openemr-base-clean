<?php

/**
 * The rerank seam (Cohere Rerank or equivalent) — degradable, like the LLM seam.
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
 * A cross-encoder reranker refines the candidate ordering the retriever
 * produced. It is an optional quality layer: {@see PassthroughReranker} is the
 * no-credentials default (keep the retriever's own order), and a Cohere/LLM
 * implementation slots in when configured — the same degrade-cleanly pattern as
 * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface}.
 */
interface RerankerInterface
{
    /**
     * @param list<EvidenceSnippet> $candidates
     *
     * @return list<EvidenceSnippet> best-first, at most $topK
     */
    public function rerank(string $query, array $candidates, int $topK): array;
}
