<?php

/**
 * The seam Worker's appointment-window lookups plug into (read-only, over openemr_postcalendar_events).
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
 * {@see AppointmentWindowReader} is the real, DB-backed implementation
 * {@see \OpenEMR\Modules\ClinicalCopilot\Worker::createDefault()} wires.
 * Behind an interface (mirroring every other collaborator seam in this
 * module -- `TraceRecorderInterface`, `AlertSinkInterface`,
 * `RateLimiterInterface`, ...) so DB-backed tests can substitute a fake that
 * throws or returns fixed appointment data without needing a real
 * `openemr_postcalendar_events` fixture for every scenario (e.g. proving I7:
 * one tick stage throwing never prevents the others from running).
 */
interface AppointmentWindowReaderInterface
{
    /**
     * @return list<DueAppointment>
     */
    public function dueForWarm(\DateTimeImmutable $now, \DateTimeImmutable $until): array;

    public function nextApptAt(int $pid, \DateTimeImmutable $now): ?\DateTimeImmutable;
}
