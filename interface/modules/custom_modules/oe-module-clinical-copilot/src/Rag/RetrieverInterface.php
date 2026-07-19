<?php

/**
 * The one seam the evidence-retriever worker and the summarizer/chat depend on.
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
 * Callers depend on this interface, never a concrete retriever — so the
 * summarizer, chat tool, and tests all bind whichever implementation fits
 * (sparse-only offline, hybrid+rerank when configured, or a stub). Retrieval is
 * deterministic and side-effect free: no LLM invents evidence, the retriever
 * only surfaces chunks that are literally in the committed corpus.
 */
interface RetrieverInterface
{
    /**
     * @param list<string> $tags optional analyte/topic tags to boost (e.g. the
     *        out-of-range analytes on the pre-visit summary), so the summarizer
     *        can ask for "the guideline behind THIS fact" without an LLM.
     *
     * @return list<EvidenceSnippet> best-first, at most $topK
     */
    public function retrieve(string $query, array $tags = [], int $topK = 4): array;
}
