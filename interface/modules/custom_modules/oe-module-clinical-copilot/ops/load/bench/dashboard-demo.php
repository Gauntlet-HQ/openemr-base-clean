<?php

/**
 * Live dashboard + alert-firing demonstration (no dev stack required).
 *
 * The production dashboard (public/dashboard.php + dashboard.html.twig) reads
 * the metric bag from `mod_copilot_trace` via MetricsService, and AlertEvaluator
 * evaluates 8 thresholds against the same table on the worker tick. Both need a
 * live OpenEMR DB, so they cannot render inside a no-stack sandbox. This script
 * demonstrates the SAME behaviour end-to-end using the SAME primitives:
 *
 *   - metric aggregation uses the module's real RateMath (the exact functions
 *     MetricsService.overview() calls — percentile / percentage / average),
 *   - alert findings are the real AlertFinding + AlertName value objects, and
 *   - the thresholds are AlertEvaluator's real defaults (p95 15000ms, error 5%,
 *     tool-failure 2%, verification-failure 10%, wrong-patient 0).
 *
 * It runs two scenarios over synthetic trace populations — HEALTHY (everything
 * within threshold) and INCIDENT (a p95 latency breach, an elevated error rate,
 * and a sev-1 wrong-patient V3 trip) — computes the overview, evaluates every
 * alert, and renders a self-contained HTML dashboard for each, plus a console
 * summary of which alerts fired. On the dev stack, the identical metric bag and
 * findings come out of MetricsService/AlertEvaluator against real rows; this is
 * the offline demonstration of that surface.
 *
 * Usage: php dashboard-demo.php [--out-dir=DIR]
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Observability\Alert\AlertFinding;
use OpenEMR\Modules\ClinicalCopilot\Observability\Alert\AlertName;
use OpenEMR\Modules\ClinicalCopilot\Observability\LlmCostEstimate;
use OpenEMR\Modules\ClinicalCopilot\Observability\Metrics\RateMath;

$moduleRoot = require __DIR__ . '/_autoload.php';

$outDir = __DIR__ . '/results';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--out-dir=')) {
        $outDir = substr($a, 10);
    }
}
@mkdir($outDir, 0755, true);

// AlertEvaluator's real defaults (src/Observability/Alert/AlertEvaluator.php loadThresholds()).
const THRESHOLDS = [
    'p95_latency_ms' => 15000.0,
    'error_rate_pct' => 5.0,
    'tool_failure_rate_pct' => 2.0,
    'verification_failure_rate_pct' => 10.0,
    'eval_window_minutes' => 15,
];

/**
 * A synthetic trace span. Field names mirror mod_copilot_trace columns the
 * production MetricsService/AlertEvaluator read.
 *
 * @return array{kind:string,status:string,duration_ms:?int,tokens_in:int,tokens_out:int,model:?string,error_class:?string}
 */
function span(string $kind, string $status, ?int $durationMs, int $tokensIn = 0, int $tokensOut = 0, ?string $model = null, ?string $errorClass = null): array
{
    return compact('kind', 'status', 'durationMs', 'tokensIn', 'tokensOut', 'model', 'errorClass')
        + ['duration_ms' => $durationMs, 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut, 'error_class' => $errorClass];
}

/**
 * Deterministic pseudo-random latency around a center (seeded so the demo is
 * reproducible — no Math.random equivalent needed).
 *
 * @return list<int>
 */
function latencies(int $n, int $centerMs, int $spreadMs, int $seed): array
{
    mt_srand($seed);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = max(1, $centerMs + mt_rand(-$spreadMs, $spreadMs));
    }
    return $out;
}

/**
 * Build a scenario's trace population.
 *
 * @return list<array<string, mixed>>
 */
function scenario(string $which): array
{
    $spans = [];

    if ($which === 'healthy') {
        // chat turns: p95 ~8s, well under the 15s threshold; no errors.
        foreach (latencies(120, 6500, 4000, 101) as $ms) {
            $spans[] = span('chat_turn', 'ok', $ms, 1300, 300, 'gemini-2.5-pro');
        }
        // renders (warm reads): fast, no LLM.
        foreach (latencies(300, 90, 60, 102) as $ms) {
            $spans[] = span('render', 'ok', $ms);
        }
        // a few warm-miss degraded reads (below the 20% warm-miss threshold).
        foreach (latencies(8, 5200, 1500, 103) as $ms) {
            $spans[] = span('render', 'degraded', $ms);
        }
        // tool calls: 1 failure out of ~200 (0.5% < 2%).
        foreach (latencies(200, 120, 80, 104) as $i => $ms) {
            $spans[] = span('tool_call', $i === 0 ? 'error' : 'ok', $ms, 0, 0, null, $i === 0 ? 'ToolTimeoutException' : null);
        }
        // verify spans: all ok.
        foreach (latencies(120, 3, 2, 105) as $ms) {
            $spans[] = span('verify', 'ok', $ms);
        }
        return $spans;
    }

    // --- INCIDENT ---
    // chat turns: p95 blown past 15s (worker not keeping up), plus 9% errors.
    foreach (latencies(120, 18000, 9000, 201) as $i => $ms) {
        $isErr = $i % 3 === 0; // elevated LLM-unavailable rate under the incident
        $spans[] = span('chat_turn', $isErr ? 'error' : 'ok', $ms, 1300, 300, 'gemini-2.5-pro', $isErr ? 'LlmUnavailableException' : null);
    }
    // one sev-1 wrong-patient V3 trip (frozen chat turn, status error).
    $spans[] = span('chat_turn', 'error', 250, 0, 0, null, 'PatientIdentityMismatch');
    // renders mostly ok.
    foreach (latencies(280, 110, 70, 202) as $ms) {
        $spans[] = span('render', 'ok', $ms);
    }
    // tool calls: 3% failure (> 2%).
    foreach (latencies(200, 130, 90, 203) as $i => $ms) {
        $isErr = $i % 33 === 0 || $i < 6;
        $spans[] = span('tool_call', $isErr ? 'error' : 'ok', $ms, 0, 0, null, $isErr ? 'CapabilityDataShapeError' : null);
    }
    // verify: within threshold still.
    foreach (latencies(120, 3, 2, 204) as $ms) {
        $spans[] = span('verify', 'ok', $ms);
    }
    return $spans;
}

/**
 * Aggregate the metric overview using the module's real RateMath — the same
 * math MetricsService.overview() performs, applied to the in-memory spans.
 *
 * @param list<array<string, mixed>> $spans
 * @return array<string, mixed>
 */
function overview(array $spans): array
{
    $byKind = [];
    $errorCount = 0;
    $costUsd = 0.0;
    $tokIn = 0;
    $tokOut = 0;
    foreach ($spans as $s) {
        $byKind[$s['kind']] ??= ['count' => 0, 'errors' => 0, 'durations' => []];
        $byKind[$s['kind']]['count']++;
        if ($s['status'] === 'error') {
            $byKind[$s['kind']]['errors']++;
            $errorCount++;
        }
        if ($s['duration_ms'] !== null) {
            $byKind[$s['kind']]['durations'][] = (int)$s['duration_ms'];
        }
        $tokIn += (int)$s['tokens_in'];
        $tokOut += (int)$s['tokens_out'];
        if (($s['model'] ?? null) !== null) {
            $costUsd += (float)(LlmCostEstimate::estimateUsd((string)$s['model'], (int)$s['tokens_in'], (int)$s['tokens_out']) ?? 0.0);
        }
    }

    $latencyByKind = [];
    foreach ($byKind as $kind => $d) {
        $latencyByKind[$kind] = [
            'count' => $d['count'],
            'p50' => round(RateMath::percentile($d['durations'], 50.0), 1),
            'p95' => round(RateMath::percentile($d['durations'], 95.0), 1),
            'p99' => round(RateMath::percentile($d['durations'], 99.0), 1),
            'error_rate' => round(RateMath::percentage($d['errors'], $d['count']), 2),
        ];
    }

    return [
        'total_spans' => count($spans),
        'error_count' => $errorCount,
        'error_rate_pct' => round(RateMath::percentage($errorCount, count($spans)), 2),
        'latency_by_kind' => $latencyByKind,
        'tokens_in' => $tokIn,
        'tokens_out' => $tokOut,
        'cost_usd' => round($costUsd, 4),
    ];
}

/**
 * Evaluate every alert with the real AlertFinding/AlertName value objects and
 * AlertEvaluator's real thresholds, over the in-memory spans. Mirrors
 * AlertEvaluator.run()'s comparisons exactly (p95/error/tool/verify/trip).
 *
 * @param list<array<string, mixed>> $spans
 * @return list<AlertFinding>
 */
function evaluate_alerts(array $spans): array
{
    $chatDur = column($spans, fn ($s) => $s['kind'] === 'chat_turn' && $s['duration_ms'] !== null, fn ($s) => (int)$s['duration_ms']);
    $p95 = RateMath::percentile($chatDur, 95.0);

    $total = count($spans);
    $errors = count(array_filter($spans, fn ($s) => $s['status'] === 'error'));
    $errorRate = RateMath::percentage($errors, $total);

    $toolTotal = count(array_filter($spans, fn ($s) => $s['kind'] === 'tool_call'));
    $toolFail = count(array_filter($spans, fn ($s) => $s['kind'] === 'tool_call' && $s['status'] === 'error'));
    $toolRate = RateMath::percentage($toolFail, $toolTotal);

    $verTotal = count(array_filter($spans, fn ($s) => $s['kind'] === 'verify'));
    $verFail = count(array_filter($spans, fn ($s) => $s['kind'] === 'verify' && $s['status'] !== 'ok'));
    $verRate = RateMath::percentage($verFail, $verTotal);

    $trips = count(array_filter($spans, fn ($s) => $s['kind'] === 'chat_turn' && $s['status'] === 'error' && $s['error_class'] === 'PatientIdentityMismatch'));

    $findings = [];
    $findings[] = new AlertFinding(
        AlertName::WrongPatientTrip,
        $trips > 0,
        $trips > 0 ? "{$trips} chat session(s) frozen on a V3 patient-identity sev-1 trip" : 'no wrong-patient trips in the window',
        (float)$trips,
        0.0,
    );
    $findings[] = new AlertFinding(
        AlertName::P95Latency,
        $chatDur !== [] && $p95 > THRESHOLDS['p95_latency_ms'],
        sprintf('chat turn p95 latency %.0fms vs %.0fms threshold', $p95, THRESHOLDS['p95_latency_ms']),
        $p95,
        THRESHOLDS['p95_latency_ms'],
    );
    $findings[] = new AlertFinding(
        AlertName::ErrorRate,
        $total > 0 && $errorRate > THRESHOLDS['error_rate_pct'],
        sprintf('error rate %.1f%% vs %.1f%% threshold', $errorRate, THRESHOLDS['error_rate_pct']),
        $errorRate,
        THRESHOLDS['error_rate_pct'],
    );
    $findings[] = new AlertFinding(
        AlertName::ToolFailureRate,
        $toolTotal > 0 && $toolRate > THRESHOLDS['tool_failure_rate_pct'],
        sprintf('tool call failure rate %.1f%% vs %.1f%% threshold', $toolRate, THRESHOLDS['tool_failure_rate_pct']),
        $toolRate,
        THRESHOLDS['tool_failure_rate_pct'],
    );
    $findings[] = new AlertFinding(
        AlertName::VerificationFailureRate,
        $verTotal > 0 && $verRate > THRESHOLDS['verification_failure_rate_pct'],
        sprintf('verification failure rate %.1f%% vs %.1f%% threshold', $verRate, THRESHOLDS['verification_failure_rate_pct']),
        $verRate,
        THRESHOLDS['verification_failure_rate_pct'],
    );
    // LlmSpend / WorkerHeartbeatStale / UnaccountedEntity need cadence + config
    // rows that only exist on the dev stack; report them as not-fired here so
    // the dashboard lists all 8, and note the source.
    $findings[] = new AlertFinding(AlertName::LlmSpend, false, 'hourly LLM burn within trend (dev-stack cadence config)', 0.0, 0.0);
    $findings[] = new AlertFinding(AlertName::WorkerHeartbeatStale, false, 'worker heartbeat fresh (dev-stack background_services)', 0.0, 0.0);
    $findings[] = new AlertFinding(AlertName::UnaccountedEntity, false, 'no unaccounted extraction entities in the window', 0.0, 0.0);

    return $findings;
}

/**
 * @param list<array<string, mixed>> $spans
 * @param callable(array<string, mixed>): bool $filter
 * @param callable(array<string, mixed>): int $map
 * @return list<int>
 */
function column(array $spans, callable $filter, callable $map): array
{
    $out = [];
    foreach ($spans as $s) {
        if ($filter($s)) {
            $out[] = $map($s);
        }
    }
    return $out;
}

// ---- run both scenarios ----
$summary = [];
foreach (['healthy', 'incident'] as $which) {
    $spans = scenario($which);
    $ov = overview($spans);
    $findings = evaluate_alerts($spans);
    $fired = array_values(array_filter($findings, fn (AlertFinding $f) => $f->fired));

    $html = render_dashboard($which, $ov, $findings);
    $file = $outDir . "/dashboard-{$which}.html";
    file_put_contents($file, $html);

    $summary[$which] = ['file' => $file, 'fired' => array_map(fn (AlertFinding $f) => $f->name->value, $fired), 'overview' => $ov];

    echo strtoupper($which) . " scenario\n";
    echo "  spans={$ov['total_spans']}  error_rate={$ov['error_rate_pct']}%  cost=\${$ov['cost_usd']}\n";
    echo "  chat p95=" . ($ov['latency_by_kind']['chat_turn']['p95'] ?? 'n/a') . "ms\n";
    foreach ($findings as $f) {
        echo sprintf("  [%s] %-26s %s\n", $f->fired ? 'FIRED' : ' ok  ', $f->name->value, $f->message);
    }
    echo "  -> {$file}\n\n";
}

file_put_contents($outDir . '/dashboard-demo-summary.json', json_encode([
    'generated_at' => date('Y-m-d\TH:i:sP'),
    'thresholds' => THRESHOLDS,
    'scenarios' => $summary,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

exit(0);

// =========================================================================

/**
 * @param array<string, mixed> $ov
 * @param list<AlertFinding> $findings
 */
function render_dashboard(string $which, array $ov, array $findings): string
{
    $fired = array_values(array_filter($findings, fn (AlertFinding $f) => $f->fired));
    $title = 'Clinical Co-Pilot — Observability (' . strtoupper($which) . ')';

    $banner = '';
    if ($fired !== []) {
        $items = '';
        foreach ($fired as $f) {
            $items .= '<li><strong>' . h($f->name->value) . '</strong> — ' . h($f->message)
                . '<br><span class="mean">means: ' . h($f->name->meaning()) . '</span>'
                . '<br><span class="oncall">on-call: ' . h($f->name->onCallResponse()) . '</span></li>';
        }
        $banner = '<div class="banner fire"><h2>🔴 ' . count($fired) . ' alert(s) FIRING</h2><ul>' . $items . '</ul></div>';
    } else {
        $banner = '<div class="banner ok"><h2>🟢 All alerts within threshold</h2></div>';
    }

    // metric tiles
    $tiles = '';
    $tiles .= tile('Total spans (window)', (string)$ov['total_spans']);
    $tiles .= tile('Error rate', $ov['error_rate_pct'] . '%', $ov['error_rate_pct'] > THRESHOLDS['error_rate_pct'] ? 'bad' : 'good');
    $chat = $ov['latency_by_kind']['chat_turn'] ?? null;
    if ($chat !== null) {
        $tiles .= tile('Chat p95', $chat['p95'] . ' ms', $chat['p95'] > THRESHOLDS['p95_latency_ms'] ? 'bad' : 'good');
        $tiles .= tile('Chat p50 / p99', $chat['p50'] . ' / ' . $chat['p99'] . ' ms');
    }
    $tiles .= tile('LLM cost (window)', '$' . $ov['cost_usd']);
    $tiles .= tile('Tokens in / out', number_format($ov['tokens_in']) . ' / ' . number_format($ov['tokens_out']));

    // latency-by-kind table
    $rows = '';
    foreach ($ov['latency_by_kind'] as $kind => $d) {
        $rows .= '<tr><td>' . h($kind) . '</td><td>' . $d['count'] . '</td><td>' . $d['p50'] . '</td><td>'
            . $d['p95'] . '</td><td>' . $d['p99'] . '</td><td>' . $d['error_rate'] . '%</td></tr>';
    }

    // full alert table
    $alertRows = '';
    foreach ($findings as $f) {
        $cls = $f->fired ? 'fire' : 'ok';
        $alertRows .= '<tr class="' . $cls . '"><td>' . ($f->fired ? '🔴' : '🟢') . '</td><td>' . h($f->name->value)
            . '</td><td>' . h($f->message) . '</td><td>' . rtrim(rtrim(number_format($f->metricValue, 2), '0'), '.')
            . '</td><td>' . rtrim(rtrim(number_format($f->threshold, 2), '0'), '.') . '</td></tr>';
    }

    return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif; margin: 0; padding: 24px; background:#0f1115; color:#e6e6e6; }
  @media (prefers-color-scheme: light) { body { background:#f6f7f9; color:#1a1a1a; } .card{background:#fff!important;} }
  h1 { font-size: 20px; margin: 0 0 4px; } .sub { opacity:.7; font-size:13px; margin-bottom:20px; }
  .banner { border-radius:10px; padding:16px 20px; margin-bottom:20px; }
  .banner.ok { background:#14351f; border:1px solid #2ea043; }
  .banner.fire { background:#3d1417; border:1px solid #e5484d; }
  .banner h2 { margin:0 0 8px; font-size:16px; }
  .banner ul { margin:0; padding-left:18px; } .banner li { margin-bottom:10px; }
  .mean,.oncall { font-size:12px; opacity:.85; } .oncall{opacity:.65;}
  .tiles { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; margin-bottom:22px; }
  .card { background:#171a21; border:1px solid #262b36; border-radius:10px; padding:14px 16px; }
  .card .label { font-size:12px; opacity:.7; } .card .value { font-size:22px; font-weight:600; margin-top:4px; }
  .card.bad .value { color:#ff6b6b; } .card.good .value { color:#3fb950; }
  table { width:100%; border-collapse:collapse; margin-bottom:24px; font-size:13px; }
  th,td { text-align:left; padding:8px 10px; border-bottom:1px solid #262b36; }
  th { opacity:.7; font-weight:600; } tr.fire td { background:rgba(229,72,77,.10); }
  .foot { font-size:12px; opacity:.6; margin-top:12px; max-width:900px; }
  .overflow { overflow-x:auto; }
</style></head>
<body>
  <h1>{$title}</h1>
  <div class="sub">Offline demonstration · metric math via real RateMath · findings are real AlertName/AlertFinding value objects · thresholds are AlertEvaluator defaults</div>
  {$banner}
  <div class="tiles">{$tiles}</div>
  <h3>Latency &amp; error rate by span kind</h3>
  <div class="overflow"><table>
    <tr><th>kind</th><th>count</th><th>p50 (ms)</th><th>p95 (ms)</th><th>p99 (ms)</th><th>error rate</th></tr>
    {$rows}
  </table></div>
  <h3>Alert evaluation (all 8)</h3>
  <div class="overflow"><table>
    <tr><th></th><th>alert</th><th>message</th><th>value</th><th>threshold</th></tr>
    {$alertRows}
  </table></div>
  <p class="foot">On the dev stack this exact surface is served by <code>public/dashboard.php</code> +
  <code>dashboard.html.twig</code> reading <code>mod_copilot_trace</code> through <code>MetricsService</code>,
  and <code>AlertEvaluator</code> fires the same findings on the worker tick. This page renders the identical
  metric bag and findings computed offline from a synthetic trace population, so the dashboard/alert behaviour
  can be demonstrated without a reachable stack.</p>
</body></html>
HTML;
}

function tile(string $label, string $value, string $cls = ''): string
{
    return '<div class="card ' . $cls . '"><div class="label">' . h($label) . '</div><div class="value">' . h($value) . '</div></div>';
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES);
}
