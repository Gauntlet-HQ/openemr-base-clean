<?php

/**
 * A hydrated mod_copilot_extracted_fact row (persisted id + domain field).
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
 * Wraps the immutable domain {@see ExtractedField} with its row id and parent
 * extraction id, so the review UI and ChartWriter can address the exact row to
 * update / record lineage against.
 */
final readonly class ExtractedFieldRow
{
    public function __construct(
        public int $id,
        public int $extractionId,
        public ExtractedField $field,
    ) {
    }
}
