<?php

/**
 * The outcome of ingesting one uploaded document into a draft extraction.
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
 * Tells the endpoint where to redirect and how to frame the review page.
 * `visionUsed` false with `schemaRejected` false means a clean manual-entry
 * fallback (no model configured); `schemaRejected` true means the model ran but
 * its output failed the strict contract and was discarded — the physician
 * hand-enters instead. Either way there is a draft to review.
 */
final readonly class IngestResult
{
    public function __construct(
        public int $pid,
        public int $extractionId,
        public DocType $docType,
        public bool $visionUsed,
        public bool $schemaRejected,
    ) {
    }
}
