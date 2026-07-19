<?php

/**
 * In-process performance benchmark for the Clinical Co-Pilot hot paths.
 *
 * WHAT THIS MEASURES (and what it does NOT). This harness drives the module's
 * real, deterministic, in-process code paths (retrieval, strict-schema
 * extraction, V1-V6 verification, canonicalization/digest, prompt assembly)
 * with NO database, NO network, and NO OpenEMR core bootstrap — so it runs in
 * any PHP 8.2+ sandbox and produces honest CPU/memory/latency/throughput
 * numbers for the module's own compute. It deliberately does NOT measure the
 * full HTTP round trip (Apache + PHP-FPM + MySQL + session/ACL) or the real
 * LLM call — those require the dev stack and live provider, and are captured
 * separately by ops/load/k6/*.js + ops/load/baseline/capture-baseline.sh
 * against a reachable, seeded deployment (see ops/load/RESULTS.md). The two
 * are complementary: this isolates the CPU cost of the module's logic; the k6
 * runbook measures it end-to-end under a real web stack.
 *
 * Concurrency is real OS-process concurrency via proc_open: `--concurrency N`
 * launches N `php bench.php ... --worker` subprocesses that hammer the workload
 * in parallel for the duration, exactly the way N PHP-FPM workers would contend
 * for the same cores. proc_open (not the pcntl extension, which is absent from
 * many PHP builds including the OpenEMR/Railway container) means this runs
 * anywhere a CLI php can spawn a subprocess. Latency percentiles are computed
 * with the module's OWN RateMath
 * (the same function the observability dashboard uses), so the harness and
 * the production metric agree by construction.
 *
 * Usage:
 *   php bench.php <workload> [--concurrency=N] [--duration=SEC] [--iters=N]
 *                            [--warmup=N] [--json] [--out=FILE]
 *   php bench.php --list
 *   php bench.php --all --concurrency=1,10,50 --duration=10   # capture matrix
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Observability\Metrics\RateMath;

$moduleRoot = require __DIR__ . '/_autoload.php';
require __DIR__ . '/workloads.php';

// ---- arg parsing --------------------------------------------------------
$args = array_slice($argv, 1);
$opts = [
    'concurrency' => '1',
    'duration' => '0',
    'iters' => '0',
    'warmup' => '200',
    'json' => false,
    'all' => false,
    'list' => false,
    'out' => '',
];
$positional = [];
foreach ($args as $a) {
    if ($a === '--json') {
        $opts['json'] = true;
    } elseif ($a === '--all') {
        $opts['all'] = true;
    } elseif ($a === '--list') {
        $opts['list'] = true;
    } elseif (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, '');
        $opts[$k] = $v;
    } else {
        $positional[] = $a;
    }
}

$registry = bench_workloads($moduleRoot);

if ($opts['list']) {
    echo "Available workloads:\n";
    foreach (array_keys($registry) as $name) {
        echo "  - {$name}\n";
    }
    exit(0);
}

// Worker mode: this process was launched by the orchestrator (below) via
// proc_open. It runs exactly ONE worker for one workload and writes its samples
// to --out, then exits. No forking — real OS-process concurrency comes from the
// orchestrator spawning many of these, so no pcntl extension is needed (it is
// absent from many PHP builds, e.g. the OpenEMR container).
if (isset($opts['worker'])) {
    $wl = $positional[0] ?? '';
    if (!isset($registry[$wl])) {
        fwrite(STDERR, "bench worker: unknown workload '{$wl}'\n");
        exit(2);
    }
    $wDuration = (float)$opts['duration'];
    $wIters = (int)$opts['iters'];
    $wWarmup = (int)$opts['warmup'];
    if ($wDuration <= 0.0 && $wIters <= 0) {
        $wDuration = 10.0;
    }
    run_worker($registry[$wl], $wDuration, $wIters, $wWarmup, (string)$opts['out']);
    exit(0);
}

// Concurrency is real OS processes launched via proc_open — far more widely
// available than the pcntl extension (which the OpenEMR/Railway PHP lacks).
if (!function_exists('proc_open')) {
    fwrite(STDERR, "bench: proc_open is disabled in this PHP; cannot run concurrent workers.\n");
    exit(2);
}

$concurrencyLevels = array_map('intval', array_filter(explode(',', (string)$opts['concurrency']), 'strlen'));
if ($concurrencyLevels === []) {
    $concurrencyLevels = [1];
}
$duration = (float)$opts['duration'];
$itersPerWorker = (int)$opts['iters'];
$warmup = (int)$opts['warmup'];

if ($duration <= 0.0 && $itersPerWorker <= 0) {
    // sensible default: a 10s time-boxed run
    $duration = 10.0;
}

$workloadNames = $opts['all'] ? array_keys($registry) : $positional;
if ($workloadNames === []) {
    fwrite(STDERR, "bench: no workload named. Use --list, or pass a workload name, or --all.\n");
    exit(2);
}
foreach ($workloadNames as $wl) {
    if (!isset($registry[$wl])) {
        fwrite(STDERR, "bench: unknown workload '{$wl}' (use --list)\n");
        exit(2);
    }
}

$tmpDir = sys_get_temp_dir() . '/ccp-bench-' . getmypid();
@mkdir($tmpDir, 0700, true);

$allResults = [];
foreach ($workloadNames as $workloadName) {
    foreach ($concurrencyLevels as $concurrency) {
        $allResults[] = run_matrix_cell(
            $registry[$workloadName],
            $workloadName,
            $concurrency,
            $duration,
            $itersPerWorker,
            $warmup,
            $tmpDir,
        );
    }
}

@array_map('unlink', glob($tmpDir . '/*') ?: []);
@rmdir($tmpDir);

// ---- output -------------------------------------------------------------
if ($opts['json']) {
    $line = json_encode($allResults, JSON_THROW_ON_ERROR);
    if ($opts['out'] !== '') {
        file_put_contents((string)$opts['out'], $line . "\n", FILE_APPEND);
    }
    echo $line . "\n";
} else {
    print_human($allResults);
    if ($opts['out'] !== '') {
        file_put_contents((string)$opts['out'], json_encode($allResults, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
        echo "\n(appended machine-readable results to {$opts['out']})\n";
    }
}

exit(0);

// =========================================================================

/**
 * Run one (workload, concurrency) cell: fork the workers, collect samples,
 * aggregate. Returns a fully-populated result record.
 *
 * @param callable(): (callable(): void) $setup
 * @return array<string, mixed>
 */
function run_matrix_cell(
    callable $setup,
    string $workloadName,
    int $concurrency,
    float $duration,
    int $itersPerWorker,
    int $warmup,
    string $tmpDir,
): array {
    $wallStart = hrtime(true);
    $self = __FILE__;
    $php = PHP_BINARY;

    // Launch all workers in parallel (proc_open returns immediately), each a
    // fresh `php bench.php <workload> --worker ...` writing its samples to a
    // file. True concurrency without pcntl.
    $procs = [];
    for ($w = 0; $w < $concurrency; $w++) {
        $outFile = "{$tmpDir}/w{$w}.json";
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($self) . ' ' . escapeshellarg($workloadName)
            . ' --worker'
            . ' --duration=' . escapeshellarg((string)$duration)
            . ' --iters=' . escapeshellarg((string)$itersPerWorker)
            . ' --warmup=' . escapeshellarg((string)$warmup)
            . ' --out=' . escapeshellarg($outFile);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            fwrite(STDERR, "bench: proc_open failed to launch a worker\n");
            exit(2);
        }
        $procs[] = ['proc' => $proc, 'pipes' => $pipes];
    }

    // Wait for all: drain each child's pipes (avoids a full-buffer deadlock),
    // then proc_close (which blocks until that child exits). All were launched
    // above, so they ran in parallel; total wait is the slowest worker.
    foreach ($procs as $p) {
        stream_get_contents($p['pipes'][1]);
        stream_get_contents($p['pipes'][2]);
        fclose($p['pipes'][1]);
        fclose($p['pipes'][2]);
        proc_close($p['proc']);
    }
    $wallSec = (hrtime(true) - $wallStart) / 1e9;

    // Merge every worker's samples + resource accounting.
    $latenciesMs = [];
    $totalOps = 0;
    $cpuUserSec = 0.0;
    $cpuSysSec = 0.0;
    $peakMemBytesPerWorker = [];
    $rssHwmKbPerWorker = [];
    for ($w = 0; $w < $concurrency; $w++) {
        $raw = @file_get_contents("{$tmpDir}/w{$w}.json");
        if ($raw === false) {
            continue;
        }
        $rec = json_decode($raw, true);
        if (!is_array($rec)) {
            continue;
        }
        foreach (($rec['latencies_ms'] ?? []) as $ms) {
            $latenciesMs[] = (float)$ms;
        }
        $totalOps += (int)($rec['ops'] ?? 0);
        $cpuUserSec += (float)($rec['cpu_user_sec'] ?? 0.0);
        $cpuSysSec += (float)($rec['cpu_sys_sec'] ?? 0.0);
        $peakMemBytesPerWorker[] = (int)($rec['peak_mem_bytes'] ?? 0);
        $rssHwmKbPerWorker[] = (int)($rec['rss_hwm_kb'] ?? 0);
    }

    $cpuTotalSec = $cpuUserSec + $cpuSysSec;
    // CPU utilization as a % of ONE core (can exceed 100% with concurrency>1).
    $cpuPctOfOneCore = $wallSec > 0 ? ($cpuTotalSec / $wallSec) * 100.0 : 0.0;
    $cores = (int)(trim((string)@shell_exec('nproc')) ?: '1');

    return [
        'workload' => $workloadName,
        'concurrency' => $concurrency,
        'wall_sec' => round($wallSec, 4),
        'total_ops' => $totalOps,
        'throughput_ops_sec' => $wallSec > 0 ? round($totalOps / $wallSec, 2) : 0.0,
        'latency_ms' => [
            'p50' => round(RateMath::percentile($latenciesMs, 50.0), 4),
            'p95' => round(RateMath::percentile($latenciesMs, 95.0), 4),
            'p99' => round(RateMath::percentile($latenciesMs, 99.0), 4),
            'min' => $latenciesMs === [] ? 0.0 : round(min($latenciesMs), 4),
            'max' => $latenciesMs === [] ? 0.0 : round(max($latenciesMs), 4),
            'mean' => round(RateMath::average($latenciesMs), 4),
        ],
        'cpu' => [
            'user_sec' => round($cpuUserSec, 4),
            'sys_sec' => round($cpuSysSec, 4),
            'total_sec' => round($cpuTotalSec, 4),
            'pct_of_one_core' => round($cpuPctOfOneCore, 1),
            'pct_of_all_cores' => $cores > 0 ? round($cpuPctOfOneCore / $cores, 1) : 0.0,
            'cores' => $cores,
        ],
        'memory' => [
            'peak_php_bytes_per_worker' => $peakMemBytesPerWorker === [] ? 0 : max($peakMemBytesPerWorker),
            'peak_php_mb_per_worker' => $peakMemBytesPerWorker === [] ? 0.0 : round(max($peakMemBytesPerWorker) / 1048576, 2),
            'rss_hwm_kb_per_worker' => $rssHwmKbPerWorker === [] ? 0 : max($rssHwmKbPerWorker),
            'rss_hwm_mb_per_worker' => $rssHwmKbPerWorker === [] ? 0.0 : round(max($rssHwmKbPerWorker) / 1024, 2),
            'aggregate_rss_hwm_mb' => $rssHwmKbPerWorker === [] ? 0.0 : round(array_sum($rssHwmKbPerWorker) / 1024, 2),
        ],
    ];
}

/**
 * A single worker process: warm up, then loop the workload capturing per-op
 * latency until the time box (or iteration count) is hit; write its samples
 * and its own resource accounting to a JSON file for the parent to merge.
 *
 * Latency samples are systematically downsampled to a fixed cap (default
 * 10,000/worker). A hot path can turn out tens of millions of ops in a
 * duration cell; storing every sample — then merging 50 workers' worth and
 * sorting for percentiles in the parent — exhausts the container's 512 MB.
 * We keep a bounded reservoir by uniform decimation: record every `stride`-th
 * op, and whenever the buffer fills, halve it (drop every other sample) and
 * double the stride. That preserves an even spread across the whole run, so
 * the percentiles stay representative while the op COUNT (throughput) stays
 * exact — it is incremented on every op regardless of sampling.
 *
 * @param callable(): (callable(): void) $setup
 */
function run_worker(callable $setup, float $duration, int $itersPerWorker, int $warmup, string $outFile): void
{
    $op = $setup();

    for ($i = 0; $i < $warmup; $i++) {
        $op();
    }

    $maxSamples = 10000;
    $latencies = [];
    $stride = 1;
    $sinceKept = 0;
    $ops = 0;

    $record = static function (float $latencyMs) use (&$latencies, &$stride, &$sinceKept, $maxSamples): void {
        if (++$sinceKept < $stride) {
            return;
        }
        $sinceKept = 0;
        $latencies[] = $latencyMs;
        if (count($latencies) >= $maxSamples) {
            $halved = [];
            $n = count($latencies);
            for ($k = 0; $k < $n; $k += 2) {
                $halved[] = $latencies[$k];
            }
            $latencies = $halved;
            $stride *= 2;
        }
    };

    if ($itersPerWorker > 0) {
        for ($i = 0; $i < $itersPerWorker; $i++) {
            $t = hrtime(true);
            $op();
            $record((hrtime(true) - $t) / 1e6); // ns -> ms
            $ops++;
        }
    } else {
        $deadlineNs = hrtime(true) + (int)($duration * 1e9);
        while (hrtime(true) < $deadlineNs) {
            $t = hrtime(true);
            $op();
            $record((hrtime(true) - $t) / 1e6);
            $ops++;
        }
    }

    $ru = getrusage();
    $rssHwmKb = 0;
    $statusRaw = @file_get_contents('/proc/self/status');
    if (is_string($statusRaw) && preg_match('/VmHWM:\s+(\d+)\s+kB/', $statusRaw, $m) === 1) {
        $rssHwmKb = (int)$m[1];
    }

    file_put_contents($outFile, json_encode([
        'ops' => $ops,
        'latencies_ms' => $latencies,
        'cpu_user_sec' => $ru['ru_utime.tv_sec'] + $ru['ru_utime.tv_usec'] / 1e6,
        'cpu_sys_sec' => $ru['ru_stime.tv_sec'] + $ru['ru_stime.tv_usec'] / 1e6,
        'peak_mem_bytes' => memory_get_peak_usage(true),
        'rss_hwm_kb' => $rssHwmKb,
    ], JSON_THROW_ON_ERROR));
}

/** @param list<array<string, mixed>> $results */
function print_human(array $results): void
{
    echo "Clinical Co-Pilot — in-process hot-path benchmark\n";
    echo str_repeat('=', 78) . "\n";
    echo "host: " . php_uname('m') . "  php: " . PHP_VERSION
        . "  cores: " . (trim((string)@shell_exec('nproc')) ?: '?') . "\n";
    echo "NOTE: module compute only — NO web stack, NO DB, NO LLM (see file header).\n\n";

    foreach ($results as $r) {
        $lat = $r['latency_ms'];
        $cpu = $r['cpu'];
        $mem = $r['memory'];
        printf("workload=%s  concurrency=%d  (%.1fs wall)\n", $r['workload'], $r['concurrency'], $r['wall_sec']);
        printf("  throughput : %10.1f ops/sec   (%d ops total)\n", $r['throughput_ops_sec'], $r['total_ops']);
        printf("  latency    : p50=%.3fms  p95=%.3fms  p99=%.3fms  max=%.3fms  mean=%.3fms\n",
            $lat['p50'], $lat['p95'], $lat['p99'], $lat['max'], $lat['mean']);
        printf("  cpu        : %.2f cpu-sec  (%.0f%% of one core, %.0f%% of all %d cores)\n",
            $cpu['total_sec'], $cpu['pct_of_one_core'], $cpu['pct_of_all_cores'], $cpu['cores']);
        printf("  memory     : %.2f MB PHP peak/worker   %.2f MB RSS-HWM/worker   %.2f MB aggregate RSS\n\n",
            $mem['peak_php_mb_per_worker'], $mem['rss_hwm_mb_per_worker'], $mem['aggregate_rss_hwm_mb']);
    }
}
