<?php

/**
 * Measure REAL prompt sizes by assembling the production prompts over the
 * committed synthetic-patient fixtures, then feed the measured token counts
 * through the module's own LlmCostEstimate. This replaces the hand-waved
 * TokIn_synth / TokCached / TokIncr estimates in ops/cost-analysis.md with
 * numbers derived from actual PromptAssembler / ChatPromptAssembler output.
 *
 * What is exact vs derived:
 *   - Prompt CHARACTER and BYTE counts are measured exactly from the real
 *     assembled system+user+responseSchema strings (no estimate).
 *   - TOKENS are derived from the measured character count using Vertex/Gemini's
 *     own published rule of thumb, ~4 characters per token for English text
 *     (https://ai.google.dev/gemini-api/docs/tokens). We do NOT call Vertex
 *     countTokens (network) here; the derivation is labelled everywhere it is
 *     used. This is a strict improvement over the prior pure guesses: the input
 *     the ratio is applied to is now real production-assembled prompt text over
 *     real fixture facts, not a number picked from the air.
 *   - OUTPUT tokens cannot be measured without a live generation; the output
 *     figures stay flagged as estimates (a synthesis narrative / chat answer of
 *     a stated shape), unchanged.
 *
 * Usage: php measure-tokens.php [--json] [--out=FILE]
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Chat\ChatPromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Observability\LlmCostEstimate;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptContext;

$moduleRoot = require __DIR__ . '/_autoload.php';

const CHARS_PER_TOKEN = 4.0; // Gemini published rule of thumb for English text.

$asJson = in_array('--json', $argv, true);
$outFile = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--out=')) {
        $outFile = substr($a, 6);
    }
}

/** Derive a token count from an exact character measurement. */
function tokens_from_chars(int $chars): int
{
    return (int)ceil($chars / CHARS_PER_TOKEN);
}

/**
 * Hydrate a committed fixture fact (which ships with fact_id:null) into a real
 * Fact by reconstructing its FactValue + Citations and computing the content-
 * address id, exactly as U3 would at ingest.
 *
 * @param array<string, mixed> $raw
 */
function hydrate_fact(array $raw): Fact
{
    $capability = Capability::from((string)$raw['capability']);
    $kind = FactKind::from((string)$raw['kind']);

    $citations = [];
    foreach (($raw['citations'] ?? []) as $c) {
        if (is_array($c)) {
            $citations[] = Citation::fromArray($c);
        }
    }

    $value = null;
    if (isset($raw['value']) && is_array($raw['value'])) {
        $value = FactValue::fromArray($raw['value']);
    }

    $raw['fact_id'] = FactId::compute($capability, $kind, $citations, $value);

    return Fact::fromArray($raw);
}

/**
 * @return list<Fact>
 */
function load_fixture_facts(string $file): array
{
    $data = json_decode((string)file_get_contents($file), true);
    $facts = [];
    foreach (($data['facts'] ?? []) as $raw) {
        if (is_array($raw)) {
            $facts[] = hydrate_fact($raw);
        }
    }
    return $facts;
}

$fixtureDir = $moduleRoot . '/tests/Seed/fixtures/expected';
$fixtures = glob($fixtureDir . '/*.json') ?: [];
sort($fixtures);

$assembler = new PromptAssembler();
$chatAssembler = new ChatPromptAssembler();
$context = new PromptContext('endo-previsit-v1', 'reduce-v1');
$chatContext = new PromptContext('endo-chat-v1', 'chat-v1');
$identifiers = new PatientIdentifiers('Jane Q. Sampleton', 'MRN-778812', '1968-04-11', '19 Birchwood Ln, Springfield');

$perPatient = [];
foreach ($fixtures as $file) {
    $facts = load_fixture_facts($file);
    if ($facts === []) {
        continue;
    }
    $name = basename($file, '.json');

    // ---- synthesis reduce prompt (real assembly) ----
    $req = $assembler->assemble($facts, $context, $identifiers);
    $sysChars = strlen($req->systemInstructions);
    $userChars = strlen($req->userContent);
    $schemaChars = strlen((string)json_encode($req->responseSchema));
    $synthInputChars = $sysChars + $userChars + $schemaChars;
    $synthInputTokens = tokens_from_chars($synthInputChars);

    // ---- chat prompt (real assembly): cached block vs turn-incremental ----
    // Cached block = the identical-every-turn prefix (system instructions +
    // preloaded PATIENT+FACTS). Incremental = the turn-specific delta.
    $chatReq = $chatAssembler->assemble(
        $facts,
        null,
        [],
        'What changed since her last visit and what should I watch?',
        [],
        null,
        $chatContext,
        $identifiers,
    );
    $chatSysChars = strlen($chatReq->prompt->systemInstructions);
    $chatUserChars = strlen($chatReq->prompt->userContent);
    // The preloaded fact block is the CanonicalSerializer portion; approximate
    // TokCached as system + fact block, TokIncr as the question/transcript tail.
    // We measure the whole assembled prompt exactly and split on the SESSION
    // FACTS / QUESTION markers the assembler emits.
    $cachedChars = $chatSysChars + fact_block_chars($chatReq->prompt->userContent);
    $incrChars = max(0, ($chatSysChars + $chatUserChars) - $cachedChars);

    $perPatient[] = [
        'fixture' => $name,
        'fact_count' => count($facts),
        'synthesis' => [
            'system_chars' => $sysChars,
            'user_chars' => $userChars,
            'schema_chars' => $schemaChars,
            'input_chars' => $synthInputChars,
            'input_tokens_derived' => $synthInputTokens,
        ],
        'chat' => [
            'cached_block_chars' => $cachedChars,
            'cached_block_tokens_derived' => tokens_from_chars($cachedChars),
            'incremental_chars' => $incrChars,
            'incremental_tokens_derived' => tokens_from_chars($incrChars),
        ],
    ];
}

// ---- synthetic HEAVY patient: an upper bound. The committed fixtures are
// mid-complexity endo visits (4-6 facts); a long-history patient windows to
// PromptFactWindow's cap. Build a fact set well past the window so the
// assembler's own windowing decides the ceiling, and measure that. ----
$heavyFacts = [];
$dates = [];
for ($m = 0; $m < 30; $m++) {
    // 30 monthly A1c draws (windowing will trim to the narrative cap).
    $dates[] = sprintf('2023-%02d-07', ($m % 12) + 1);
}
$pk = 1;
foreach ($dates as $i => $d) {
    $heavyFacts[] = \OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory::a1cTrendPoint(1, $pk++, number_format(6.5 + ($i * 0.05), 1), $d);
}
for ($k = 0; $k < 6; $k++) {
    $heavyFacts[] = \OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory::medEvent(1, $pk++);
}
$heavyReq = $assembler->assemble($heavyFacts, $context, $identifiers);
$heavyChars = strlen($heavyReq->systemInstructions) + strlen($heavyReq->userContent) + strlen((string)json_encode($heavyReq->responseSchema));
$perPatient[] = [
    'fixture' => 'synthetic_heavy',
    'fact_count' => count($heavyFacts),
    'synthesis' => [
        'system_chars' => strlen($heavyReq->systemInstructions),
        'user_chars' => strlen($heavyReq->userContent),
        'schema_chars' => strlen((string)json_encode($heavyReq->responseSchema)),
        'input_chars' => $heavyChars,
        'input_tokens_derived' => tokens_from_chars($heavyChars),
    ],
    'chat' => [
        'cached_block_chars' => 0,
        'cached_block_tokens_derived' => 0,
        'incremental_chars' => 0,
        'incremental_tokens_derived' => 0,
    ],
    'note' => 'synthetic long-history upper bound; ' . count($heavyFacts) . ' facts pre-window, windowed by PromptFactWindow',
];

if ($perPatient === []) {
    fwrite(STDERR, "measure-tokens: no fixtures hydrated from {$fixtureDir}\n");
    exit(1);
}

// ---- aggregate (median across the real fixtures only; the synthetic heavy
// case is reported separately as an upper bound, not folded into the median). ----
$realFixtures = array_values(array_filter($perPatient, static fn ($p) => $p['fixture'] !== 'synthetic_heavy'));
$heavyCase = array_values(array_filter($perPatient, static fn ($p) => $p['fixture'] === 'synthetic_heavy'))[0] ?? null;
$synthTokens = array_map(static fn ($p) => $p['synthesis']['input_tokens_derived'], $realFixtures);
$cachedTokens = array_map(static fn ($p) => $p['chat']['cached_block_tokens_derived'], $realFixtures);
$incrTokens = array_map(static fn ($p) => $p['chat']['incremental_tokens_derived'], $realFixtures);

$measured = [
    'chars_per_token_ratio' => CHARS_PER_TOKEN,
    'fixtures_measured' => count($perPatient),
    'TokIn_synth_measured' => median_int($synthTokens),
    'TokCached_measured' => median_int($cachedTokens),
    'TokIncr_measured' => median_int($incrTokens),
    // Output tokens remain estimates (no live generation to measure).
    'TokOut_synth_estimate' => 800,
    'TokOut_turn_estimate' => 300,
    'TokIn_synth_heavy_upperbound' => $heavyCase !== null ? $heavyCase['synthesis']['input_tokens_derived'] : null,
];

// ---- per-call cost via the module's own estimator (Gemini 2.5 Pro) ----
$perDocumentVisionEstimate = LlmCostEstimate::estimateUsd('gemini-2.5-pro', 2000, 400); // 1-2 pg scan
$synthCallCost = LlmCostEstimate::estimateUsd('gemini-2.5-pro', $measured['TokIn_synth_measured'], $measured['TokOut_synth_estimate']);
$chatTurn1Cost = LlmCostEstimate::estimateUsd('gemini-2.5-pro', $measured['TokCached_measured'] + $measured['TokIncr_measured'], $measured['TokOut_turn_estimate']);

$record = [
    'measured_at' => date('Y-m-d\TH:i:sP'),
    'git_commit' => trim((string)@shell_exec('git -C ' . escapeshellarg($moduleRoot) . ' rev-parse --short HEAD 2>/dev/null')) ?: 'unknown',
    'method' => 'exact char/byte count of real PromptAssembler/ChatPromptAssembler output over committed fixtures; tokens = ceil(chars / ' . CHARS_PER_TOKEN . ') per Gemini published ratio',
    'measured' => $measured,
    'per_call_cost_usd' => [
        'synthesis_reduce_pro' => $synthCallCost,
        'chat_turn1_pro' => $chatTurn1Cost,
        'vision_extraction_pro_1_2pg' => $perDocumentVisionEstimate,
    ],
    'per_patient' => $perPatient,
];

if ($outFile !== '') {
    file_put_contents($outFile, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}

if ($asJson) {
    echo json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

// ---- human report ----
echo "Clinical Co-Pilot — MEASURED prompt sizes (real assembly over fixtures)\n";
echo str_repeat('=', 74) . "\n";
echo "method: exact char count of assembled prompts; tokens = chars / " . CHARS_PER_TOKEN . " (Gemini ratio)\n\n";
printf("%-14s %5s %12s %14s %14s %13s\n", 'fixture', 'facts', 'synth chars', 'synth tokens', 'cached tokens', 'incr tokens');
foreach ($perPatient as $p) {
    printf(
        "%-14s %5d %12d %14d %14d %13d\n",
        $p['fixture'],
        $p['fact_count'],
        $p['synthesis']['input_chars'],
        $p['synthesis']['input_tokens_derived'],
        $p['chat']['cached_block_tokens_derived'],
        $p['chat']['incremental_tokens_derived'],
    );
}
echo "\nMedian across real fixtures (the numbers to use in ops/cost-analysis.md):\n";
printf("  TokIn_synth  = %6d tokens   (was estimated 8000; heavy-patient upper bound = %d)\n", $measured['TokIn_synth_measured'], (int)$measured['TokIn_synth_heavy_upperbound']);
printf("  TokCached    = %6d tokens   (was estimated 8000)\n", $measured['TokCached_measured']);
printf("  TokIncr      = %6d tokens   (was estimated  700; turn-1 empty transcript — grows with session)\n", $measured['TokIncr_measured']);
echo "\nPer-call cost via LlmCostEstimate (Gemini 2.5 Pro list rates):\n";
printf("  synthesis reduce (miss)      = \$%.5f\n", (float)$synthCallCost);
printf("  chat turn 1 (cache write)    = \$%.5f\n", (float)$chatTurn1Cost);
printf("  vision extraction / document = \$%.5f\n", (float)$perDocumentVisionEstimate);
exit(0);

// =========================================================================

/**
 * Character length of the preloaded PATIENT + SESSION FACTS block within the
 * chat user content — the portion identical on every turn (the cached prefix).
 * The assembler emits a "SESSION FACTS" marker; everything up to the turn
 * question is the cacheable block. If markers move, this degrades to counting
 * the whole user content as cached (a conservative over-count of the cache).
 */
function fact_block_chars(string $userContent): int
{
    // The turn-specific tail starts at the first of these markers.
    foreach (['CONVERSATION', 'QUESTION', 'PRIOR VERIFICATION', 'NARRATIVE'] as $marker) {
        $pos = strpos($userContent, $marker);
        if ($pos !== false) {
            return $pos;
        }
    }
    return strlen($userContent);
}

/** @param list<int> $values */
function median_int(array $values): int
{
    if ($values === []) {
        return 0;
    }
    sort($values);
    $n = count($values);
    $mid = intdiv($n, 2);
    if ($n % 2 === 1) {
        return $values[$mid];
    }
    return (int)round(($values[$mid - 1] + $values[$mid]) / 2);
}
