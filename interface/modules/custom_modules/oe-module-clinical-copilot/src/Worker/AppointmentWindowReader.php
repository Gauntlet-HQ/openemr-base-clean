<?php

/**
 * Read-only appointment-window queries over openemr_postcalendar_events for the warm worker.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Worker;

use OpenEMR\Common\Database\QueryUtils;

/**
 * `openemr_postcalendar_events` is a core, host-owned table -- this class
 * only ever SELECTs from it (build-notes.md: "All existing OpenEMR tables
 * are read-only to this module"). `pc_apptstatus` values `x` (Canceled) and
 * `%` (Canceled < 24h) are excluded from both queries below: a canceled
 * appointment does not need a pre-visit synthesis warmed, and including it
 * would waste the per-tick LLM budget on a visit that will not happen
 * (ARCHITECTURE.md §3.7).
 */
final class AppointmentWindowReader implements AppointmentWindowReaderInterface
{
    private const CANCELED_STATUSES = ['x', '%'];

    /**
     * ARCHITECTURE_COMPLETE.md "WORKER" block: "window = next clinic day's
     * appointments; full-window passes at T-12h and T-1h, then the 5-min
     * tick." Rather than special-casing "the T-12h pass" and "the T-1h
     * pass" as distinct code paths, this single query re-run every 5
     * minutes achieves the same coverage: an appointment enters the result
     * set the moment it comes within `$until` of `$now` and is re-swept
     * (cheaply, on a digest hit) every tick after that until the visit
     * itself -- by construction it has therefore already been through a
     * "T-12h-ish" and a "T-1h-ish" pass, and (per T22) is warmed well before
     * T-30min. One appointment per patient is returned (the SOONEST one in
     * the window) -- a patient with two events in one lookahead window is
     * warmed once, for the nearer visit.
     *
     * @return list<DueAppointment>
     */
    public function dueForWarm(\DateTimeImmutable $now, \DateTimeImmutable $until): array
    {
        return $this->earliestPerPid($this->fetchWindow(
            $now->format('Y-m-d H:i:s'),
            $until->format('Y-m-d H:i:s'),
        ));
    }

    /**
     * T22's QA-driven rerun cutoff ("now is before ~T-5min for that appt")
     * needs the SAME appointment-time notion the warm pass used, for a
     * SPECIFIC patient, independent of whatever window the current tick's
     * warm pass happened to sweep (a QA verdict can land for a doc whose
     * warm pass ran several ticks ago). Looks a little into the past
     * (`$now` minus 2 hours) so a visit that started slightly late is still
     * found, and up to 48 hours ahead so a same-day-tomorrow appointment
     * does not spuriously read as "no appointment" if the worker is
     * evaluating just after midnight.
     */
    public function nextApptAt(int $pid, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $windowStart = $now->modify('-2 hours')->format('Y-m-d H:i:s');
        $windowEnd = $now->modify('+48 hours')->format('Y-m-d H:i:s');

        $placeholders = implode(',', array_fill(0, count(self::CANCELED_STATUSES), '?'));
        $rows = QueryUtils::fetchRecords(
            "SELECT `pc_eventDate`, `pc_startTime`
             FROM `openemr_postcalendar_events`
             WHERE `pc_pid` = ?
               AND TIMESTAMP(`pc_eventDate`, COALESCE(`pc_startTime`, '00:00:00')) BETWEEN ? AND ?
               AND `pc_apptstatus` NOT IN ({$placeholders})
             ORDER BY TIMESTAMP(`pc_eventDate`, COALESCE(`pc_startTime`, '00:00:00')) ASC
             LIMIT 1",
            [(string)$pid, $windowStart, $windowEnd, ...self::CANCELED_STATUSES],
        );

        if ($rows === []) {
            return null;
        }

        return self::toDateTime($rows[0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchWindow(string $windowStartSql, string $windowEndSql): array
    {
        $placeholders = implode(',', array_fill(0, count(self::CANCELED_STATUSES), '?'));

        return QueryUtils::fetchRecords(
            "SELECT `pc_pid`, `pc_eventDate`, `pc_startTime`
             FROM `openemr_postcalendar_events`
             WHERE TIMESTAMP(`pc_eventDate`, COALESCE(`pc_startTime`, '00:00:00')) BETWEEN ? AND ?
               AND `pc_apptstatus` NOT IN ({$placeholders})
             ORDER BY TIMESTAMP(`pc_eventDate`, COALESCE(`pc_startTime`, '00:00:00')) ASC",
            [$windowStartSql, $windowEndSql, ...self::CANCELED_STATUSES],
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<DueAppointment>
     */
    private function earliestPerPid(array $rows): array
    {
        /** @var array<int, DueAppointment> $byPid */
        $byPid = [];

        foreach ($rows as $row) {
            $pidRaw = $row['pc_pid'] ?? null;
            if (!is_string($pidRaw) && !is_int($pidRaw)) {
                continue;
            }
            $pid = (int)$pidRaw;
            if ($pid <= 0 || isset($byPid[$pid])) {
                // Rows are already ordered soonest-first, so the first row
                // seen for a pid is its soonest appointment in this window.
                continue;
            }

            $apptAt = self::toDateTime($row);
            if ($apptAt === null) {
                continue;
            }

            $byPid[$pid] = new DueAppointment($pid, $apptAt);
        }

        return array_values($byPid);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toDateTime(array $row): ?\DateTimeImmutable
    {
        $eventDate = $row['pc_eventDate'] ?? null;
        if (!is_string($eventDate) || $eventDate === '') {
            return null;
        }
        $startTimeRaw = $row['pc_startTime'] ?? null;
        $startTime = is_string($startTimeRaw) && $startTimeRaw !== '' ? $startTimeRaw : '00:00:00';

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', "{$eventDate} {$startTime}");

        return $parsed !== false ? $parsed : null;
    }
}
