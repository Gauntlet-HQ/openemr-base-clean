<?php

/**
 * No-op AlertSinkInterface, for tests that don't care about sev-1 delivery.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Verify\Sev1Signal;

final class NullAlertSink implements AlertSinkInterface
{
    public function sev1PatientIdentity(Sev1Signal $signal): void
    {
        // Intentionally empty.
    }
}
