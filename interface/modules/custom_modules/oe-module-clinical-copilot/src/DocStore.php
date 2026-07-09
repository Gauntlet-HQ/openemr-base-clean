<?php

/**
 * Append-only repository over mod_copilot_doc, with T22 best-of-N selection.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Doc\DocRow;
use OpenEMR\Modules\ClinicalCopilot\Doc\NewDoc;
use OpenEMR\Modules\ClinicalCopilot\Doc\QaStatus;
use OpenEMR\Modules\ClinicalCopilot\Doc\RegenReason;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;

/**
 * I3/E7 (append-only): this class has exactly two public methods,
 * {@see self::insert()} and {@see self::findBest()}. There is no update or
 * delete method anywhere in this class or its own call sites -- deliberately.
 * The one documented exception, module-wide, is
 * {@see \OpenEMR\Modules\ClinicalCopilot\Observability\Qa\DocQaAnnotator}'s
 * advisory `qa_status`/`qa_score` UPDATE (T22 carve-out, guarded by
 * `WHERE qa_status = 'pending'` so it's idempotent; it never touches served
 * content -- `doc`, `verify_status`, `regen_reason` -- see docs/build-notes.md).
 * A reader auditing "can this ledger's SERVED content be mutated" only needs
 * this file's method list; auditing "can ANY column ever change post-insert"
 * also needs to check DocQaAnnotator.
 *
 * T22 (docs/build-notes.md "Warm timing + QA-driven rerun"): `mod_copilot_doc`
 * now carries best-of-N candidate narratives per `(pid, fact_digest)`
 * (the UNIQUE(pid, fact_digest) key was relaxed to a non-unique
 * `(pid, fact_digest, id)` index in table.sql/sql/install.sql -- see that
 * file's comment). {@see self::findBest()} implements the serve-selection
 * rule: the most recent row with `verify_status = 'passed'`, preferring a
 * higher `qa_score`; if none passed, the latest `degraded` row (facts-only,
 * I6). Still no LLM and no extra cost on the read path -- one indexed
 * lookup, same as before T22.
 */
final class DocStore
{
    /**
     * Inserts one new attempt. Always succeeds in adding a row -- there is
     * no upsert, no "replace the row for this digest" path; a second attempt
     * at the same `(pid, fact_digest)` is a new row that coexists with the
     * first (T22), never a mutation of it (E7).
     *
     * `qa_status`/`qa_score` are not accepted here (see {@see NewDoc}'s
     * docblock) -- they take their column defaults (`pending`/`NULL`) until
     * U12's QA sweep appends its own verdict via a later row, or (T22's
     * `mod_copilot_qa`, out of U6's scope) records against this row's id.
     *
     * @return int the new row's `id`
     */
    public function insert(NewDoc $newDoc): int
    {
        $sql = 'INSERT INTO `mod_copilot_doc`
            (`pid`, `fact_digest`, `doc_type`, `appt_id`, `doc`, `capability_versions`,
             `prompt_version`, `correlation_id`, `llm_latency_ms`, `tokens_in`, `tokens_out`,
             `cost_usd`, `excluded_counts`, `regen_reason`, `verify_status`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        return QueryUtils::sqlInsert($sql, [
            $newDoc->pid,
            $newDoc->factDigest,
            $newDoc->docType,
            $newDoc->apptId,
            self::encodeJson($newDoc->doc),
            self::encodeJson($newDoc->capabilityVersions),
            $newDoc->promptVersion,
            $newDoc->correlationId,
            $newDoc->llmLatencyMs,
            $newDoc->tokensIn,
            $newDoc->tokensOut,
            $newDoc->costUsd,
            $newDoc->excludedCounts !== null ? self::encodeJson($newDoc->excludedCounts) : null,
            $newDoc->regenReason->value,
            $newDoc->verifyStatus->value,
        ]);
    }

    /**
     * T22 serve-selection: current best for `(pid, fact_digest)` = most
     * recent `verify_status='passed'` row, preferring higher `qa_score`
     * (NULLs -- not yet QA-swept -- sort after any scored row, so recency
     * alone decides among not-yet-scored passed attempts); else the latest
     * `degraded` row; else null (a true cache miss -- the caller extracts
     * facts fresh and runs reduce+verify, this is not DocStore's concern).
     */
    public function findBest(int $pid, string $factDigest): ?DocRow
    {
        $passed = QueryUtils::querySingleRow(
            'SELECT * FROM `mod_copilot_doc`
             WHERE `pid` = ? AND `fact_digest` = ? AND `verify_status` = ?
             ORDER BY `qa_score` DESC, `id` DESC
             LIMIT 1',
            [$pid, $factDigest, VerifyStatus::Passed->value],
        );
        if (is_array($passed)) {
            return self::hydrate($passed);
        }

        $degraded = QueryUtils::querySingleRow(
            'SELECT * FROM `mod_copilot_doc`
             WHERE `pid` = ? AND `fact_digest` = ? AND `verify_status` = ?
             ORDER BY `id` DESC
             LIMIT 1',
            [$pid, $factDigest, VerifyStatus::Degraded->value],
        );
        if (is_array($degraded)) {
            return self::hydrate($degraded);
        }

        return null;
    }

    public function findByCorrelationId(string $correlationId): ?DocRow
    {
        $row = QueryUtils::querySingleRow(
            'SELECT * FROM `mod_copilot_doc` WHERE `correlation_id` = ? ORDER BY `id` DESC LIMIT 1',
            [$correlationId],
        );

        return is_array($row) ? self::hydrate($row) : null;
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

    /**
     * @param array<string, mixed> $value
     */
    private static function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function parseDateTime(string $value): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $parsed !== false ? $parsed : new \DateTimeImmutable($value);
    }
}
