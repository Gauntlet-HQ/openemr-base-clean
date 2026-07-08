<?php

/**
 * Computes every dashboard metric from mod_copilot_trace + mod_copilot_qa (+ ui_event) in real time.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Metrics;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Observability\UiEvent\UiEventType;

/**
 * ARCHITECTURE.md §3.3: every headline number the dashboard shows, computed
 * live from the trace table (the observability source of truth, I12) plus
 * the QA ledger (advisory metrics, docs/build-notes.md "U12 additions") and
 * the tiny UI-engagement ledger (over-reliance indicators, §2.5). No
 * metric here is precomputed/cached -- every dashboard load recomputes,
 * matching this module's own "never serve stale observability" ethos (the
 * trace/qa ledgers are append-only and cheap to aggregate at this scale).
 *
 * Two metrics are documented, ACCEPTED approximations rather than exact
 * (see the U12 report for the full reasoning) because the data the metric
 * would need does not exist on the spans as other build units wrote them,
 * and retrofitting those call sites is out of this unit's owned files:
 *  - {@see self::cacheHitRate()}: a synthesis read's `cache_lookup` span
 *    never distinguishes hit/miss in its own `status` (always `ok`) --
 *    inferred instead from "a digest span exists but no llm_reduce span
 *    followed it for the same correlation id."
 *  - `tool_call` spans carry no tool name field, so tool failure rate is
 *    reported in aggregate across all tools, not broken out per tool.
 */
final class MetricsService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(\DateTimeImmutable $since): array
    {
        $sinceSql = $since->format('Y-m-d H:i:s.u');

        return [
            'requests_by_kind' => $this->requestsByKind($sinceSql),
            'error_count' => $this->errorCount($sinceSql),
            'error_rate_pct' => $this->errorRate($sinceSql),
            'latency_percentiles_by_kind' => $this->latencyPercentilesByKind($sinceSql),
            'tool_call_counts' => $this->toolCallCounts($sinceSql),
            'tool_failure_rate_pct' => $this->toolFailureRate($sinceSql),
            'llm_retry_count' => $this->llmRetryCount($sinceSql),
            'verification_pass_fail_by_check' => $this->verificationByCheck($sinceSql),
            'cache_hit_rate_pct' => $this->cacheHitRate($sinceSql),
            'degradation_count' => $this->degradationCount($sinceSql),
            'tokens_and_cost' => $this->tokensAndCost($sinceSql),
            'worker_lag' => $this->workerLag(),
            'reviewer_concurrence_rate_pct' => $this->reviewerConcurrenceRate($sinceSql),
            'salience_score_pct' => $this->salienceScore($sinceSql),
            'narrative_density_ratio_avg' => $this->qaAverage('density_ratio', $sinceSql),
            'fact_utilization_rate_avg' => $this->qaAverage('fact_utilization_rate', $sinceSql),
            'chat_drilldown_rate_pct' => $this->chatDrilldownRate($sinceSql),
            'unaccounted_entity_rate_pct' => $this->unaccountedEntityRate($sinceSql),
            'citation_click_through_rate_pct' => $this->citationClickThroughRate($sinceSql, $since),
            'facts_panel_opens' => $this->factsPanelOpens($since),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function requestsByKind(string $sinceSql): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT `kind`, COUNT(DISTINCT `correlation_id`) AS c
             FROM `mod_copilot_trace`
             WHERE `kind` IN ('chat_turn', 'render', 'warm') AND `started_at` > ?
             GROUP BY `kind`",
            [$sinceSql],
        );

        $result = ['chat_turn' => 0, 'render' => 0, 'warm' => 0];
        foreach ($rows as $row) {
            $result[(string)$row['kind']] = (int)$row['c'];
        }

        return $result;
    }

    private function errorCount(string $sinceSql): int
    {
        return (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `status` = 'error' AND `started_at` > ?",
            'c',
            [$sinceSql],
        );
    }

    private function errorRate(string $sinceSql): float
    {
        $total = (int)QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `started_at` > ?', 'c', [$sinceSql]);

        return RateMath::percentage($this->errorCount($sinceSql), $total);
    }

    /**
     * @return array<string, array{p50: float, p95: float, count: int}>
     */
    private function latencyPercentilesByKind(string $sinceSql): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT `kind`, `duration_ms` FROM `mod_copilot_trace` WHERE `duration_ms` IS NOT NULL AND `started_at` > ?",
            [$sinceSql],
        );

        $byKind = [];
        foreach ($rows as $row) {
            $byKind[(string)$row['kind']][] = (int)$row['duration_ms'];
        }

        $result = [];
        foreach ($byKind as $kind => $durations) {
            $result[$kind] = [
                'p50' => RateMath::percentile($durations, 50.0),
                'p95' => RateMath::percentile($durations, 95.0),
                'count' => count($durations),
            ];
        }

        return $result;
    }

    /**
     * @return array{total: int, failed: int}
     */
    private function toolCallCounts(string $sinceSql): array
    {
        $total = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `kind` = 'tool_call' AND `started_at` > ?",
            'c',
            [$sinceSql],
        );
        $failed = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `kind` = 'tool_call' AND `status` = 'error' AND `started_at` > ?",
            'c',
            [$sinceSql],
        );

        return ['total' => $total, 'failed' => $failed];
    }

    private function toolFailureRate(string $sinceSql): float
    {
        $counts = $this->toolCallCounts($sinceSql);

        return RateMath::percentage($counts['failed'], $counts['total']);
    }

    private function llmRetryCount(string $sinceSql): int
    {
        return (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `kind` = 'llm_reduce' AND `status` = 'retried' AND `started_at` > ?",
            'c',
            [$sinceSql],
        );
    }

    /**
     * Per-check V1-V6 pass/fail counts (ARCHITECTURE.md §3.3). This data
     * lives in `mod_copilot_doc.doc`'s `verdicts` JSON and
     * `mod_copilot_chat_turn.verification_verdict` JSON -- NOT on the trace
     * table (a `verify` span only records an overall ok/degraded outcome,
     * never a per-check breakdown) -- so this method scans recent rows from
     * both ledgers directly, bounded to a sane count so a dashboard load
     * never scans the full history.
     *
     * @return array<string, array{passed: int, failed: int}>
     */
    private function verificationByCheck(string $sinceSql): array
    {
        $tally = [];

        $docRows = QueryUtils::fetchRecords(
            'SELECT `doc` FROM `mod_copilot_doc` WHERE `computed_at` > ? ORDER BY `id` DESC LIMIT 500',
            [$sinceSql],
        );
        foreach ($docRows as $row) {
            $decoded = json_decode((string)$row['doc'], true);
            $verdicts = is_array($decoded) ? ($decoded['verdicts'] ?? []) : [];
            self::tallyVerdicts($tally, is_array($verdicts) ? $verdicts : []);
        }

        $turnRows = QueryUtils::fetchRecords(
            "SELECT `verification_verdict` FROM `mod_copilot_chat_turn` WHERE `role` = 'assistant' AND `created_at` > ? ORDER BY `id` DESC LIMIT 500",
            [$sinceSql],
        );
        foreach ($turnRows as $row) {
            $verdictsRaw = $row['verification_verdict'] ?? null;
            $verdicts = is_string($verdictsRaw) ? json_decode($verdictsRaw, true) : null;
            self::tallyVerdicts($tally, is_array($verdicts) ? $verdicts : []);
        }

        return $tally;
    }

    /**
     * @param array<string, array{passed: int, failed: int}> $tally
     * @param list<mixed> $verdicts
     */
    private static function tallyVerdicts(array &$tally, array $verdicts): void
    {
        foreach ($verdicts as $verdict) {
            if (!is_array($verdict)) {
                continue;
            }
            $check = $verdict['check'] ?? null;
            $skipped = (bool)($verdict['skipped'] ?? false);
            if (!is_string($check) || $skipped) {
                continue;
            }
            $tally[$check] ??= ['passed' => 0, 'failed' => 0];
            if ((bool)($verdict['passed'] ?? false)) {
                $tally[$check]['passed']++;
            } else {
                $tally[$check]['failed']++;
            }
        }
    }

    /**
     * Approximation documented in this class's own docblock: a request whose
     * `digest` span has NO corresponding `llm_reduce` span under the same
     * correlation id never called the model -- a cache hit.
     */
    private function cacheHitRate(string $sinceSql): float
    {
        $digestCorrelations = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(DISTINCT `correlation_id`) AS c FROM `mod_copilot_trace` WHERE `kind` = 'digest' AND `started_at` > ?",
            'c',
            [$sinceSql],
        );
        $reduceCorrelations = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(DISTINCT `correlation_id`) AS c FROM `mod_copilot_trace` WHERE `kind` = 'llm_reduce' AND `started_at` > ?",
            'c',
            [$sinceSql],
        );

        return RateMath::percentage(max(0, $digestCorrelations - $reduceCorrelations), $digestCorrelations);
    }

    private function degradationCount(string $sinceSql): int
    {
        return (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `status` = 'degraded' AND `started_at` > ?",
            'c',
            [$sinceSql],
        );
    }

    /**
     * @return array{tokens_in: int, tokens_out: int, cost_usd_total: float, cost_usd_avg_per_request: float, cost_usd_by_day: array<string, float>}
     */
    private function tokensAndCost(string $sinceSql): array
    {
        $row = QueryUtils::querySingleRow(
            'SELECT COALESCE(SUM(`tokens_in`), 0) AS ti, COALESCE(SUM(`tokens_out`), 0) AS to_, COALESCE(SUM(`cost_usd`), 0) AS c
             FROM `mod_copilot_trace` WHERE `started_at` > ?',
            [$sinceSql],
        );

        // "cost per request" (ARCHITECTURE.md §3.3): averaged over every
        // individual span that carries a cost (each billable LLM call), via
        // the same {@see RateMath::average()} pure helper the isolated suite
        // covers directly.
        $costRows = QueryUtils::fetchRecords(
            "SELECT `cost_usd` AS v FROM `mod_copilot_trace` WHERE `cost_usd` IS NOT NULL AND `cost_usd` > 0 AND `started_at` > ?",
            [$sinceSql],
        );
        $costValues = array_map(static fn (array $r): float => (float)$r['v'], $costRows);

        $byDay = [];
        $dayRows = QueryUtils::fetchRecords(
            'SELECT DATE(`started_at`) AS d, COALESCE(SUM(`cost_usd`), 0) AS c
             FROM `mod_copilot_trace` WHERE `started_at` > ? GROUP BY DATE(`started_at`) ORDER BY d ASC',
            [$sinceSql],
        );
        foreach ($dayRows as $dayRow) {
            $byDay[(string)$dayRow['d']] = (float)$dayRow['c'];
        }

        return [
            'tokens_in' => is_array($row) ? (int)$row['ti'] : 0,
            'tokens_out' => is_array($row) ? (int)$row['to_'] : 0,
            'cost_usd_total' => is_array($row) ? (float)$row['c'] : 0.0,
            'cost_usd_avg_per_request' => round(RateMath::average($costValues), 6),
            'cost_usd_by_day' => $byDay,
        ];
    }

    /**
     * Honest placeholder: "appointments due vs. warmed" needs the warm
     * worker's own appointment-window scan (U9, not yet built --
     * `src/worker_entry.php`'s own docblock documents this). Nothing in the
     * trace table today records "appointments due" -- only warm ATTEMPTS
     * that actually ran -- so this cannot be computed accurately until U9
     * lands; reported as `null` rather than a number that would look
     * meaningful but is not, per this module's own no-silent-omission ethos.
     *
     * @return array{available: bool, note: string}
     */
    private function workerLag(): array
    {
        return ['available' => false, 'note' => 'requires U9 worker appointment-window data, not yet built'];
    }

    private function reviewerConcurrenceRate(string $sinceSql): float
    {
        $total = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_qa` WHERE `status` = 'ok' AND `concurs` IS NOT NULL AND `created_at` > ?",
            'c',
            [$sinceSql],
        );
        $concurring = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_qa` WHERE `status` = 'ok' AND `concurs` = 1 AND `created_at` > ?",
            'c',
            [$sinceSql],
        );

        return RateMath::percentage($concurring, $total);
    }

    private function salienceScore(string $sinceSql): float
    {
        $total = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_qa` WHERE `status` = 'ok' AND `salience_ok` IS NOT NULL AND `created_at` > ?",
            'c',
            [$sinceSql],
        );
        $ok = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_qa` WHERE `status` = 'ok' AND `salience_ok` = 1 AND `created_at` > ?",
            'c',
            [$sinceSql],
        );

        return RateMath::percentage($ok, $total);
    }

    private function qaAverage(string $column, string $sinceSql): float
    {
        if (!in_array($column, ['density_ratio', 'fact_utilization_rate'], true)) {
            throw new \DomainException("MetricsService: unsupported qa column '{$column}'");
        }

        $value = QueryUtils::fetchSingleValue(
            "SELECT AVG(`{$column}`) AS v FROM `mod_copilot_qa` WHERE `created_at` > ?",
            'v',
            [$sinceSql],
        );

        return $value !== null ? round((float)$value, 4) : 0.0;
    }

    private function chatDrilldownRate(string $sinceSql): float
    {
        $turnCorrelations = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(DISTINCT `correlation_id`) AS c FROM `mod_copilot_trace` WHERE `kind` = 'chat_turn' AND `started_at` > ?",
            'c',
            [$sinceSql],
        );
        $withTool = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(DISTINCT t1.`correlation_id`) AS c
             FROM `mod_copilot_trace` t1
             WHERE t1.`kind` = 'chat_turn' AND t1.`started_at` > ?
               AND EXISTS (SELECT 1 FROM `mod_copilot_trace` t2 WHERE t2.`correlation_id` = t1.`correlation_id` AND t2.`kind` = 'tool_call')",
            'c',
            [$sinceSql],
        );

        return RateMath::percentage($withTool, $turnCorrelations);
    }

    private function unaccountedEntityRate(string $sinceSql): float
    {
        $total = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `kind` = 'extract' AND `started_at` > ?",
            'c',
            [$sinceSql],
        );
        $unaccounted = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `kind` = 'extract' AND `error_class` = 'UnaccountedRows' AND `started_at` > ?",
            'c',
            [$sinceSql],
        );

        return RateMath::percentage($unaccounted, $total);
    }

    /**
     * Denominator is distinct correlation ids with a `render` or `chat_turn`
     * span in the window -- i.e. every surface that could have shown a
     * citation to click.
     */
    private function citationClickThroughRate(string $sinceSql, \DateTimeImmutable $since): float
    {
        $shown = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(DISTINCT `correlation_id`) AS c FROM `mod_copilot_trace` WHERE `kind` IN ('render', 'chat_turn') AND `started_at` > ?",
            'c',
            [$sinceSql],
        );
        $clicks = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_ui_event` WHERE `event_type` = ? AND `created_at` > ?",
            'c',
            [UiEventType::CitationClick->value, $since->format('Y-m-d H:i:s')],
        );

        return RateMath::percentage($clicks, $shown);
    }

    private function factsPanelOpens(\DateTimeImmutable $since): int
    {
        return (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_ui_event` WHERE `event_type` = ? AND `created_at` > ?",
            'c',
            [UiEventType::FactsPanelOpen->value, $since->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Filtered request list for the dashboard's tile -> list drill-down
     * (ARCHITECTURE.md §3.3's click-through chain, step 2). Groups spans by
     * correlation id, one row per request, most recent first.
     *
     * @return list<array{correlation_id: string, kind: string, started_at: string, status: string, pid: ?int, span_count: int}>
     */
    public function requestList(?string $kindFilter, ?string $statusFilter, int $limit): array
    {
        $conditions = [];
        $binds = [];
        if ($kindFilter !== null) {
            $conditions[] = 't.`kind` = ?';
            $binds[] = $kindFilter;
        }
        if ($statusFilter !== null) {
            $conditions[] = 't.`status` = ?';
            $binds[] = $statusFilter;
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $rows = QueryUtils::fetchRecords(
            "SELECT t.`correlation_id`, MIN(t.`kind`) AS root_kind, MIN(t.`started_at`) AS started_at,
                    MAX(t.`status`) AS worst_status, MAX(t.`pid`) AS pid, COUNT(*) AS span_count
             FROM `mod_copilot_trace` t
             {$where}
             GROUP BY t.`correlation_id`
             ORDER BY started_at DESC
             LIMIT " . QueryUtils::escapeLimit($limit),
            $binds,
        );

        return array_map(static fn (array $row): array => [
            'correlation_id' => (string)$row['correlation_id'],
            'kind' => (string)$row['root_kind'],
            'started_at' => (string)$row['started_at'],
            'status' => (string)$row['worst_status'],
            'pid' => $row['pid'] !== null ? (int)$row['pid'] : null,
            'span_count' => (int)$row['span_count'],
        ], $rows);
    }

    /**
     * The most recent FIRED alert_eval spans -- what the dashboard banner
     * shows (ARCHITECTURE.md §3.5: "firing ... surfaces a dashboard banner").
     * A non-fired evaluation is recorded too (status `ok`) but is not
     * banner-worthy; this method only returns the `error`-status ones.
     *
     * @return list<array{alert: string, message: string, started_at: string}>
     */
    public function recentFiredAlerts(int $limit = 20): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT `error_class`, `error_detail`, `started_at` FROM `mod_copilot_trace`
             WHERE `kind` = 'alert_eval' AND `status` = 'error'
             ORDER BY `started_at` DESC LIMIT " . QueryUtils::escapeLimit($limit),
        );

        return array_map(static fn (array $row): array => [
            'alert' => (string)($row['error_class'] ?? ''),
            'message' => (string)($row['error_detail'] ?? ''),
            'started_at' => (string)$row['started_at'],
        ], $rows);
    }

    /**
     * One request's full span waterfall (ARCHITECTURE.md §3.3's click-through
     * chain, step 3) -- every span for one correlation id, ordered so nested
     * spans (parent_span_id set) render under their parent.
     *
     * @return list<array<string, mixed>>
     */
    public function spanWaterfall(string $correlationId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM `mod_copilot_trace` WHERE `correlation_id` = ? ORDER BY `started_at` ASC, `id` ASC',
            [$correlationId],
        );

        return array_map(static fn (array $row): array => [
            'span_id' => (string)$row['span_id'],
            'parent_span_id' => $row['parent_span_id'] !== null ? (string)$row['parent_span_id'] : null,
            'kind' => (string)$row['kind'],
            'started_at' => (string)$row['started_at'],
            'duration_ms' => $row['duration_ms'] !== null ? (int)$row['duration_ms'] : null,
            'status' => (string)$row['status'],
            'error_class' => $row['error_class'] !== null ? (string)$row['error_class'] : null,
            'error_detail' => $row['error_detail'] !== null ? (string)$row['error_detail'] : null,
            'model' => $row['model'] !== null ? (string)$row['model'] : null,
            'tokens_in' => $row['tokens_in'] !== null ? (int)$row['tokens_in'] : null,
            'tokens_out' => $row['tokens_out'] !== null ? (int)$row['tokens_out'] : null,
            'cost_usd' => $row['cost_usd'] !== null ? (float)$row['cost_usd'] : null,
            'payload_ref' => $row['payload_ref'] !== null ? (string)$row['payload_ref'] : null,
        ], $rows);
    }
}
