<?php

/**
 * Builds in-memory RawLabRow fixtures for the U4 isolated test suite.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Lab;

use OpenEMR\Modules\ClinicalCopilot\Lab\RawLabRow;

final class RawLabRowBuilder
{
    private function __construct()
    {
        // static-only
    }

    /**
     * Builds a row whose C1 date precedence resolves via
     * `procedure_report.date_collected` (the top-priority, `collected`
     * source) -- the common case for a normally-processed lab.
     */
    public static function collected(
        int $patientId,
        int $procedureResultId,
        string $resultCode,
        string $resultDataType,
        string $result,
        string $units,
        string $resultStatus,
        string $date,
        string $abnormal = '',
        string $range = '',
    ): RawLabRow {
        $dt = new \DateTimeImmutable($date);

        return new RawLabRow(
            $patientId,
            $procedureResultId,
            $resultCode,
            $resultDataType,
            $result,
            $units,
            $resultStatus,
            $abnormal,
            $range,
            $dt,
            $dt,
            $dt,
            $dt,
        );
    }

    /**
     * A row with no collection date anywhere but a report date (C1
     * fallback): `date_source` must be `fallback`.
     */
    public static function fallbackDateOnly(
        int $patientId,
        int $procedureResultId,
        string $resultCode,
        string $resultDataType,
        string $result,
        string $units,
        string $resultStatus,
        string $reportDate,
    ): RawLabRow {
        return new RawLabRow(
            $patientId,
            $procedureResultId,
            $resultCode,
            $resultDataType,
            $result,
            $units,
            $resultStatus,
            '',
            '',
            null,
            null,
            null,
            new \DateTimeImmutable($reportDate),
        );
    }
}
