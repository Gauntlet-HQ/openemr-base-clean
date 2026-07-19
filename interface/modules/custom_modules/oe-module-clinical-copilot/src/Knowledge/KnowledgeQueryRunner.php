<?php

/**
 * The read-only seam over the external knowledge database.
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
 * The retriever and the readiness probe depend on THIS, never a concrete PDO —
 * so tests bind a fake runner returning canned rows with no database, exactly as
 * the read path binds a stub LLM client. The surface is deliberately read-only:
 * a single parameterized {@see select()} and an {@see isAvailable()} guard. There
 * is intentionally no write method — the knowledge store is populated by the
 * offline seed script ({@see \OpenEMR\Modules\ClinicalCopilot\Knowledge}
 * ops/knowledge/seed_from_corpus.php), never by the request path.
 */
interface KnowledgeQueryRunner
{
    /**
     * Whether the store is configured AND its driver is present. Cheap and
     * side-effect free (no socket opened) so callers can branch to the offline
     * corpus without paying a connection attempt.
     */
    public function isAvailable(): bool;

    /**
     * Run one parameterized SELECT and return its rows. Returns an empty list on
     * any connection or query failure — the knowledge path degrades to "no
     * evidence found" rather than surfacing a database error to the clinician.
     *
     * @param array<string, scalar|null> $params named bind parameters
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array;
}
