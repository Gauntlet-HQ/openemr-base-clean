<?php

/**
 * A validated, typed extraction: the set of fields pulled from one document.
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
 * Parse, don't validate: raw VLM JSON is turned into this typed object exactly
 * once, at the ingestion boundary, after {@see ExtractionSchema::validate()}
 * has passed. Downstream code (review UI, ChartWriter) works only with the
 * typed fields and never re-parses the model output. The schema is the source
 * of truth — a payload that fails validation never becomes a ParsedExtraction.
 */
final readonly class ParsedExtraction
{
    /**
     * @param list<ExtractedField> $fields
     * @param string|null          $patientName    document-header patient name (labs only; null otherwise)
     * @param string|null          $patientDob     document-header patient DOB (labs only; null otherwise)
     * @param string|null          $collectionDate printed specimen collection date, normalized to strict
     *                                             Y-m-d (labs only); null when not printed, not parseable,
     *                                             or non-lab — the review screen then defaults to today
     */
    public function __construct(
        public DocType $docType,
        public array $fields,
        public ?string $patientName = null,
        public ?string $patientDob = null,
        public ?string $collectionDate = null,
    ) {
    }

    /**
     * Extraction accuracy = fields the human accepted unchanged / fields the
     * model actually proposed a value for. Manual-entry fields (`vlmValue`
     * null) are excluded from the denominator: there is no model claim to be
     * right or wrong about. Returns null when the model proposed nothing (a
     * fully manual entry), so callers can distinguish "100% accurate" from
     * "no measurement to make".
     */
    public function fieldAccuracy(): ?float
    {
        $proposed = array_filter($this->fields, static fn (ExtractedField $f): bool => $f->vlmValue !== null);
        $total = count($proposed);
        if ($total === 0) {
            return null;
        }

        $accepted = count(array_filter($proposed, static fn (ExtractedField $f): bool => !$f->editedByUser));

        return $accepted / $total;
    }

    /**
     * @return list<ExtractedField>
     */
    public function editedFields(): array
    {
        return array_values(array_filter($this->fields, static fn (ExtractedField $f): bool => $f->editedByUser));
    }

    public function findField(string $fieldKey): ?ExtractedField
    {
        foreach ($this->fields as $field) {
            if ($field->fieldKey === $fieldKey) {
                return $field;
            }
        }

        return null;
    }
}
