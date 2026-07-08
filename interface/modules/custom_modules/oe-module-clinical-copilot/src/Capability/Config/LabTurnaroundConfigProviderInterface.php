<?php

/**
 * Loads the versioned lab-turnaround config PendingResults needs.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability\Config;

/**
 * The only seam {@see \OpenEMR\Modules\ClinicalCopilot\Capability\PendingResults}
 * needs a database for beyond U4's LabSliceReader. {@see DbLabTurnaroundConfigProvider}
 * is the production implementation; isolated tests substitute a
 * directly-constructed {@see LabTurnaroundConfig} instead of touching a
 * database -- same pattern as U4's
 * {@see \OpenEMR\Modules\ClinicalCopilot\Lab\Config\LabContractConfigProviderInterface}.
 */
interface LabTurnaroundConfigProviderInterface
{
    public function load(): LabTurnaroundConfig;
}
