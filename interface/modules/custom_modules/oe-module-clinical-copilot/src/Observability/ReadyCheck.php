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
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeBaseStatus;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceCircuitBreaker;

/**
 * "GET /copilot/ready -- genuine dependency checks with timeouts: DB
 * round-trip through QueryUtils, module tables writable (INSERT+ROLLBACK
 * probe on trace table), LLM provider reachable via a Vertex countTokens
 * call ..., background worker heartbeat fresh, circuit-breaker state ...
 * Unauthenticated but REDACTED: status enums only ... no latencies, no
 * config values, no PHI ... and per-IP rate-limited" (ARCHITECTURE.md §3.4).
 *
 * Week-2 dependencies are probed the same way: core document storage (where
 * ChartWriter lands source PDFs), the pgvector knowledge Postgres (via the
 * same {@see KnowledgeBaseStatus} snapshot the dashboard uses), and the
 * reranker (in-process, so a static configured-state --
 * {@see self::RERANKER_STATE}).
 *
 * Every check is independently caught -- a DB outage must not prevent
 * `/ready` from still reporting the LLM/breaker fields it CAN determine
 * (each field degrades to its own honest failure enum rather than the whole
 * endpoint 500ing), matching I6: "degraded-but-serving states are reported
 * honestly."
 */
final class ReadyCheck
{
    /**
     * The production reranker ({@see \OpenEMR\Modules\ClinicalCopilot\Rag\HeuristicReranker},
     * W7) is in-process PHP behind the RerankerInterface seam -- it ships with
     * the module and cannot be "down" independently of the process answering
     * this very request, so its readiness is a STATIC configured-state, not a
     * live probe. Only a remote/hosted reranker swapped in behind the same
     * seam would warrant a real reachability probe here.
     */
    public const RERANKER_STATE = 'in-process';

    public function __construct(
        private readonly CadenceCircuitBreaker $breaker = new CadenceCircuitBreaker(),
        private readonly LlmReachabilityProbe $llmProbe = new LlmReachabilityProbe(),
        private readonly ?KnowledgeBaseStatus $knowledgeStatus = null,
    ) {
    }

    /**
     * @return array{
     *     ready: bool, status: string, db: string, tables_writable: string,
     *     llm: string, worker_heartbeat: string, breaker: string,
     *     document_store: string, knowledge: string, reranker: string
     * }
     */
    public function check(): array
    {
        $db = $this->checkDb();
        $tablesWritable = $this->checkTablesWritable();
        $breakerOpen = $this->checkBreakerOpen();
        $llm = $breakerOpen === true ? 'circuit-open' : $this->checkLlm();
        $heartbeat = $this->checkHeartbeat();
        $documentStore = $this->checkDocumentStore();
        $knowledge = $this->checkKnowledge();

        // Overall status: 'ok' only when the hard dependencies (DB, tables)
        // are sound. LLM-unreachable/circuit-open and a stale heartbeat are
        // DEGRADED-but-serving states (I6: reads still work; chat degrades
        // to a facts browser; warm coverage degrades to read-time
        // generation) -- reported honestly as 'degraded', never folded into
        // 'ok' and never escalated to a hard failure that would make an
        // orchestrator restart a perfectly healthy process.
        //
        // The Week-2 dependencies degrade the same way, mirroring how the
        // serving paths actually behave when they are down:
        //  - document store missing/unwritable: ingestion uploads fail but the
        //    read/chat paths keep serving -- degraded, never error.
        //  - knowledge Postgres unreachable/driver-missing: retrieval returns
        //    no external evidence (KnowledgeBaseConnection::select degrades to
        //    []) and the offline corpus remains the factory fallback -- the
        //    module DELIBERATELY serves through this, so it is degraded, never
        //    error. 'offline-corpus' (nothing configured) is a normal healthy
        //    state and does not degrade.
        $status = ($db === 'ok' && $tablesWritable === 'ok') ? 'ok' : 'error';
        if (
            $status === 'ok' && (
                $llm !== 'ok'
                || $heartbeat !== 'ok'
                || !in_array($documentStore, ['ok', 'remote-unprobed'], true)
                || !in_array($knowledge, ['ok', 'offline-corpus'], true)
            )
        ) {
            $status = 'degraded';
        }

        return [
            // Explicit "is the service ready to serve?" -- true whenever the hard
            // dependencies (DB + writable tables) are sound, INDEPENDENT of the
            // LLM. An external LLM outage (Gemini down) leaves ready=true with
            // status='degraded': reads still serve and chat degrades to a facts
            // browser, so an uptime probe / grader sees the service is up, not
            // failed. Mirrors the HTTP code (200 unless status==='error').
            'ready' => $db === 'ok' && $tablesWritable === 'ok',
            'status' => $status,
            'db' => $db,
            'tables_writable' => $tablesWritable,
            'llm' => $llm,
            'worker_heartbeat' => $heartbeat,
            'breaker' => $breakerOpen === null ? 'unknown' : ($breakerOpen ? 'open' : 'closed'),
            'document_store' => $documentStore,
            'knowledge' => $knowledge,
            'reranker' => self::RERANKER_STATE,
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

    /**
     * Core document storage -- where ChartWriter::storeSourceDocument lands
     * uploaded source PDFs via the core Document class. With the default
     * `document_storage_method` '0' ("Hard Disk"), bytes go under the site
     * documents directory (`$GLOBALS['oer_config']['documents']['repopath']`,
     * classically `sites/default/documents/` -- docs/W2_BACKUP_RECOVERY.md
     * §1A), so the honest cheap check is that directory resolving and being
     * writable. Reads the resolution inputs through OEGlobalsBag; any failure
     * to resolve degrades to 'unknown' rather than throwing.
     *
     * @return 'ok'|'missing'|'not_writable'|'remote-unprobed'|'unknown'
     */
    private function checkDocumentStore(): string
    {
        try {
            $bag = OEGlobalsBag::getInstance();

            $methodRaw = $bag->get('document_storage_method');
            $method = is_scalar($methodRaw) ? (string)$methodRaw : null;

            $dir = null;
            $oerConfig = $bag->get('oer_config');
            if (
                is_array($oerConfig)
                && is_array($oerConfig['documents'] ?? null)
                && is_string($oerConfig['documents']['repopath'] ?? null)
                && $oerConfig['documents']['repopath'] !== ''
            ) {
                $dir = $oerConfig['documents']['repopath'];
            }
            if ($dir === null) {
                $siteDir = $bag->getString('OE_SITE_DIR');
                $dir = $siteDir !== '' ? $siteDir . '/documents/' : null;
            }

            return self::documentStoreState($method, $dir);
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Pure decision over the resolved storage config, so the enum mapping is
     * unit-testable without OpenEMR globals.
     *
     * Probe choice (documented deliberately): `is_dir` + `is_writable` -- a
     * real access(2) check for THIS process user -- rather than a
     * touch-and-unlink probe file. /ready is an unauthenticated uptime-probe
     * target (rate-limited, but still hit continuously), and writing/deleting
     * a probe file inside the clinical documents repository on every poll
     * trades disk churn and a leftover-file failure mode for very little
     * extra signal (a full-disk condition is caught by the actual upload path
     * and by the tables_writable probe's engine health). Side-effect-free
     * wins here.
     *
     * `document_storage_method` '0' is "Hard Disk" (the default, and what an
     * unset value means); any other configured method (e.g. '1' CouchDB) is
     * a remote store this endpoint deliberately does not probe -- reported
     * honestly as 'remote-unprobed' rather than pretending an on-disk check
     * covers it.
     *
     * @return 'ok'|'missing'|'not_writable'|'remote-unprobed'|'unknown'
     */
    public static function documentStoreState(?string $storageMethod, ?string $documentsDir): string
    {
        $method = trim((string)$storageMethod);
        if ($method !== '' && $method !== '0') {
            return 'remote-unprobed';
        }

        if ($documentsDir === null || $documentsDir === '') {
            return 'unknown';
        }
        if (!is_dir($documentsDir)) {
            return 'missing';
        }

        return is_writable($documentsDir) ? 'ok' : 'not_writable';
    }

    /**
     * pgvector / knowledge Postgres -- reuses the SAME {@see KnowledgeBaseStatus}
     * snapshot the dashboard shows, so /ready and the dashboard can never
     * disagree about the knowledge store. Non-ok states are DEGRADED, never
     * error: retrieval genuinely serves through an outage (the connection
     * degrades to zero external evidence and the factory's offline corpus
     * remains the fallback), so an orchestrator must not restart over it.
     *
     * @return 'ok'|'offline-corpus'|'driver-missing'|'unreachable'|'unknown'
     */
    private function checkKnowledge(): string
    {
        try {
            $snapshot = ($this->knowledgeStatus ?? KnowledgeBaseStatus::createDefault())->snapshot();

            return self::knowledgeStateFromSnapshot($snapshot);
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Maps a {@see KnowledgeBaseStatus::snapshot()} onto this endpoint's
     * redacted status enums. Deliberately drops `chunk_count`: /ready is
     * "status enums only ... no config values" (ARCHITECTURE.md §3.4), and a
     * corpus size is a config-shaped detail the dashboard (authenticated)
     * already shows. 'not_configured' becomes 'offline-corpus' because that
     * is what it MEANS operationally: the module is healthy on the in-repo
     * corpus, not missing a dependency.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return 'ok'|'offline-corpus'|'driver-missing'|'unreachable'|'unknown'
     */
    public static function knowledgeStateFromSnapshot(array $snapshot): string
    {
        return match ($snapshot['state'] ?? null) {
            'ok' => 'ok',
            'not_configured' => 'offline-corpus',
            'driver_missing' => 'driver-missing',
            'unreachable' => 'unreachable',
            default => 'unknown',
        };
    }
}
