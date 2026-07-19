<?php

/**
 * The Week 2 machine-readable citation contract for a document-sourced fact.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

/**
 * The spec's minimum citation shape — {source_type, source_id, page_or_section,
 * field_or_chunk_id, quote_or_value} — plus the page bounding box that powers
 * the required visual overlay. `page_or_section` carries either a page NUMBER
 * (document extractions) or a section LABEL (guideline chunks, e.g. "Glycemic
 * Targets"), and `url` is the optional guideline source link — so a guideline
 * citation is fully provenanced: source, section, chunk id, quote, url.
 * This is deliberately a SEPARATE value object
 * from the Week 1 {@see \OpenEMR\Modules\ClinicalCopilot\Fact\Citation} (which
 * points at a live core-table row and whose `table`/`pk > 0` invariants a
 * document source cannot satisfy). Once a locked lab commits to
 * `procedure_result`, the Week 1 lab reader re-grounds it in a normal core-table
 * Citation on read — so the two citation models never have to merge.
 */
final readonly class SourceCitation
{
    public function __construct(
        public SourceType $sourceType,
        public string $sourceId,
        public int|string|null $pageOrSection,
        public ?string $fieldOrChunkId,
        public string $quoteOrValue,
        public ?BoundingBox $bbox = null,
        public ?string $url = null,
    ) {
        if ($sourceId === '') {
            throw new \DomainException('SourceCitation.sourceId must not be empty');
        }

        if ($quoteOrValue === '') {
            throw new \DomainException('SourceCitation.quoteOrValue must not be empty');
        }
    }

    /**
     * @return array{source_type: string, source_id: string, page_or_section: int|string|null, field_or_chunk_id: string|null, quote_or_value: string, bbox: list<int>|null, url: string|null}
     */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType->value,
            'source_id' => $this->sourceId,
            'page_or_section' => $this->pageOrSection,
            'field_or_chunk_id' => $this->fieldOrChunkId,
            'quote_or_value' => $this->quoteOrValue,
            'bbox' => $this->bbox?->toArray(),
            'url' => $this->url,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $sourceTypeRaw = $data['source_type'] ?? null;
        if (!is_string($sourceTypeRaw) || SourceType::tryFrom($sourceTypeRaw) === null) {
            throw new \InvalidArgumentException('SourceCitation.source_type is missing or unrecognized');
        }

        $sourceId = $data['source_id'] ?? null;
        if (!is_string($sourceId)) {
            throw new \InvalidArgumentException('SourceCitation.source_id must be a string');
        }

        $quote = $data['quote_or_value'] ?? null;
        if (!is_string($quote)) {
            throw new \InvalidArgumentException('SourceCitation.quote_or_value must be a string');
        }

        // A page NUMBER (documents) or a section LABEL (guidelines) — both legal.
        $page = $data['page_or_section'] ?? null;
        if ($page !== null && !is_int($page) && !is_string($page)) {
            throw new \InvalidArgumentException('SourceCitation.page_or_section must be an int, a string, or null');
        }

        $fieldOrChunk = $data['field_or_chunk_id'] ?? null;
        if ($fieldOrChunk !== null && !is_string($fieldOrChunk)) {
            throw new \InvalidArgumentException('SourceCitation.field_or_chunk_id must be a string or null');
        }

        $url = $data['url'] ?? null;
        if ($url !== null && !is_string($url)) {
            throw new \InvalidArgumentException('SourceCitation.url must be a string or null');
        }

        $bbox = null;
        if (isset($data['bbox']) && is_array($data['bbox']) && count($data['bbox']) === 4) {
            $c = array_values($data['bbox']);
            $bbox = new BoundingBox((int)$c[0], (int)$c[1], (int)$c[2], (int)$c[3]);
        }

        return new self(
            SourceType::from($sourceTypeRaw),
            $sourceId,
            $page,
            $fieldOrChunk,
            $quote,
            $bbox,
            $url,
        );
    }
}
