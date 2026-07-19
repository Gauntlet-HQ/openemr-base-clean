<?php

/**
 * One retrieved piece of guideline evidence: a chunk, a score, and its citation.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Rag;

use OpenEMR\Modules\ClinicalCopilot\Ingest\SourceCitation;
use OpenEMR\Modules\ClinicalCopilot\Ingest\SourceType;

/**
 * The retriever's output unit. Its {@see SourceCitation} carries
 * {@see SourceType::Guideline} — the SAME contract document extractions use, but
 * a distinct source type — so guideline evidence and patient-record facts can
 * ride the same rendering path while staying structurally separate (the doc's
 * "separate patient-record facts from guideline evidence" rule, enforced by the
 * type, not by convention).
 */
final readonly class EvidenceSnippet
{
    public function __construct(
        public GuidelineChunk $chunk,
        public float $score,
        public SourceCitation $citation,
    ) {
    }

    public static function forChunk(GuidelineChunk $chunk, float $score): self
    {
        // Full guideline provenance on the citation itself: source + section +
        // chunk id + quote + url — so a consumer of the citation alone (wire
        // responses, exports) can attribute the evidence without reaching back
        // into the chunk.
        return new self(
            $chunk,
            $score,
            new SourceCitation(
                sourceType: SourceType::Guideline,
                sourceId: $chunk->source,
                pageOrSection: $chunk->section !== '' ? $chunk->section : null,
                fieldOrChunkId: $chunk->id,
                quoteOrValue: $chunk->excerpt(),
                url: $chunk->url,
            ),
        );
    }

    public function withScore(float $score): self
    {
        return new self($this->chunk, $score, $this->citation);
    }
}
