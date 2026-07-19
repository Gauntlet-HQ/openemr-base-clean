<?php

/**
 * Lab contract C1: clinical-date precedence.
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

/**
 * Pure, DB-independent. Precedence (ARCHITECTURE_COMPLETE.md C1):
 * `procedure_report.date_collected` -> `procedure_order.date_collected` ->
 * `procedure_result.date` -> `procedure_report.date_report`. The first two
 * are authoritative collection dates (`date_source: collected`); the last
 * two are fallbacks (`date_source: fallback`). Never mixed with system
 * freshness (I4) -- this resolver has no notion of "now" at all.
 */
final class ClinicalDateResolver
{
    private function __construct()
    {
        // static-only
    }

    public static function resolve(RawLabRow $row): ClinicalDate
    {
        if ($row->procedureReportDateCollected !== null) {
            return new ClinicalDate($row->procedureReportDateCollected, DateSource::Collected, 'procedure_report.date_collected');
        }

        if ($row->procedureOrderDateCollected !== null) {
            return new ClinicalDate($row->procedureOrderDateCollected, DateSource::Collected, 'procedure_order.date_collected');
        }

        if ($row->procedureResultDate !== null) {
            return new ClinicalDate($row->procedureResultDate, DateSource::Fallback, 'procedure_result.date');
        }

        if ($row->procedureReportDateReport !== null) {
            return new ClinicalDate($row->procedureReportDateReport, DateSource::Fallback, 'procedure_report.date_report');
        }

        return new ClinicalDate(null, DateSource::Fallback, 'none');
    }
}
