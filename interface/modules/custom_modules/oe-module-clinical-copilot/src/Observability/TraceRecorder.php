<?php

/**
 * mod_copilot_trace writer -- the real TraceRecorderInterface implementation (I12).
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
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;

/**
 * The seam {@see TraceRecorderInterface} defines: every read-path step
 * (extract, digest, cache_lookup, llm_reduce, verify, render), every chat
 * step (chat_turn, tool_call), every worker step (warm, alert_eval) calls
 * {@see self::record()} exactly once with a fully-formed {@see TraceSpan} --
 * there is no start/end pair to manage, no in-flight state, just one INSERT
 * per span (I12: cache hits, degraded reads, and failures included, because
 * every call site already calls this unconditionally -- see
 * `SynthesisReadPath::recordSpan()` / `ChatController::recordSpan()`).
 *
 * Deliberately swallow-nothing on the SQL itself (a broken trace writer is a
 * real defect worth surfacing), but this class is never allowed to abort the
 * caller's real work over a trace-write failure -- callers wrap `record()`
 * in the same fire-and-forget style the Null/Logging defaults already use;
 * this class's own contribution is simply "do the INSERT for real."
 */
final class TraceRecorder implements TraceRecorderInterface
{
    private const ALLOWED_KINDS = [
        'extract', 'digest', 'cache_lookup', 'llm_reduce', 'chat_turn',
        'tool_call', 'verify', 'render', 'warm', 'alert_eval',
    ];

    private const ALLOWED_STATUSES = ['ok', 'error', 'retried', 'degraded'];

    public function record(TraceSpan $span): void
    {
        // `kind`/`status` are plain VARCHAR columns (not DB-enforced ENUMs,
        // ARCHITECTURE_COMPLETE.md's own schema comment), so this class is
        // the one place that holds callers to the documented closed set --
        // an unrecognized value is a caller bug, surfaced immediately rather
        // than silently widening the column's real domain.
        if (!in_array($span->kind, self::ALLOWED_KINDS, true)) {
            throw new \DomainException("TraceRecorder: unrecognized span kind '{$span->kind}'");
        }
        if (!in_array($span->status, self::ALLOWED_STATUSES, true)) {
            throw new \DomainException("TraceRecorder: unrecognized span status '{$span->status}'");
        }

        $sql = 'INSERT INTO `mod_copilot_trace`
            (`correlation_id`, `span_id`, `parent_span_id`, `kind`, `started_at`, `duration_ms`,
             `status`, `error_class`, `error_detail`, `model`, `tokens_in`, `tokens_out`,
             `cost_usd`, `pid`, `user_id`, `payload_ref`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        QueryUtils::sqlInsert($sql, [
            $span->correlationId,
            $span->spanId,
            $span->parentSpanId,
            $span->kind,
            $span->startedAt->format('Y-m-d H:i:s.u'),
            $span->durationMs,
            $span->status,
            $span->errorClass,
            $span->errorDetail,
            $span->model,
            $span->tokensIn,
            $span->tokensOut,
            $span->costUsd,
            $span->pid,
            $span->userId,
            $span->payloadRef,
        ]);
    }
}
