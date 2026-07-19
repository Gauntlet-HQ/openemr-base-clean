<?php

/**
 * One-command capture: run every hot-path workload at concurrency 1 (baseline),
 * 10, and 50, then render both a machine-readable NDJSON record and the
 * Markdown results table that ops/load/RESULTS.md embeds.
 *
 * This is the in-process complement to ops/load/baseline/capture-baseline.sh
 * (full-stack curl timings) and ops/load/k6/*.js (full-stack concurrent load):
 * it needs no reachable stack, so it runs anywhere PHP does and produces the
 * module-compute CPU/memory/latency/throughput baseline that was previously
 * blank. See ops/load/bench/README.md.
 *
 * Usage:
 *   php capture.php [--duration=SEC] [--warmup=N] [--out-dir=DIR]
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$moduleRoot = require __DIR__ . '/_autoload.php';
require __DIR__ . '/workloads.php';

use OpenEMR\Modules\ClinicalCopilot\Observability\Metrics\RateMath;

$opts = ['duration' => '8', 'warmup' => '300', 'out-dir' => __DIR__ . '/results'];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, '');
        $opts[$k] = $v;
    }
}
$duration = (float)$opts['duration'];
$warmup = (int)$opts['warmup'];
$outDir = (string)$opts['out-dir'];
@mkdir($outDir, 0755, true);

$levels = [1, 10, 50];
$registry = bench_workloads($moduleRoot);
$benchScript = __DIR__ . '/bench.php';
$phpBin = PHP_BINARY;

$cores = trim((string)@shell_exec('nproc')) ?: '?';
$stamp = date('Y-m-d\TH:i:sP');
$gitCommit = trim((string)@shell_exec('git -C ' . escapeshellarg($moduleRoot) . ' rev-parse --short HEAD 2>/dev/null')) ?: 'unknown';

echo "Capturing in-process hot-path baseline + load matrix\n";
echo "  host={" . php_uname('m') . "} php=" . PHP_VERSION . " cores={$cores} duration={$duration}s/cell\n";
echo "  " . count($registry) . " workloads x " . count($levels) . " concurrency levels = "
    . (count($registry) * count($levels)) . " cells\n\n";

$rows = [];
foreach (array_keys($registry) as $workload) {
    foreach ($levels as $c) {
        fwrite(STDERR, "  running {$workload} @ c={$c} ... ");
        $cmd = sprintf(
            '%s %s %s --concurrency=%d --duration=%s --warmup=%d --json',
            escapeshellarg($phpBin),
            escapeshellarg($benchScript),
            escapeshellarg($workload),
            $c,
            escapeshellarg((string)$duration),
            $warmup,
        );
        $json = (string)shell_exec($cmd);
        $decoded = json_decode(trim($json), true);
        if (!is_array($decoded) || !isset($decoded[0])) {
            fwrite(STDERR, "FAILED\n");
            continue;
        }
        $rows[] = $decoded[0];
        fwrite(STDERR, sprintf("%.0f ops/s\n", $decoded[0]['throughput_ops_sec']));
    }
}

$record = [
    'captured_at' => $stamp,
    'git_commit' => $gitCommit,
    'host_arch' => php_uname('m'),
    'php_version' => PHP_VERSION,
    'cores' => is_numeric($cores) ? (int)$cores : $cores,
    'mem_total_mb' => mem_total_mb(),
    'duration_sec_per_cell' => $duration,
    'warmup_per_worker' => $warmup,
    'results' => $rows,
];

$ndjson = $outDir . '/inprocess-results.ndjson';
file_put_contents($ndjson, json_encode($record, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
$latest = $outDir . '/inprocess-latest.json';
file_put_contents($latest, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

$md = render_markdown($record);
$mdFile = $outDir . '/inprocess-latest.md';
file_put_contents($mdFile, $md);

echo "\n" . $md . "\n";
echo "Wrote:\n  {$ndjson}\n  {$latest}\n  {$mdFile}\n";
exit(0);

// =========================================================================

function mem_total_mb(): int
{
    $raw = @file_get_contents('/proc/meminfo');
    if (is_string($raw) && preg_match('/MemTotal:\s+(\d+)\s+kB/', $raw, $m) === 1) {
        return (int)round((int)$m[1] / 1024);
    }
    return 0;
}

/** @param array<string, mixed> $record */
function render_markdown(array $record): string
{
    $out = [];
    $out[] = '### In-process hot-path results (module compute only — no web stack / DB / LLM)';
    $out[] = '';
    $out[] = sprintf(
        '_Captured %s · commit `%s` · host %s · PHP %s · %s cores · %s MB RAM · %ss/cell · warmup %d/worker._',
        $record['captured_at'],
        $record['git_commit'],
        $record['host_arch'],
        $record['php_version'],
        (string)$record['cores'],
        (string)$record['mem_total_mb'],
        (string)$record['duration_sec_per_cell'],
        $record['warmup_per_worker'],
    );
    $out[] = '';
    $out[] = '| Workload | Conc | Throughput (ops/s) | p50 (ms) | p95 (ms) | p99 (ms) | CPU (% all cores) | RSS/worker (MB) | Aggregate RSS (MB) |';
    $out[] = '|---|---:|---:|---:|---:|---:|---:|---:|---:|';
    foreach ($record['results'] as $r) {
        $out[] = sprintf(
            '| `%s` | %d | %s | %s | %s | %s | %s%% | %s | %s |',
            $r['workload'],
            $r['concurrency'],
            number_format($r['throughput_ops_sec'], 1),
            fmt($r['latency_ms']['p50']),
            fmt($r['latency_ms']['p95']),
            fmt($r['latency_ms']['p99']),
            fmt($r['cpu']['pct_of_all_cores'], 0),
            fmt($r['memory']['rss_hwm_mb_per_worker']),
            fmt($r['memory']['aggregate_rss_hwm_mb']),
        );
    }
    return implode("\n", $out) . "\n";
}

function fmt(float $v, int $dp = 3): string
{
    return number_format($v, $dp);
}
