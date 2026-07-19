<?php

/**
 * Provenance for a knowledge document being ingested (title/source/section/url).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

/**
 * The citable identity of an uploaded knowledge document, carried onto every
 * chunk it produces so retrieved evidence still names where it came from. `source`
 * is required — it is the citation label ("ADA Standards of Care 2026") AND the
 * key the writer replaces on re-upload, so a corrected document cleanly supersedes
 * its previous chunks rather than duplicating them.
 */
final readonly class DocumentMetadata
{
    public function __construct(
        public string $title,
        public string $source,
        public string $section = '',
        public ?string $url = null,
    ) {
        if (trim($source) === '') {
            throw new \DomainException('DocumentMetadata.source is required (it is the citation label and the re-upload key)');
        }
    }
}
