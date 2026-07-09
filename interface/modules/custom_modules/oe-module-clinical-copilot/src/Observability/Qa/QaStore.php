<?php

/**
 * Append-only repository over mod_copilot_qa (idempotent on target_type+target_id).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Qa;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

/**
 * Mirrors {@see \OpenEMR\Modules\ClinicalCopilot\DocStore}'s discipline: the
 * only public methods are `insert()` and read methods -- no update, no
 * delete (table.sql: "append-only ... no UPDATE/DELETE path is ever
 * implemented against it"). Idempotency is enforced twice: application code
 * checks {@see self::existsFor()} before building a verdict (avoids doing
 * unnecessary LLM/metric work), and the table's own `UNIQUE(target_type,
 * target_id)` key is the actual guarantee (defends against a concurrent
 * second sweep run).
 */
final class QaStore
{
    public function __construct(
        private readonly SystemLogger $logger = new SystemLogger(),
    ) {
    }

    public function insert(NewQaVerdict $verdict): int
    {
        $sql = 'INSERT INTO `mod_copilot_qa`
            (`target_type`, `target_id`, `correlation_id`, `pid`, `user_id`, `model`,
             `concurs`, `salience_ok`, `flags`, `density_ratio`, `fact_utilization_rate`,
             `reviewer_note`, `tokens_in`, `tokens_out`, `cost_usd`, `status`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        return QueryUtils::sqlInsert($sql, [
            $verdict->targetType->value,
            $verdict->targetId,
            $verdict->correlationId,
            $verdict->pid,
            $verdict->userId,
            $verdict->model,
            $verdict->concurs === null ? null : (int)$verdict->concurs,
            $verdict->salienceOk === null ? null : (int)$verdict->salienceOk,
            self::encodeJson(array_map(static fn (QaFlag $f): array => $f->toArray(), $verdict->flags)),
            $verdict->densityRatio,
            $verdict->factUtilizationRate,
            $verdict->reviewerNote,
            $verdict->tokensIn,
            $verdict->tokensOut,
            $verdict->costUsd,
            $verdict->status,
        ]);
    }

    public function existsFor(QaTargetType $targetType, int $targetId): bool
    {
        $id = QueryUtils::fetchSingleValue(
            'SELECT `id` FROM `mod_copilot_qa` WHERE `target_type` = ? AND `target_id` = ?',
            'id',
            [$targetType->value, $targetId],
        );

        return $id !== null;
    }

    /**
     * A row whose `target_type` does not parse (a corrupt/hand-edited row)
     * is skipped from the listing rather than crashing the whole dashboard
     * -- see {@see self::hydrate()}.
     *
     * @return list<QaVerdictRow>
     */
    public function recent(int $limit = 200): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM `mod_copilot_qa` ORDER BY `id` DESC LIMIT ' . QueryUtils::escapeLimit($limit),
        );

        $verdicts = [];
        foreach ($rows as $row) {
            $verdict = $this->hydrate($row);
            if ($verdict !== null) {
                $verdicts[] = $verdict;
            }
        }

        return $verdicts;
    }

    /**
     * Returns null -- and logs once -- when `target_type` does not parse
     * into {@see QaTargetType}: a corrupt/hand-edited row, not a value this
     * method can safely coerce into either target type. The caller
     * ({@see self::recent()}) skips it rather than including a fabricated
     * target type in the dashboard listing.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ?QaVerdictRow
    {
        $targetType = QaTargetType::tryFrom((string)$row['target_type']);
        if ($targetType === null) {
            $this->logger->error('ClinicalCopilot: skipping mod_copilot_qa row with unrecognised target_type', [
                'qa_id' => (int)$row['id'],
                'target_type' => $row['target_type'],
            ]);

            return null;
        }

        $flagsRaw = $row['flags'] ?? null;
        $flagsDecoded = is_string($flagsRaw) ? json_decode($flagsRaw, true) : null;
        $flags = [];
        if (is_array($flagsDecoded)) {
            foreach ($flagsDecoded as $flagData) {
                if (is_array($flagData)) {
                    /** @var array<string, mixed> $flagData */
                    $flag = QaFlag::fromArray($flagData);
                    if ($flag !== null) {
                        $flags[] = $flag;
                    }
                }
            }
        }

        return new QaVerdictRow(
            (int)$row['id'],
            $targetType,
            (int)$row['target_id'],
            (string)$row['correlation_id'],
            (int)$row['pid'],
            $row['user_id'] !== null ? (int)$row['user_id'] : null,
            $row['model'] !== null ? (string)$row['model'] : null,
            $row['concurs'] !== null ? (bool)$row['concurs'] : null,
            $row['salience_ok'] !== null ? (bool)$row['salience_ok'] : null,
            $flags,
            $row['density_ratio'] !== null ? (float)$row['density_ratio'] : null,
            $row['fact_utilization_rate'] !== null ? (float)$row['fact_utilization_rate'] : null,
            $row['reviewer_note'] !== null ? (string)$row['reviewer_note'] : null,
            $row['tokens_in'] !== null ? (int)$row['tokens_in'] : null,
            $row['tokens_out'] !== null ? (int)$row['tokens_out'] : null,
            $row['cost_usd'] !== null ? (float)$row['cost_usd'] : null,
            (string)$row['status'],
            self::parseDateTime((string)$row['created_at']),
        );
    }

    /**
     * @param list<array<string, mixed>> $value
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
