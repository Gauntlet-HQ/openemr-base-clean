<?php

/**
 * Readiness snapshot for the external medical-knowledge Postgres.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

/**
 * Answers "is the separate knowledge store configured, reachable, and populated?"
 * for the readiness endpoint and the observability dashboard — the same shape as
 * the LLM/worker readiness rows. Distinguishes the three states an operator
 * actually cares about:
 *
 *   - not_configured : no env set ⇒ the module is running on the offline corpus
 *                      (a normal, healthy state, not an error).
 *   - unreachable    : configured but the connection/probe query failed.
 *   - ok             : reachable; `chunk_count` reports how much knowledge is loaded.
 */
final class KnowledgeBaseStatus
{
    public function __construct(
        private readonly KnowledgeBaseConfig $config,
        private readonly KnowledgeQueryRunner $runner,
    ) {
    }

    public static function createDefault(): self
    {
        $config = KnowledgeBaseConfig::fromEnv();

        return new self($config, new KnowledgeBaseConnection($config));
    }

    /**
     * @return array{state: 'not_configured'|'driver_missing'|'unreachable'|'ok', configured: bool, chunk_count: int|null}
     */
    public function snapshot(): array
    {
        if (!$this->config->isConfigured()) {
            return ['state' => 'not_configured', 'configured' => false, 'chunk_count' => null];
        }

        // Configured, but the runner still reports unavailable. The connection's
        // isAvailable() is (isConfigured AND the pdo_pgsql driver is loaded in
        // THIS SAPI); config is already true here, so the missing factor is the
        // driver — classically pdo_pgsql is present for the CLI (so check.php and
        // deploy-time seeding pass) but not for the Apache/web process, which is
        // where a chat/RAG request actually runs. Report that distinctly instead
        // of the misleading "no database configured — set DATABASE_URL".
        if (!$this->runner->isAvailable()) {
            return ['state' => 'driver_missing', 'configured' => true, 'chunk_count' => null];
        }

        $table = $this->config->table;
        if (!KnowledgeTableName::isValid($table)) {
            return ['state' => 'unreachable', 'configured' => true, 'chunk_count' => null];
        }

        $rows = $this->runner->select("SELECT count(*) AS n FROM {$table}");
        if ($rows === []) {
            return ['state' => 'unreachable', 'configured' => true, 'chunk_count' => null];
        }

        $count = is_numeric($rows[0]['n'] ?? null) ? (int)$rows[0]['n'] : 0;

        return ['state' => 'ok', 'configured' => true, 'chunk_count' => $count];
    }
}
