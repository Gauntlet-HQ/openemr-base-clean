<?php

/**
 * Today's appointment list for the Clinical Co-Pilot landing page (USERS.md §2).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Common\Database\QueryUtils;

/**
 * Read-only over `openemr_postcalendar_events` + `patient_data` (I9). This
 * is the entry surface when `doc.php` is opened without a `pid` — e.g. from
 * Reports → Clinical Co-Pilot — so the physician picks a scheduled patient
 * instead of hitting a dead-end error.
 */
final class ScheduledPatientListReader
{
    /** @var list<string> */
    private const CANCELED_STATUSES = ['x', '%'];

    /**
     * @return list<ScheduledPatientRow> soonest appointment per patient today
     */
    public function today(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::CANCELED_STATUSES), '?'));

        $rows = QueryUtils::fetchRecords(
            "SELECT
                pd.`pid`,
                pd.`pubpid`,
                pd.`fname`,
                pd.`mname`,
                pd.`lname`,
                e.`pc_startTime`,
                e.`pc_title`
             FROM `openemr_postcalendar_events` e
             INNER JOIN `patient_data` pd ON pd.`pid` = e.`pc_pid`
             WHERE e.`pc_eventDate` = CURDATE()
               AND e.`pc_apptstatus` NOT IN ({$placeholders})
             ORDER BY COALESCE(e.`pc_startTime`, '23:59:59') ASC, pd.`lname` ASC, pd.`fname` ASC",
            self::CANCELED_STATUSES,
        );

        /** @var array<int, ScheduledPatientRow> $byPid */
        $byPid = [];

        foreach ($rows as $row) {
            $pid = (int)($row['pid'] ?? 0);
            if ($pid <= 0 || isset($byPid[$pid])) {
                continue;
            }

            $byPid[$pid] = self::hydrateRow($row, $pid);
        }

        return array_values($byPid);
    }

    public function forPidToday(int $pid): ?ScheduledPatientRow
    {
        if ($pid <= 0) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count(self::CANCELED_STATUSES), '?'));

        $rows = QueryUtils::fetchRecords(
            "SELECT
                pd.`pid`,
                pd.`pubpid`,
                pd.`fname`,
                pd.`mname`,
                pd.`lname`,
                e.`pc_startTime`,
                e.`pc_title`
             FROM `openemr_postcalendar_events` e
             INNER JOIN `patient_data` pd ON pd.`pid` = e.`pc_pid`
             WHERE e.`pc_eventDate` = CURDATE()
               AND e.`pc_pid` = ?
               AND e.`pc_apptstatus` NOT IN ({$placeholders})
             ORDER BY COALESCE(e.`pc_startTime`, '23:59:59') ASC
             LIMIT 1",
            [$pid, ...self::CANCELED_STATUSES],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }

        $resolvedPid = (int)($row['pid'] ?? 0);
        if ($resolvedPid <= 0) {
            return null;
        }

        return self::hydrateRow($row, $resolvedPid);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateRow(array $row, int $pid): ScheduledPatientRow
    {
        $name = TextNormalizer::collapseSpaces(
            (string)($row['fname'] ?? '') . ' ' . (string)($row['mname'] ?? '') . ' ' . (string)($row['lname'] ?? '')
        );
        $pubpid = trim((string)($row['pubpid'] ?? ''));
        if ($pubpid === '') {
            $pubpid = "PID-{$pid}";
        }

        $startRaw = $row['pc_startTime'] ?? null;
        $appointmentTime = is_string($startRaw) && $startRaw !== ''
            ? substr($startRaw, 0, 5)
            : '—';

        $title = trim((string)($row['pc_title'] ?? ''));
        if ($title === '') {
            $title = 'Appointment';
        }

        return new ScheduledPatientRow(
            $pid,
            $pubpid,
            $name !== '' ? $name : $pubpid,
            $appointmentTime,
            $title,
        );
    }
}
