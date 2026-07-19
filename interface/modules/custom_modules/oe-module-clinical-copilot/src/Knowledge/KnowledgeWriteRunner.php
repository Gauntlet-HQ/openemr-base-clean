<?php

/**
 * The write seam over the knowledge database (ingestion path only).
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
 * Deliberately separate from {@see KnowledgeQueryRunner}: the summarizer's
 * retrieval path depends only on the read seam and can never reach these write
 * methods, so "read-only to the app" stays literally true for query traffic.
 * Only the operator ingestion flow ({@see KnowledgeChunkWriter}) depends on this,
 * and a deployment can back it with a distinct insert-capable DB role. Tests bind
 * a fake to exercise the writer with no database.
 */
interface KnowledgeWriteRunner
{
    public function isAvailable(): bool;

    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    /**
     * Execute one write statement and return the affected-row count.
     *
     * @param array<string, scalar|null> $params
     */
    public function execute(string $sql, array $params = []): int;
}
