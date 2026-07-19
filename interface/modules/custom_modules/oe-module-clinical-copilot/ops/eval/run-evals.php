<?php

/**
 * Week 2 eval gate runner — 54-case golden set, boolean rubrics, exit-code gate.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

/*
 * The spec's HARD GATE: a 54-case (50 original + 4 medication_list) golden
 * set with BOOLEAN rubrics (not 1-10
 * ratings) that blocks regressions before they reach the demo. The evaluation
 * engine lives in EvalGate (ops/eval/EvalGate.php) so the SAME logic backs both
 * this CLI gate AND the dashboard's "Run evals" button — this file is just the
 * CLI face: argument handling, the human report, baseline update, exit code.
 *
 * It stays DETERMINISTIC and needs NO live model or database: every case
 * supplies the model's output verbatim and is fed through the SAME production
 * code paths (ExtractionSchema, ExtractionClient with a stub, the RAG
 * retriever) the app uses. Introduce a real regression (break schema
 * validation, drop a citation, let the retriever return the wrong chunk) and a
 * rubric's pass-rate falls below baseline, and this process exits non-zero.
 *
 * Rubric categories (spec §6): schema_valid, citation_present,
 * factually_consistent, safe_refusal, no_phi_in_logs.
 *
 * Usage:
 *   php ops/eval/run-evals.php                 # gate: compare to baseline, exit 0/1
 *   php ops/eval/run-evals.php --update-baseline  # rewrite baseline.json from this run
 *   php ops/eval/run-evals.php --report <path> # gate, plus write a per-case JSON report
 *                                              # (ids/categories/rubric booleans only —
 *                                              # never case content; the committed copy
 *                                              # lives at ops/eval/results-latest.json)
 *   php ops/eval/run-evals.php --record        # gate, then persist the outcome for the
 *                                              # eval-regression alert (needs the OpenEMR DB;
 *                                              # CLINICAL_COPILOT_EVAL_RECORD=1 does the same)
 *
 * Recording is strictly OPT-IN and best-effort: without --record (or the env
 * var) this runner stays exactly as DB-free and network-free as CI needs it to
 * be; with it, the run's summary is written to the `eval_last_run` cadence
 * config row (the same row the dashboard's "Run evals" button writes), so the
 * AlertEvaluator eval-regression alert also arms from CLI/CI runs that DO have
 * a database. Any bootstrap or persistence failure degrades to the pure
 * offline behavior -- the gate's own exit code is never affected.
 */

use OpenEMR\Modules\ClinicalCopilot\Ops\Eval\EvalGate;

$moduleRoot = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($moduleRoot): void {
    $prefix = 'OpenEMR\\Modules\\ClinicalCopilot\\';
    if (str_starts_with($class, $prefix)) {
        $file = $moduleRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// EvalGate lives under ops/eval/ (not src/), so require it explicitly.
require_once __DIR__ . '/EvalGate.php';

$evalDir = __DIR__;
$updateBaseline = in_array('--update-baseline', $argv, true);
$recordRequested = in_array('--record', $argv, true) || getenv('CLINICAL_COPILOT_EVAL_RECORD') === '1';

$reportPath = null;
$reportFlagIdx = array_search('--report', $argv, true);
if ($reportFlagIdx !== false) {
    $reportPath = $argv[$reportFlagIdx + 1] ?? null;
    if (!is_string($reportPath) || $reportPath === '' || str_starts_with($reportPath, '--')) {
        fwrite(STDERR, "eval: --report requires a file path argument.\n");
        exit(2);
    }
}

try {
    $result = (new EvalGate($evalDir))->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'eval: ' . $e->getMessage() . "\n");
    exit(2);
}

if ($reportPath !== null) {
    writeReport($reportPath, $result);
    echo "eval: per-case report written to {$reportPath}\n";
}

$rates = $result['rates'];
$tally = $result['tally'];

if ($updateBaseline) {
    file_put_contents(
        $evalDir . '/baseline.json',
        json_encode(['rubrics' => $rates, 'case_count' => $result['case_count']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
    );
    echo "eval: baseline updated (" . $result['case_count'] . " cases)\n";
    printReport($rates, $tally, [], []);
    exit(0);
}

printReport($rates, $tally, $result['failures'], $result['regressions']);

// Opt-in outcome persistence (never on --update-baseline: a re-baselining
// run's regressions are measured against the baseline it is replacing, and
// recording them would arm the alert on a deliberately superseded number).
// Bootstrapping OpenEMR happens HERE, at top-level scope, only after the
// gate result is already computed -- the default (no flag, no env var) path
// never touches globals.php, the DB, or the network.
if ($recordRequested) {
    $recorded = false;
    // ops/eval -> module root -> custom_modules -> modules -> interface/globals.php
    $globalsPath = $moduleRoot . '/../../../globals.php';
    if (is_file($globalsPath)) {
        try {
            $ignoreAuth = true;
            $sessionAllowWrite = true;
            $_GET['site'] = $_GET['site'] ?? 'default';
            require_once $globalsPath;
            (new \OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceConfigStore())->recordEvalRun($result);
            $recorded = true;
        } catch (\Throwable) {
            // Degrade to the pure offline behavior -- the gate's exit code is
            // the contract; a persistence failure must never change it. The
            // generic notice below is all that surfaces (no exception detail).
            $recorded = false;
        }
    }
    if ($recorded) {
        echo "\neval: outcome recorded (eval_last_run) -- the eval-regression alert now reflects this run.\n";
    } else {
        fwrite(STDERR, "\neval: --record requested but no OpenEMR DB was reachable -- outcome not recorded (gate result unaffected).\n");
    }
}

if (!$result['passed']) {
    fwrite(STDERR, "\neval: GATE FAILED — " . count($result['regressions']) . " rubric regression(s).\n");
    exit(1);
}

echo "\neval: GATE PASSED — all rubrics at or above baseline.\n";
exit(0);

/**
 * Write the per-case JSON report: case ids, categories, and rubric booleans
 * ONLY — never case inputs or model output, so the file stays PHI-free by
 * construction. Deterministic (no timestamp), so the committed copy
 * (ops/eval/results-latest.json) diffs cleanly between runs.
 *
 * @param array{
 *     rates: array<string, float>,
 *     tally: array<string, array{pass: int, total: int}>,
 *     regressions: list<string>,
 *     passed: bool,
 *     case_count: int,
 *     cases: list<array{id: string, category: string, rubrics: array<string, bool>}>
 * } $result
 */
function writeReport(string $path, array $result): void
{
    $report = [
        'case_count' => $result['case_count'],
        'passed' => $result['passed'],
        'rates' => $result['rates'],
        'tally' => $result['tally'],
        'regressions' => $result['regressions'],
        'cases' => $result['cases'],
    ];
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}

/**
 * @param array<string, float> $rates
 * @param array<string, array{pass: int, total: int}> $tally
 * @param list<string> $failures
 * @param list<string> $regressions
 */
function printReport(array $rates, array $tally, array $failures, array $regressions): void
{
    echo "\nClinical Co-Pilot — Week 2 eval gate\n";
    echo str_repeat('-', 52) . "\n";
    foreach ($rates as $rubric => $rate) {
        $t = $tally[$rubric] ?? ['pass' => 0, 'total' => 0];
        printf("  %-22s %5.1f%%  (%d/%d)\n", $rubric, $rate * 100, $t['pass'], $t['total']);
    }
    if ($failures !== []) {
        echo "\n  failing cases:\n";
        foreach ($failures as $f) {
            echo "    - {$f}\n";
        }
    }
    if ($regressions !== []) {
        echo "\n  regressions:\n";
        foreach ($regressions as $r) {
            echo "    ! {$r}\n";
        }
    }
}
