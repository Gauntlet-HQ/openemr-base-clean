<?php

/**
 * Production cost + latency report from RECORDED runs (not estimates).
 *
 * Aggregates the real per-synthesis and per-chat-turn token usage, USD cost, and
 * LLM latency the module already writes to `mod_copilot_doc` and
 * `mod_copilot_chat_turn` (populated from usageMetadata on every live call, cost
 * via LlmCostEstimate). This is the "cost analysis tied to production
 * measurements" artifact: run it against the live DB after real traffic.
 *
 * CLI-only. Bootstraps globals.php for the DB connection, exactly like the
 * module installer.
 *
 * Usage (inside the container, e.g. `railway ssh`):
 *   php ops/perf/cost-report.php [days]
 * where [days] is the trailing window to report over (default 7).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cost-report.php is CLI-only\n");
    exit(1);
}

$days = isset($argv[1]) && is_numeric($argv[1]) ? max(1, (int) $argv[1]) : 7;

$_GET['site'] = 'default';
$ignoreAuth = true;
require_once __DIR__ . '/../../../../../globals.php';

use OpenEMR\Common\Database\QueryUtils;

/**
 * @param non-empty-string $table
 * @return array<string, float|int|null>
 */
function aggregate(string $table, string $tsColumn, int $days, bool $hasLatency): array
{
    // Only mod_copilot_doc carries llm_latency_ms; chat turns do not, so gate
    // those columns rather than let the whole section error out.
    $latencySelect = $hasLatency
        ? "COALESCE(AVG(`llm_latency_ms`), 0) AS latency_avg_ms,
           COALESCE(MAX(`llm_latency_ms`), 0) AS latency_max_ms,"
        : "0 AS latency_avg_ms, 0 AS latency_max_ms,";

    $sql = "SELECT
                COUNT(*)                                   AS n,
                COALESCE(SUM(`cost_usd`), 0)               AS cost_usd,
                COALESCE(SUM(`tokens_in`), 0)              AS tokens_in,
                COALESCE(SUM(`tokens_out`), 0)             AS tokens_out,
                {$latencySelect}
                SUM(`cost_usd` IS NOT NULL)                AS metered_rows
            FROM `{$table}`
            WHERE `{$tsColumn}` >= (NOW() - INTERVAL ? DAY)";

    $row = QueryUtils::querySingleRow($sql, [$days]);

    return is_array($row) ? $row : [];
}

/** Median LLM latency, computed in PHP so it works on any MySQL version. */
function medianLatencyMs(string $table, string $tsColumn, int $days): ?float
{
    $rows = QueryUtils::fetchTableColumn(
        "SELECT `llm_latency_ms` FROM `{$table}`
         WHERE `{$tsColumn}` >= (NOW() - INTERVAL ? DAY) AND `llm_latency_ms` IS NOT NULL
         ORDER BY `llm_latency_ms` ASC",
        'llm_latency_ms',
        [$days],
    );
    $values = array_values(array_filter(array_map('intval', $rows), static fn (int $v): bool => $v > 0));
    $count = count($values);
    if ($count === 0) {
        return null;
    }

    return (float) $values[intdiv($count, 2)];
}

function money(mixed $v): string
{
    return '$' . number_format((float) $v, 4);
}

function num(mixed $v): string
{
    return number_format((float) $v);
}

echo "== Clinical Co-Pilot -- production cost & latency report ==\n";
echo "window: last {$days} day(s)  |  generated: " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

$sections = [
    'Pre-visit synthesis (narratives)' => ['table' => 'mod_copilot_doc', 'ts' => 'computed_at', 'latency' => true],
    'Chat turns'                       => ['table' => 'mod_copilot_chat_turn', 'ts' => 'created_at', 'latency' => false],
];

$grandCost = 0.0;
$grandIn = 0;
$grandOut = 0;

foreach ($sections as $label => $meta) {
    try {
        $agg = aggregate($meta['table'], $meta['ts'], $days, $meta['latency']);
    } catch (\Throwable $e) {
        echo "-- {$label}: unavailable ({$meta['table']}: " . $e->getMessage() . ")\n\n";
        continue;
    }

    $n = (int) ($agg['n'] ?? 0);
    $cost = (float) ($agg['cost_usd'] ?? 0);
    $in = (int) ($agg['tokens_in'] ?? 0);
    $out = (int) ($agg['tokens_out'] ?? 0);
    $metered = (int) ($agg['metered_rows'] ?? 0);
    $median = $meta['latency'] ? medianLatencyMs($meta['table'], $meta['ts'], $days) : null;

    $grandCost += $cost;
    $grandIn += $in;
    $grandOut += $out;

    echo "-- {$label} ({$meta['table']}) --\n";
    echo "  runs:              " . num($n) . "  (metered for cost: " . num($metered) . ")\n";
    echo "  total cost:        " . money($cost) . "\n";
    echo "  avg cost / run:    " . ($n > 0 ? money($cost / $n) : 'n/a') . "\n";
    echo "  tokens in / out:   " . num($in) . ' / ' . num($out) . "\n";
    echo "  avg tokens / run:  " . ($n > 0 ? num(($in + $out) / $n) : 'n/a') . "\n";
    echo "  latency avg / p50 / max (ms): "
        . num($agg['latency_avg_ms'] ?? 0) . ' / '
        . ($median !== null ? num($median) : 'n/a') . ' / '
        . num($agg['latency_max_ms'] ?? 0) . "\n\n";
}

echo "== Totals (last {$days} day(s)) ==\n";
echo "  total measured cost: " . money($grandCost) . "\n";
echo "  total tokens:        " . num($grandIn + $grandOut) . " (in " . num($grandIn) . " / out " . num($grandOut) . ")\n";
$perDay = $grandCost / $days;
echo "  avg cost / day:      " . money($perDay) . "\n";
echo "  projected 30-day:    " . money($perDay * 30) . "\n";
echo "\nNote: cost is the recorded cost_usd per call (LlmCostEstimate over live\n";
echo "usageMetadata token counts), i.e. production-measured, not a forecast.\n";
