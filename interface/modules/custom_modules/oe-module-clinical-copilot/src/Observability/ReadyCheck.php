<?php

/**
 * Dependency-checking readiness probe (ARCHITECTURE.md §3.4).
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
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceCircuitBreaker;

/**
 * "GET /copilot/ready -- genuine dependency checks with timeouts: DB
 * round-trip through QueryUtils, module tables writable (INSERT+ROLLBACK
 * probe on trace table), LLM provider reachable via a Vertex countTokens
 * call ..., background worker heartbeat fresh, circuit-breaker state ...
 * Unauthenticated but REDACTED: status enums only ... no latencies, no
 * config values, no PHI ... and per-IP rate-limited" (ARCHITECTURE.md §3.4).
 *
 * Every check is independently caught -- a DB outage must not prevent
 * `/ready` from still reporting the LLM/breaker fields it CAN determine
 * (each field degrades to its own honest failure enum rather than the whole
 * endpoint 500ing), matching I6: "degraded-but-serving states are reported
 * honestly."
 */
final class ReadyCheck
{
    public function __construct(
        private readonly CadenceCircuitBreaker $breaker = new CadenceCircuitBreaker(),
        private readonly LlmReachabilityProbe $llmProbe = new LlmReachabilityProbe(),
    ) {
    }

    /**
     * @return array{
     *     status: string, db: string, tables_writable: string,
     *     llm: string, worker_heartbeat: string, breaker: string
     * }
     */
    public function check(): array
    {
        $db = $this->checkDb();
        $tablesWritable = $this->checkTablesWritable();
        $breakerOpen = $this->checkBreakerOpen();
        $llm = $breakerOpen === true ? 'circuit-open' : $this->checkLlm();
        $heartbeat = $this->checkHeartbeat();

        // Overall status: 'ok' only when the hard dependencies (DB, tables)
        // are sound. LLM-unreachable/circuit-open and a stale heartbeat are
        // DEGRADED-but-serving states (I6: reads still work; chat degrades
        // to a facts browser; warm coverage degrades to read-time
        // generation) -- reported honestly as 'degraded', never folded into
        // 'ok' and never escalated to a hard failure that would make an
        // orchestrator restart a perfectly healthy process.
        $status = ($db === 'ok' && $tablesWritable === 'ok') ? 'ok' : 'error';
        if ($status === 'ok' && ($llm !== 'ok' || $heartbeat !== 'ok')) {
            $status = 'degraded';
        }

        return [
            'status' => $status,
            'db' => $db,
            'tables_writable' => $tablesWritable,
            'llm' => $llm,
            'worker_heartbeat' => $heartbeat,
            'breaker' => $breakerOpen === null ? 'unknown' : ($breakerOpen ? 'open' : 'closed'),
        ];
    }

    private function checkDb(): string
    {
        try {
            $value = QueryUtils::fetchSingleValue('SELECT 1 AS v', 'v');

            return $value !== null ? 'ok' : 'error';
        } catch (\Throwable) {
            return 'error';
        }
    }

    /**
     * INSERT + ROLLBACK probe on the trace table itself -- proves the module
     * tables are writable (permissions, disk, engine health) without leaving
     * any row behind. Deliberately does NOT reuse a shared, longer-lived
     * transaction: this probe owns its own transaction start/rollback so a
     * caller composing this into a larger request never has its own
     * transaction state disturbed.
     */
    private function checkTablesWritable(): string
    {
        try {
            QueryUtils::startTransaction();
            QueryUtils::sqlInsert(
                'INSERT INTO `mod_copilot_trace`
                    (`correlation_id`, `span_id`, `kind`, `started_at`, `status`, `pid`)
                 VALUES (?, ?, ?, ?, ?, ?)',
                ['ready-probe', bin2hex(random_bytes(8)), 'render', (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'), 'ok', 0],
            );
            QueryUtils::rollbackTransaction();

            return 'ok';
        } catch (\Throwable) {
            try {
                QueryUtils::rollbackTransaction();
            } catch (\Throwable) {
                // best-effort cleanup only
            }

            return 'error';
        }
    }

    private function checkBreakerOpen(): ?bool
    {
        try {
            return $this->breaker->isOpen();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return 'ok'|'unreachable'
     */
    private function checkLlm(): string
    {
        try {
            return $this->llmProbe->probe();
        } catch (\Throwable) {
            return 'unreachable';
        }
    }

    /**
     * @return 'ok'|'stale'|'unknown'
     */
    private function checkHeartbeat(): string
    {
        try {
            $raw = QueryUtils::fetchSingleValue(
                "SELECT `config_json` FROM `mod_copilot_cadence` WHERE `code_set` = 'worker_heartbeat'",
                'config_json',
            );
            $config = is_string($raw) ? json_decode($raw, true) : null;
            $lastTickAt = is_array($config) && is_string($config['last_tick_at'] ?? null) ? $config['last_tick_at'] : null;
            if ($lastTickAt === null) {
                return 'unknown';
            }

            $intervalMinutes = (int)(QueryUtils::fetchSingleValue(
                "SELECT `execute_interval` FROM `background_services` WHERE `name` = 'clinical_copilot_worker'",
                'execute_interval',
            ) ?? 5);

            $lastTick = new \DateTimeImmutable($lastTickAt);
            $minutesSince = ((new \DateTimeImmutable())->getTimestamp() - $lastTick->getTimestamp()) / 60.0;

            return $minutesSince > ($intervalMinutes * 2) ? 'stale' : 'ok';
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
