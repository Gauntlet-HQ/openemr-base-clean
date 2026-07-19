<?php

/**
 * A `procedure_order` row with no `procedure_report` at all (drawn-but-unresulted, T10).
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
 * Absence-of-result is itself a first-class datum (T10): an active order
 * with no report row means "specimen may already be drawn, do not
 * reorder" -- structurally invisible to the result-slice join, which is
 * exactly why {@see LabSliceReader::readPendingOrders()} queries for it
 * separately with a `NOT EXISTS`-shaped join rather than trying to infer it
 * from an absence within {@see LabRowProcessor}, which only ever sees rows
 * that DO have a result.
 *
 * PendingResults (U5) turns these into `pending_order` Facts: never a
 * result, never a clock reset, and the input OverdueTests needs to decide
 * whether an overdue reorder-suppression note is warranted.
 */
final readonly class PendingOrderRow
{
    public function __construct(
        public int $patientId,
        public int $procedureOrderId,
        public string $resultCode,
        public string $orderStatus,
        public ?\DateTimeImmutable $dateCollected,
        public ?\DateTimeImmutable $dateOrdered,
    ) {
    }
}
