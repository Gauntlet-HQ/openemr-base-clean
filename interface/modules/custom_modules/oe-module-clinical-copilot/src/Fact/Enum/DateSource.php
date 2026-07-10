<?php

/**
 * Clinical date provenance (two time axes, I4 / lab contract C1).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact\Enum;

/**
 * `Collected` = an authoritative specimen-collection date
 * (`procedure_report.date_collected` or `procedure_order.date_collected`).
 * `Fallback` = a date substituted only because no collection date exists
 * (`procedure_result.date` or `procedure_report.date_report`).
 */
enum DateSource: string
{
    case Collected = 'collected';
    case Fallback = 'fallback';
}
