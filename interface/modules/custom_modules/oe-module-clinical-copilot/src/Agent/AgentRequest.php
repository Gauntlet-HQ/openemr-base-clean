<?php

/**
 * One request the supervisor routes: an optional document to extract and/or a question to ground.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

use OpenEMR\Modules\ClinicalCopilot\Ingest\DocType;

/**
 * The supervisor's routing is a pure function of this request's shape — which is
 * exactly why an LLM router is unnecessary here: {@see self::hasDocument()} and
 * {@see self::needsEvidence()} are knowable without asking a model. A request
 * may carry a document (extract it), a clinical question and/or notable analyte
 * `tags` (retrieve guideline evidence for them), or both.
 */
final readonly class AgentRequest
{
    /**
     * @param list<string> $tags notable analytes/topics to ground (e.g. the
     *        out-of-range analytes on a pre-visit summary)
     */
    public function __construct(
        public int $pid,
        public string $correlationId,
        public ?DocType $docType = null,
        public ?string $documentBytes = null,
        public ?string $mimeType = null,
        public ?string $question = null,
        public array $tags = [],
    ) {
    }

    public function hasDocument(): bool
    {
        return $this->docType !== null
            && $this->documentBytes !== null && $this->documentBytes !== ''
            && $this->mimeType !== null && $this->mimeType !== '';
    }

    public function needsEvidence(): bool
    {
        return ($this->question !== null && $this->question !== '') || $this->tags !== [];
    }

    /**
     * The retrieval query: the explicit question if present, else a query
     * synthesized from the notable tags (so "ground these analytes" still
     * retrieves without a typed question).
     */
    public function evidenceQuery(): string
    {
        if ($this->question !== null && $this->question !== '') {
            return $this->question;
        }

        return implode(' ', $this->tags);
    }
}
