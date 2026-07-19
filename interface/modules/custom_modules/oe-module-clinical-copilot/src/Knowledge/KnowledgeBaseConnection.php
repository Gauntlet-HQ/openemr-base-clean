<?php

/**
 * Lazy, degrade-cleanly PDO connection to the external medical-knowledge Postgres.
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
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Owns exactly one concern: turning a {@see KnowledgeBaseConfig} into a live,
 * read-only PDO to the external Postgres — and nothing about OpenEMR's own
 * database. It is a DISTINCT connection object to a DISTINCT server; it has no
 * handle on the PHI MySQL and the PHI MySQL has no handle on it, so the two data
 * domains cannot be joined even by accident.
 *
 * Everything degrades:
 *   - not configured, or the pdo_pgsql driver absent  ⇒ {@see isAvailable()} false
 *   - connect/query failure                           ⇒ {@see select()} returns []
 * so a missing or unreachable knowledge store never dead-ends a page; the caller
 * (the retriever) simply surfaces "no guideline evidence" and the offline corpus
 * remains the fallback wired by the factory.
 *
 * The connection is lazy: constructing this opens no socket. The first
 * {@see select()} connects; later calls reuse the handle. Read-only by
 * construction — it exposes only SELECT and is never handed a write.
 */
final class KnowledgeBaseConnection implements KnowledgeQueryRunner
{
    private ?PDO $pdo = null;

    private bool $connectAttempted = false;

    public function __construct(
        private readonly KnowledgeBaseConfig $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->config->isConfigured() && in_array('pgsql', PDO::getAvailableDrivers(), true);
    }

    /**
     * @param array<string, scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $pdo = $this->connection();
        if ($pdo === null) {
            return [];
        }

        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);
            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            return $rows;
        } catch (PDOException $e) {
            // Degrade, don't propagate: a transient knowledge-store outage must
            // not surface as a 500 on the clinician's synthesis page.
            $this->logger?->warning('Clinical Co-Pilot: knowledge base query failed', ['exception' => $e]);

            return [];
        }
    }

    private function connection(): ?PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if ($this->connectAttempted || !$this->isAvailable()) {
            return null;
        }
        $this->connectAttempted = true;

        try {
            $this->pdo = new PDO($this->config->dsn(), $this->config->user, $this->config->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Read-only workload; no emulated prepares so bound params are
                // handled server-side.
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $e) {
            $this->logger?->warning('Clinical Co-Pilot: knowledge base connection failed', ['exception' => $e]);
            $this->pdo = null;
        }

        return $this->pdo;
    }
}
