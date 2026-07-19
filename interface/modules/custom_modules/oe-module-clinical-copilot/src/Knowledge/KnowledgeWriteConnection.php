<?php

/**
 * Write-capable PDO connection to the knowledge Postgres (ingestion path only).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

use PDO;

/**
 * The write twin of {@see KnowledgeBaseConnection}. It connects with the
 * configured WRITE role ({@see KnowledgeBaseConfig::effectiveWriteUser()}) — which
 * a deployment can make distinct from the SELECT-only role the retriever uses —
 * and exposes transactional writes. It is used ONLY by the ingestion flow, never
 * the request/retrieval path. Connection failures surface as
 * {@see PDOException} to the caller (the ingestion endpoint), which reports the
 * failure to the operator; unlike the read path, a failed knowledge write must
 * not silently succeed.
 */
final class KnowledgeWriteConnection implements KnowledgeWriteRunner
{
    private ?PDO $pdo = null;

    public function __construct(private readonly KnowledgeBaseConfig $config)
    {
    }

    public function isAvailable(): bool
    {
        return $this->config->isConfigured() && in_array('pgsql', PDO::getAvailableDrivers(), true);
    }

    public function begin(): void
    {
        $this->connection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection()->commit();
    }

    public function rollback(): void
    {
        $pdo = $this->pdo;
        if ($pdo !== null && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /**
     * @param array<string, scalar|null> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    private function connection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        // A PDOException here propagates by design: unlike the read path, a write
        // that cannot connect must never look like a success.
        $this->pdo = new PDO(
            $this->config->dsn(),
            $this->config->effectiveWriteUser(),
            $this->config->effectiveWritePassword(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ],
        );

        return $this->pdo;
    }
}
