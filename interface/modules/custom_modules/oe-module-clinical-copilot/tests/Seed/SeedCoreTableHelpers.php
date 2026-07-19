<?php

/**
 * Shared core-OpenEMR-table insert/upsert helpers for CLI seed scripts.
 *
 * Extracted from SeedClinicalCopilot.php so SeedEndoCohort.php (and any
 * future seeder) does not duplicate the same patient_data / procedure_order
 * / procedure_report / procedure_result / prescriptions / lists /
 * form_vitals / openemr_postcalendar_events insert shapes. Composing classes
 * must declare and initialize `private readonly int $providerId` and
 * `private readonly \DateTimeImmutable $today` (used by insertScheduleEvent
 * callers, not by this trait directly) before calling these methods.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Seed;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;

trait SeedCoreTableHelpers
{
    private readonly int $providerId;

    private function resolveProviderId(): int
    {
        $id = QueryUtils::fetchSingleValue("SELECT `id` FROM `users` ORDER BY `id` ASC LIMIT 1", 'id');
        return $id !== null ? (int)$id : 1;
    }

    /**
     * "Today" as the DATABASE sees it (CURDATE()), not the PHP process's clock.
     * The scheduled-patient list matches appointments with
     * `pc_eventDate = CURDATE()`, so anchoring every seeded date -- above all the
     * appointment date -- to the DB's own today guarantees the demo patients
     * appear in the list even when PHP and MySQL are in different timezones (as
     * on Railway, where they are separate services and a PHP "today" can be a
     * day off from the MySQL CURDATE() the list compares against).
     */
    private function resolveToday(): \DateTimeImmutable
    {
        $curdate = QueryUtils::fetchSingleValue("SELECT CURDATE() AS `d`", 'd');

        return new \DateTimeImmutable(is_string($curdate) && $curdate !== '' ? $curdate : 'today');
    }

    private function findPidByPubpid(string $pubpid): ?int
    {
        $pid = QueryUtils::fetchSingleValue("SELECT `pid` FROM `patient_data` WHERE `pubpid` = ?", 'pid', [$pubpid]);
        return $pid !== null ? (int)$pid : null;
    }

    private function getFreshPid(): int
    {
        $pid = QueryUtils::fetchSingleValue("SELECT MAX(`pid`) + 1 AS pid FROM `patient_data`", 'pid');
        return $pid !== null ? (int)$pid : 1;
    }

    private function upsertPatientDemographics(string $pubpid, string $fname, string $lname, string $dob, string $sex): int
    {
        $existingPid = $this->findPidByPubpid($pubpid);
        if ($existingPid !== null) {
            QueryUtils::sqlStatementThrowException(
                "UPDATE `patient_data` SET `fname` = ?, `lname` = ?, `DOB` = ?, `sex` = ?, `date` = NOW() WHERE `pid` = ?",
                [$fname, $lname, $dob, $sex, $existingPid]
            );
            return $existingPid;
        }

        $pid = $this->getFreshPid();
        $uuid = (new UuidRegistry(['table_name' => 'patient_data']))->createUuid();
        QueryUtils::sqlInsert(
            "INSERT INTO `patient_data`
                (`uuid`, `pid`, `pubpid`, `fname`, `lname`, `DOB`, `sex`, `date`, `regdate`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 'clinical_copilot_synthetic')",
            [$uuid, $pid, $pubpid, $fname, $lname, $dob, $sex]
        );
        return $pid;
    }

    /**
     * Deletes this patient's dependent rows (labs, meds, vitals, schedule)
     * so re-running the seed against an existing patient is idempotent. The
     * patient_data row itself (and its pid) is left untouched.
     */
    private function clearDependentData(int $pid): void
    {
        $orderIds = QueryUtils::fetchRecords(
            "SELECT `procedure_order_id` FROM `procedure_order` WHERE `patient_id` = ?",
            [$pid]
        );
        $ids = array_map(static fn (array $row): int => (int)$row['procedure_order_id'], $orderIds);

        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            QueryUtils::sqlStatementThrowException(
                "DELETE `procedure_result` FROM `procedure_result`
                 INNER JOIN `procedure_report` ON `procedure_report`.`procedure_report_id` = `procedure_result`.`procedure_report_id`
                 WHERE `procedure_report`.`procedure_order_id` IN ($placeholders)",
                $ids
            );
            QueryUtils::sqlStatementThrowException(
                "DELETE FROM `procedure_report` WHERE `procedure_order_id` IN ($placeholders)",
                $ids
            );
            QueryUtils::sqlStatementThrowException(
                "DELETE FROM `procedure_order_code` WHERE `procedure_order_id` IN ($placeholders)",
                $ids
            );
            QueryUtils::sqlStatementThrowException(
                "DELETE FROM `procedure_order` WHERE `procedure_order_id` IN ($placeholders)",
                $ids
            );
        }

        QueryUtils::sqlStatementThrowException("DELETE FROM `prescriptions` WHERE `patient_id` = ?", [$pid]);
        QueryUtils::sqlStatementThrowException("DELETE FROM `lists` WHERE `pid` = ? AND `type` = 'medication'", [$pid]);
        QueryUtils::sqlStatementThrowException("DELETE FROM `form_vitals` WHERE `pid` = ?", [$pid]);
        QueryUtils::sqlStatementThrowException("DELETE FROM `openemr_postcalendar_events` WHERE `pc_pid` = ?", [(string)$pid]);
    }

    private function insertProcedureOrder(
        int $pid,
        \DateTimeImmutable $dateOrdered,
        ?\DateTimeImmutable $dateCollected,
        string $orderStatus,
        string $loincCode,
        string $analyteName
    ): int {
        $uuid = (new UuidRegistry(['table_name' => 'procedure_order', 'table_id' => 'procedure_order_id']))->createUuid();
        $orderId = QueryUtils::sqlInsert(
            "INSERT INTO `procedure_order`
                (`uuid`, `provider_id`, `patient_id`, `encounter_id`, `date_collected`, `date_ordered`, `order_priority`, `order_status`, `activity`, `procedure_order_type`)
             VALUES (?, ?, ?, 0, ?, ?, 'normal', ?, 1, 'laboratory_test')",
            [
                $uuid,
                $this->providerId,
                $pid,
                $dateCollected?->format('Y-m-d H:i:s'),
                $dateOrdered->format('Y-m-d H:i:s'),
                $orderStatus,
            ]
        );

        QueryUtils::sqlInsert(
            "INSERT INTO `procedure_order_code` (`procedure_order_id`, `procedure_order_seq`, `procedure_code`, `procedure_name`, `procedure_source`)
             VALUES (?, 1, ?, ?, '1')",
            [$orderId, $loincCode, $analyteName]
        );

        return $orderId;
    }

    private function insertProcedureReport(
        int $orderId,
        ?\DateTimeImmutable $dateCollected,
        ?\DateTimeImmutable $dateReport,
        string $reportStatus = 'complete'
    ): int {
        $uuid = (new UuidRegistry(['table_name' => 'procedure_report', 'table_id' => 'procedure_report_id']))->createUuid();
        return QueryUtils::sqlInsert(
            "INSERT INTO `procedure_report`
                (`uuid`, `procedure_order_id`, `procedure_order_seq`, `date_collected`, `date_report`, `report_status`, `review_status`)
             VALUES (?, ?, 1, ?, ?, ?, 'reviewed')",
            [
                $uuid,
                $orderId,
                $dateCollected?->format('Y-m-d H:i:s'),
                $dateReport?->format('Y-m-d H:i:s'),
                $reportStatus,
            ]
        );
    }

    private function insertProcedureResult(
        int $reportId,
        string $loincCode,
        string $resultText,
        string $resultDataType,
        string $result,
        string $units,
        string $resultStatus,
        ?\DateTimeImmutable $date,
        string $range = '',
        string $abnormal = ''
    ): int {
        $uuid = (new UuidRegistry(['table_name' => 'procedure_result', 'table_id' => 'procedure_result_id']))->createUuid();
        return QueryUtils::sqlInsert(
            "INSERT INTO `procedure_result`
                (`uuid`, `procedure_report_id`, `result_data_type`, `result_code`, `result_text`, `date`, `units`, `result`, `range`, `abnormal`, `result_status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$uuid, $reportId, $resultDataType, $loincCode, $resultText, $date?->format('Y-m-d H:i:s'), $units, $result, $range, $abnormal, $resultStatus]
        );
    }

    private function insertPrescription(
        int $pid,
        string $drug,
        string $dosage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        bool $active
    ): int {
        $uuid = (new UuidRegistry(['table_name' => 'prescriptions']))->createUuid();
        return QueryUtils::sqlInsert(
            "INSERT INTO `prescriptions`
                (`uuid`, `patient_id`, `provider_id`, `start_date`, `end_date`, `drug`, `dosage`, `active`, `date_added`, `datetime`, `txDate`,
                 `usage_category`, `usage_category_title`, `request_intent`, `request_intent_title`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'community', 'Home/Community', 'order', 'Order')",
            [
                $uuid,
                $pid,
                $this->providerId,
                $startDate->format('Y-m-d'),
                $endDate?->format('Y-m-d'),
                $drug,
                $dosage,
                $active ? 1 : 0,
                $startDate->format('Y-m-d H:i:s'),
                $startDate->format('Y-m-d H:i:s'),
                $startDate->format('Y-m-d'),
            ]
        );
    }

    private function insertOutsideMedListRow(int $pid, string $title, \DateTimeImmutable $begDate, string $comments): int
    {
        $uuid = (new UuidRegistry(['table_name' => 'lists']))->createUuid();
        return QueryUtils::sqlInsert(
            "INSERT INTO `lists` (`uuid`, `date`, `type`, `title`, `begdate`, `activity`, `pid`, `comments`)
             VALUES (?, NOW(), 'medication', ?, ?, 1, ?, ?)",
            [$uuid, $title, $begDate->format('Y-m-d H:i:s'), $pid, $comments]
        );
    }

    private function insertVital(int $pid, \DateTimeImmutable $date, ?float $weight, ?string $bps, ?string $bpd): int
    {
        $uuid = (new UuidRegistry(['table_name' => 'form_vitals']))->createUuid();
        return QueryUtils::sqlInsert(
            "INSERT INTO `form_vitals` (`uuid`, `date`, `pid`, `activity`, `bps`, `bpd`, `weight`)
             VALUES (?, ?, ?, 1, ?, ?, ?)",
            [$uuid, $date->format('Y-m-d H:i:s'), $pid, $bps, $bpd, $weight]
        );
    }

    private function insertScheduleEvent(int $pid, \DateTimeImmutable $eventDate, string $title, string $startTime = '08:50:00'): int
    {
        $start = \DateTimeImmutable::createFromFormat('!H:i:s', $startTime) ?: new \DateTimeImmutable('1970-01-01 08:50:00');
        $end = $start->modify('+15 minutes');
        $uuid = (new UuidRegistry(['table_name' => 'openemr_postcalendar_events', 'table_id' => 'pc_eid']))->createUuid();
        return QueryUtils::sqlInsert(
            "INSERT INTO `openemr_postcalendar_events`
                (`uuid`, `pc_catid`, `pc_multiple`, `pc_aid`, `pc_pid`, `pc_title`, `pc_time`, `pc_eventDate`, `pc_endDate`, `pc_duration`, `pc_startTime`, `pc_endTime`)
             VALUES (?, 5, 0, ?, ?, ?, NOW(), ?, ?, 900, ?, ?)",
            [
                $uuid,
                (string)$this->providerId,
                (string)$pid,
                $title,
                $eventDate->format('Y-m-d'),
                $eventDate->format('Y-m-d'),
                $start->format('H:i:s'),
                $end->format('H:i:s'),
            ]
        );
    }
}
