<?php

/**
 * Telemetry retention: prune observability rows older than a fixed window.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmEnv;

/**
 * The observability tables ({@see \OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder}'s
 * `mod_copilot_trace`, its payload sidecar, the UI-event pings, and the QA
 * verdicts) grow unbounded — one row per span, per ping, per verdict, forever.
 * None of it is chart data: it is diagnostic telemetry with a short useful
 * life (the dashboard's default window is the last 24h; alerts evaluate the
 * last 15 minutes to an hour). This repository deletes rows past a retention
 * horizon — **default 3 days** — so the telemetry footprint stays bounded on a
 * small deployment's disk.
 *
 * It is a "cron SQL job" in the OpenEMR sense: the module's background worker
 * ({@see \OpenEMR\Modules\ClinicalCopilot\Worker::runTick()}, driven by the
 * `background_services` cron that is already a hard deployment requirement)
 * calls {@see WorkerTick::pruneTelemetry()} once per tick, which calls this.
 * No separate crontab entry and no MySQL event scheduler (often off by
 * default) are needed; the delete rides the cron the module already owns.
 *
 * What it prunes (module-owned telemetry only) and what it deliberately does
 * NOT touch:
 *   - prunes: `mod_copilot_trace`, `mod_copilot_trace_payload`,
 *     `mod_copilot_ui_event`, `mod_copilot_qa`.
 *   - never touches: `mod_copilot_doc` (the served synthesis cache),
 *     `mod_copilot_cadence` (config/heartbeat), the chat session/turn tables,
 *     or the Week 2 ingestion tables (`mod_copilot_extraction` /
 *     `mod_copilot_extracted_fact`) — those are records, not telemetry.
 *
 * This is one of the whitelisted core-write repositories (the module's PHPStan
 * ForbiddenWriteOutsideRepositoriesRule): the DELETE lives here, behind a
 * typed method, not scattered through the codebase.
 */
final class TelemetryRetention
{
    /** Override the retention horizon (whole days). Falsy/absent -> default. */
    public const ENV_RETENTION_DAYS = 'CLINICAL_COPILOT_TELEMETRY_RETENTION_DAYS';

    public const DEFAULT_RETENTION_DAYS = 3;

    /**
     * table => the DATETIME column that dates each row. Order is deliberate:
     * the payload sidecar is pruned right after its parent trace so a payload
     * never outlives the span it belongs to.
     *
     * @var array<string, string>
     */
    private const TELEMETRY_TABLES = [
        'mod_copilot_trace' => 'started_at',
        'mod_copilot_trace_payload' => 'created_at',
        'mod_copilot_ui_event' => 'created_at',
        'mod_copilot_qa' => 'created_at',
    ];

    public function __construct(private readonly SystemLogger $logger = new SystemLogger())
    {
    }

    /**
     * The configured retention horizon in whole days. Reads
     * {@see self::ENV_RETENTION_DAYS}; falls back to {@see self::DEFAULT_RETENTION_DAYS}.
     * Clamped to at least 1 day so a mis-set `0`/negative value can never turn
     * this into "delete everything just written."
     */
    public static function retentionDays(): int
    {
        $raw = trim(LlmEnv::getString(self::ENV_RETENTION_DAYS));
        if ($raw === '' || !ctype_digit($raw)) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        return max(1, (int)$raw);
    }

    /**
     * The cutoff instant: rows dated strictly before this are pruned.
     *
     * @param \DateTimeImmutable $now the reference "now" (injected for tests)
     */
    public static function cutoff(\DateTimeImmutable $now, ?int $retentionDays = null): \DateTimeImmutable
    {
        $days = $retentionDays ?? self::retentionDays();
        $days = max(1, $days);

        return $now->sub(new \DateInterval('P' . $days . 'D'));
    }

    /**
     * Delete telemetry rows older than the retention horizon.
     *
     * @param \DateTimeImmutable|null $now         reference now (defaults to real now)
     * @param int|null                $retentionDays override the env/default horizon
     * @return array<string, int> table => rows deleted
     */
    public function prune(?\DateTimeImmutable $now = null, ?int $retentionDays = null): array
    {
        $now ??= new \DateTimeImmutable();
        $cutoffSql = self::cutoff($now, $retentionDays)->format('Y-m-d H:i:s.u');

        $deleted = [];
        foreach (self::TELEMETRY_TABLES as $table => $dateColumn) {
            // $table and $dateColumn are class constants, never user input —
            // safe to interpolate; the cutoff is bound.
            QueryUtils::sqlStatementThrowException(
                "DELETE FROM `{$table}` WHERE `{$dateColumn}` < ?",
                [$cutoffSql],
            );
            $deleted[$table] = (int)QueryUtils::affectedRows();
        }

        $total = array_sum($deleted);
        if ($total > 0) {
            $this->logger->debug('ClinicalCopilot: telemetry retention prune', [
                'cutoff' => $cutoffSql,
                'rows_deleted' => $deleted,
                'total' => $total,
            ]);
        }

        return $deleted;
    }
}
