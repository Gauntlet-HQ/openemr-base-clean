<?php

/**
 * A hydrated mod_copilot_extraction header row.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

final readonly class ExtractionRow
{
    public function __construct(
        public int $id,
        public int $pid,
        public DocType $docType,
        public ?int $sourceDocumentId,
        public ExtractionStatus $status,
        public ?string $model,
        public string $correlationId,
        public ?float $fieldAccuracy,
        public ?int $createdBy,
        public ?int $lockedBy,
        public ?LabIdentityStatus $identityStatus = null,
        public ?string $identityDetail = null,
        public ?string $collectionDate = null,
    ) {
    }

    public function isLocked(): bool
    {
        return $this->status === ExtractionStatus::Locked;
    }
}
