<?php

/**
 * One row of the raw 3-table lab slice join, before any C1-C4 processing.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

/**
 * Deliberately a plain, directly-constructible value object (not built from
 * an untyped SQL row array) so isolated tests can hand {@see LabRowProcessor}
 * in-memory rows without any DB dependency. {@see LabSliceReader} is the only
 * place that maps real `procedure_order`/`procedure_report`/`procedure_result`
 * columns into this shape.
 */
final readonly class RawLabRow
{
    public function __construct(
        public int $patientId,
        public int $procedureResultId,
        public string $resultCode,
        public string $resultDataType,
        public string $result,
        public string $units,
        public string $resultStatus,
        public string $abnormal,
        public string $range,
        public ?\DateTimeImmutable $procedureReportDateCollected,
        public ?\DateTimeImmutable $procedureOrderDateCollected,
        public ?\DateTimeImmutable $procedureResultDate,
        public ?\DateTimeImmutable $procedureReportDateReport,
    ) {
    }
}
