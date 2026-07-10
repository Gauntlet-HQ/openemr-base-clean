<?php

/**
 * Read-only view over mod_copilot_trace for public/status.php's polling fallback.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Common\Database\QueryUtils;

/**
 * ARCHITECTURE.md §1.3: "the polling fallback ... reads the trace spans the
 * turn is writing (via TraceRecorder) to render progress." This class is the
 * READ side of that same table -- {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface}
 * only ever writes. A direct, read-only `SELECT` here (rather than a new
 * interface) mirrors how {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\DocHistoryReader}
 * reads `mod_copilot_doc` directly for the doc page's history view: the
 * table is the shared source of truth (I12), and reading it plainly is not
 * a write-path concern that needs a seam.
 *
 * **Honest gap:** until U12 wires a real `TraceRecorderInterface`
 * implementation, {@see ChatController::createDefault()} uses
 * {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\NullTraceRecorder} (a
 * no-op), so no rows land in `mod_copilot_trace` for this class to read yet
 * -- `public/status.php`'s staged-progress list is empty until then, while
 * the turn-completion half of polling (reading `mod_copilot_chat_turn`
 * directly) works today regardless, since that ledger write does not depend
 * on the trace seam at all.
 */
final class TracePoller
{
    /**
     * @return list<array{kind: string, status: string, started_at: string, duration_ms: int|null}>
     */
    public function forCorrelationId(string $correlationId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT `kind`, `status`, `started_at`, `duration_ms` FROM `mod_copilot_trace`
             WHERE `correlation_id` = ? ORDER BY `started_at` ASC, `id` ASC',
            [$correlationId],
        );

        return array_map(static fn (array $row): array => [
            'kind' => (string)$row['kind'],
            'status' => (string)$row['status'],
            'started_at' => (string)$row['started_at'],
            'duration_ms' => $row['duration_ms'] !== null ? (int)$row['duration_ms'] : null,
        ], $rows);
    }
}
