<?php

/**
 * The result of C1 clinical-date precedence resolution for one lab row.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;

final readonly class ClinicalDate
{
    public function __construct(
        public ?\DateTimeImmutable $date,
        public DateSource $source,
        /**
         * Which physical field this date came from -- becomes the citation
         * `field` for the date-bearing evidence (I5: no silent provenance loss).
         */
        public string $sourceField,
    ) {
    }
}
