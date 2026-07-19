<?php

/**
 * Read-only history listing over mod_copilot_doc: "what was the physician shown, over time."
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
use OpenEMR\Modules\ClinicalCopilot\Doc\DocRow;
use OpenEMR\Modules\ClinicalCopilot\Doc\QaStatus;
use OpenEMR\Modules\ClinicalCopilot\Doc\RegenReason;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;

/**
 * Deliberately NOT a method on {@see \OpenEMR\Modules\ClinicalCopilot\DocStore}:
 * that class's own append-only eval
 * (tests/Db/DocStore/DocStoreTest.php::testNoUpdateOrDeleteMethodExistsOnDocStore)
 * asserts its public surface is EXACTLY `insert()`/`findBest()` -- a
 * reader-auditing invariant U6 built on purpose (I3/E7). Adding a third
 * public method there to serve the history view would break that test for
 * no correctness gain, since a plain SELECT needs no append-only guarantee
 * to begin with. This class is a second, independent, read-only view over
 * the SAME table -- it can never write (no method here executes anything
 * but a SELECT), so it cannot violate I3 either.
 */
final class DocHistoryReader
{
    /**
     * @return list<DocRow> every attempt ever recorded for this pid, `ORDER BY computed_at DESC` (T7's ledger, viewed oldest-attempt-last)
     */
    public function forPid(int $pid, int $limit = 50): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM `mod_copilot_doc` WHERE `pid` = ? ORDER BY `computed_at` DESC, `id` DESC LIMIT ?',
            [$pid, $limit],
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): DocRow
    {
        $doc = json_decode((string)$row['doc'], true);
        $capabilityVersions = json_decode((string)$row['capability_versions'], true);
        $excludedCountsRaw = $row['excluded_counts'] ?? null;
        $excludedCounts = is_string($excludedCountsRaw) ? json_decode($excludedCountsRaw, true) : null;

        return new DocRow(
            (int)$row['id'],
            (int)$row['pid'],
            (string)$row['fact_digest'],
            (string)$row['doc_type'],
            $row['appt_id'] !== null ? (int)$row['appt_id'] : null,
            is_array($doc) ? $doc : [],
            is_array($capabilityVersions) ? $capabilityVersions : [],
            (string)$row['prompt_version'],
            self::parseDateTime((string)$row['computed_at']),
            (string)$row['correlation_id'],
            $row['llm_latency_ms'] !== null ? (int)$row['llm_latency_ms'] : null,
            $row['tokens_in'] !== null ? (int)$row['tokens_in'] : null,
            $row['tokens_out'] !== null ? (int)$row['tokens_out'] : null,
            $row['cost_usd'] !== null ? (float)$row['cost_usd'] : null,
            is_array($excludedCounts) ? $excludedCounts : null,
            QaStatus::tryFrom((string)$row['qa_status']) ?? QaStatus::Pending,
            $row['qa_score'] !== null ? (float)$row['qa_score'] : null,
            RegenReason::tryFrom((string)$row['regen_reason']) ?? RegenReason::None,
            VerifyStatus::tryFrom((string)$row['verify_status']) ?? VerifyStatus::Degraded,
        );
    }

    private static function parseDateTime(string $value): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $parsed !== false ? $parsed : new \DateTimeImmutable($value);
    }
}
