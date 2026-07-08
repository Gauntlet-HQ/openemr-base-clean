<?php

/**
 * Closed set of reasons a lab-slice row is excluded-and-flagged (I5, C2-C4).
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
 * Serialized into the Fact `flags` set as `excluded_reason:<value>`
 * (see {@see \OpenEMR\Modules\ClinicalCopilot\Fact\Flag::excludedReason()}).
 *
 * Ambiguity resolved here (documented per build-notes.md and the U4 report):
 * C2 lists two distinct "excluded" rows in one table cell -- the five
 * unperformed-test statuses (`cannot be done`, `incomplete`, `error`,
 * `pending`, `canceled`) and the catch-all "unrecognized" status. Both
 * exclude-and-flag and neither resets the overdue clock, but they are
 * different failure modes worth distinguishing in the flag (an unperformed
 * test vs. a status string the contract has never seen), so they get two
 * reasons: UnresultedStatus and UnrecognizedStatus.
 */
enum ExclusionReason: string
{
    case Unitless = 'unitless';
    case UnresultedStatus = 'unresulted_status';
    case UnrecognizedStatus = 'unrecognized_status';
    case UnparseableValue = 'unparseable_value';
}
