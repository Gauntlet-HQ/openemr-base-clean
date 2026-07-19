<?php

/**
 * One extracted field: the model's value, the human's value, and its citation.
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
 * The unit of the review UI and the accuracy metric. `vlmValue` is what the
 * model extracted (null on the manual-entry path); `value` is what the human
 * verified/edited and what gets committed. `editedByUser` is the ground-truth
 * accuracy signal — it flips true the moment `value` diverges from `vlmValue`.
 * Immutable; edits produce a new instance (wither), never a mutation.
 */
final readonly class ExtractedField
{
    public function __construct(
        public string $fieldKey,
        public ?string $vlmValue,
        public ?string $value,
        public ?string $unit = null,
        public ?string $refRange = null,
        public ?string $abnormalFlag = null,
        public ?SourceCitation $citation = null,
        public ?float $confidence = null,
        public bool $editedByUser = false,
        public ?string $committedCoreTable = null,
        public ?int $committedCorePk = null,
    ) {
        if ($fieldKey === '') {
            throw new \DomainException('ExtractedField.fieldKey must not be empty');
        }

        if ($confidence !== null && ($confidence < 0.0 || $confidence > 1.0)) {
            throw new \DomainException("ExtractedField.confidence must be in [0,1], got {$confidence}");
        }
    }

    /**
     * Returns a copy with the human-supplied value applied, recomputing
     * `editedByUser` from whether it diverges from the model's extraction.
     * Trailing/leading whitespace is normalized before the comparison so a
     * pure-whitespace "edit" is not counted as an extraction miss.
     */
    public function withHumanValue(?string $value): self
    {
        $normalized = $value === null ? null : trim($value);
        $edited = $this->normalize($normalized) !== $this->normalize($this->vlmValue);

        return new self(
            $this->fieldKey,
            $this->vlmValue,
            $normalized,
            $this->unit,
            $this->refRange,
            $this->abnormalFlag,
            $this->citation,
            $this->confidence,
            $edited,
            $this->committedCoreTable,
            $this->committedCorePk,
        );
    }

    public function withCommittedLineage(string $table, int $pk): self
    {
        return new self(
            $this->fieldKey,
            $this->vlmValue,
            $this->value,
            $this->unit,
            $this->refRange,
            $this->abnormalFlag,
            $this->citation,
            $this->confidence,
            $this->editedByUser,
            $table,
            $pk,
        );
    }

    public function isCommitted(): bool
    {
        return $this->committedCorePk !== null;
    }

    private function normalize(?string $v): string
    {
        return $v === null ? '' : trim($v);
    }
}
