<?php

/**
 * Loads the versioned config the lab contract needs to evaluate a slice.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab\Config;

/**
 * The only seam {@see \OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader}
 * needs a database for. {@see DbLabContractConfigProvider} is the production
 * implementation (`mod_copilot_cadence` via QueryUtils); tests substitute a
 * trivial closure-backed or direct implementation instead of touching a
 * database.
 */
interface LabContractConfigProviderInterface
{
    public function load(): LabContractConfig;
}
