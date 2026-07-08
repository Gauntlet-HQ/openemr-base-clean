<?php

/**
 * One scheduled appointment the warm worker considers on a tick.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Worker;

/**
 * Read from `openemr_postcalendar_events` (read-only, build-notes.md "Host
 * APIs" -- all existing OpenEMR tables are read-only to this module) by
 * {@see AppointmentWindowReader}. `apptAt` is `pc_eventDate` + `pc_startTime`
 * combined into one instant -- the moment the T-12h/T-1h/T-30min/T-5min
 * offsets in T22 (docs/build-notes.md "Warm timing + QA-driven rerun") are
 * measured against.
 */
final readonly class DueAppointment
{
    public function __construct(
        public int $pid,
        public \DateTimeImmutable $apptAt,
    ) {
    }
}
