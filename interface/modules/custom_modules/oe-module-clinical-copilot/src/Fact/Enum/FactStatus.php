<?php

/**
 * Fact-level status (lab contract C2 and general presentation status).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact\Enum;

enum FactStatus: string
{
    case Final = 'final';
    case Corrected = 'corrected';
    case Unstated = 'unstated';
    case Preliminary = 'preliminary';
    case Excluded = 'excluded';
}
