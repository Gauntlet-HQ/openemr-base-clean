<?php

/**
 * A presented (non-excluded) lab-slice Fact plus contract metadata capabilities need.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

/**
 * The base `Fact` here is always `kind: result` -- U4 resolves C1-C4
 * (date, status, value parsing, units, supersession) but deliberately does
 * NOT decide whether a datum is a `trend_point`, `overdue_item`,
 * `pending_order`, or `preliminary_result`: that re-kinding is a
 * capability-level judgment (U5) informed by `resetsClock`/`inFlight` here
 * and by cross-row context (e.g. "is this part of a multi-point series")
 * that only the capability has.
 */
final readonly class PresentedLabFact
{
    public function __construct(
        public Fact $fact,
        /** Per C2: does this row's status reset the OverdueTests clock? */
        public bool $resetsClock,
        /** Per C2: preliminary results render in the in-flight section, never as a trend point. */
        public bool $inFlight,
        public OutOfRangeResult $outOfRange,
    ) {
    }
}
