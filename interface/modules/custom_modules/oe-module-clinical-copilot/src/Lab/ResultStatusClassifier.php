<?php

/**
 * Lab contract C2: `result_status` semantics.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;

/**
 * Pure, DB-independent. `result_status` is free-text varchar, default ''.
 *
 * Ambiguity resolved here (documented per the U4 report): C2's table lists
 * "cannot be done, incomplete, error, pending, canceled" and "unrecognized"
 * in two separate rows, both "excluded (flagged)". They are given two
 * distinct {@see ExclusionReason} values -- UnresultedStatus for the five
 * named unperformed-test statuses, UnrecognizedStatus for anything the
 * contract has never seen -- so downstream consumers (and the physician
 * reading the exclusion note) can tell "the test wasn't actually done" from
 * "this is a status string we don't understand yet." Both behave
 * identically for supersession/clock purposes; only the flag differs.
 */
final class ResultStatusClassifier
{
    private const UNRESULTED_STATUSES = ['cannot be done', 'incomplete', 'error', 'pending', 'canceled', 'cancelled'];

    private function __construct()
    {
        // static-only
    }

    public static function classify(string $resultStatus): StatusClassification
    {
        // result_status is a free-text varchar. Normalise case/whitespace and
        // accept the aliases OpenEMR's own HL7 result receivers actually write
        // (rhl7ReportStatus() in interface/orders/receive_hl7_results.inc.php
        // and the DORN receiver map C->'correct', P->'prelim') alongside the
        // contract's canonical spellings. Without this, a real HL7-imported
        // corrected result ('correct') falls to UnrecognizedStatus and is
        // dropped from its supersession group, leaving the stale prior value
        // presented as current -- the exact silent-replacement failure C2 /
        // USERS.md §1 exist to prevent.
        $status = strtolower(trim($resultStatus));

        return match (true) {
            $status === '' => StatusClassification::presented(FactStatus::Unstated, true, false, 1),
            $status === 'final' => StatusClassification::presented(FactStatus::Final, true, false, 2),
            in_array($status, ['corrected', 'correct'], true) => StatusClassification::presented(FactStatus::Corrected, true, false, 3),
            in_array($status, ['preliminary', 'prelim'], true) => StatusClassification::presented(FactStatus::Preliminary, false, true, 0),
            in_array($status, self::UNRESULTED_STATUSES, true) => StatusClassification::excluded(ExclusionReason::UnresultedStatus),
            default => StatusClassification::excluded(ExclusionReason::UnrecognizedStatus),
        };
    }
}
