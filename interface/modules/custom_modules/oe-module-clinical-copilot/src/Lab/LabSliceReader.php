<?php

/**
 * The DB-facing lab slice reader: the 3-table join, activity=1 only.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\LabContractConfigProviderInterface;

/**
 * Thin orchestrator: fetches raw rows for one patient over `procedure_order`
 * (activity=1) -> `procedure_report` -> `procedure_result`, maps them into
 * {@see RawLabRow}, loads config via the injected
 * {@see LabContractConfigProviderInterface}, and hands both to the pure
 * {@see LabRowProcessor}. This class is the ONLY place in `src/Lab/` that
 * touches a database -- every contract decision (C1-C4, supersession,
 * exclusion) lives in LabRowProcessor and its collaborators, which are
 * fully unit-testable with in-memory rows.
 *
 * `result_code` has no `patient_id` column of its own (verified against
 * `sql/database.sql`): `procedure_result` -> `procedure_report` ->
 * `procedure_order` carries `patient_id`. The join below mirrors host
 * `ProcedureService`'s own join shape but is written directly (not reused)
 * because the contract semantics (status/supersession/parsing/units) are
 * this reader's job, not the host service's (per build-notes.md).
 */
final class LabSliceReader
{
    public function __construct(
        private readonly LabContractConfigProviderInterface $configProvider,
    ) {
    }

    /**
     * @param list<string> $loincCodes
     */
    public function read(int $pid, array $loincCodes, Capability $capability, string $capabilityVersion): LabSliceResult
    {
        $config = $this->configProvider->load();
        $rows = $this->fetchRawRows($pid, $loincCodes);

        return LabRowProcessor::process($rows, $config, $capability, $capabilityVersion);
    }

    /**
     * Drawn-but-unresulted orders (T10): an active order with NO
     * `procedure_report` row at all. Never counts as a result, never resets
     * the OverdueTests clock; PendingResults (U5) turns these into
     * `pending_order` Facts.
     *
     * @param list<string> $loincCodes
     * @return list<PendingOrderRow>
     */
    public function readPendingOrders(int $pid, array $loincCodes): array
    {
        if ($loincCodes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($loincCodes), '?'));
        $sql = "SELECT
                    po.patient_id AS patient_id,
                    po.procedure_order_id AS procedure_order_id,
                    poc.procedure_code AS result_code,
                    po.order_status AS order_status,
                    po.date_collected AS date_collected,
                    po.date_ordered AS date_ordered
                FROM `procedure_order` po
                INNER JOIN `procedure_order_code` poc ON poc.`procedure_order_id` = po.`procedure_order_id`
                LEFT JOIN `procedure_report` prep ON prep.`procedure_order_id` = po.`procedure_order_id`
                WHERE po.`activity` = 1
                    AND po.`patient_id` = ?
                    AND poc.`procedure_code` IN ($placeholders)
                    AND prep.`procedure_report_id` IS NULL";

        $records = QueryUtils::fetchRecords($sql, array_merge([$pid], $loincCodes));

        return array_map(self::mapPendingOrderRow(...), $records);
    }

    /**
     * @param list<string> $loincCodes
     * @return list<RawLabRow>
     */
    private function fetchRawRows(int $pid, array $loincCodes): array
    {
        if ($loincCodes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($loincCodes), '?'));
        $sql = "SELECT
                    po.patient_id AS patient_id,
                    pr.procedure_result_id AS procedure_result_id,
                    pr.result_code AS result_code,
                    pr.result_data_type AS result_data_type,
                    pr.result AS result_value,
                    pr.units AS units,
                    pr.result_status AS result_status,
                    pr.abnormal AS abnormal,
                    pr.range AS range_value,
                    prep.date_collected AS report_date_collected,
                    po.date_collected AS order_date_collected,
                    pr.date AS result_date,
                    prep.date_report AS report_date_report
                FROM `procedure_order` po
                INNER JOIN `procedure_report` prep ON prep.`procedure_order_id` = po.`procedure_order_id`
                INNER JOIN `procedure_result` pr ON pr.`procedure_report_id` = prep.`procedure_report_id`
                WHERE po.`activity` = 1
                    AND po.`patient_id` = ?
                    AND pr.`result_code` IN ($placeholders)";

        $records = QueryUtils::fetchRecords($sql, array_merge([$pid], $loincCodes));

        return array_map(self::mapRawLabRow(...), $records);
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function mapRawLabRow(array $record): RawLabRow
    {
        return new RawLabRow(
            (int)$record['patient_id'],
            (int)$record['procedure_result_id'],
            (string)$record['result_code'],
            (string)$record['result_data_type'],
            (string)$record['result_value'],
            (string)$record['units'],
            (string)$record['result_status'],
            (string)$record['abnormal'],
            (string)$record['range_value'],
            self::parseDateTime($record['report_date_collected'] ?? null),
            self::parseDateTime($record['order_date_collected'] ?? null),
            self::parseDateTime($record['result_date'] ?? null),
            self::parseDateTime($record['report_date_report'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function mapPendingOrderRow(array $record): PendingOrderRow
    {
        return new PendingOrderRow(
            (int)$record['patient_id'],
            (int)$record['procedure_order_id'],
            (string)$record['result_code'],
            (string)$record['order_status'],
            self::parseDateTime($record['date_collected'] ?? null),
            self::parseDateTime($record['date_ordered'] ?? null),
        );
    }

    private static function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '' || !is_string($value)) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $parsed !== false ? $parsed : null;
    }
}
