<?php

/**
 * One row on the Clinical Co-Pilot schedule landing page.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

final readonly class ScheduledPatientRow
{
    public function __construct(
        public int $pid,
        public string $pubpid,
        public string $displayName,
        public string $appointmentTime,
        public string $appointmentTitle,
    ) {
    }
}
