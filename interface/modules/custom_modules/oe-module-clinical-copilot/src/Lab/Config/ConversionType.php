<?php

/**
 * Shape of a per-unit conversion rule (C4).
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
 * `mod_copilot_cadence.code_set = 'unit_conversion'` stores each rule as
 * either a plain multiplier (glucose, cholesterol, triglycerides) or a named
 * formula (A1c IFCC mmol/mol -> NGSP %). Formulas are matched by a closed set
 * of known keys -- never evaluated as arbitrary expressions -- so an
 * unrecognized formula can never silently do the wrong math (C4: no unit
 * guessing extends to no formula guessing).
 */
enum ConversionType: string
{
    case Multiplier = 'multiplier';
    case IfccToNgsp = 'ifcc_to_ngsp';
}
