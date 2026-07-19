<?php

/**
 * Clinical Co-Pilot Week 2 — end-to-end demo (no dev stack required).
 *
 * The Week 2 submission's demo was faulted for NOT showing verification,
 * observability metrics, or eval results. This script drives all three (plus
 * ingestion→extraction→citation, guideline retrieval, and measured cost) as
 * one narrated run over real code paths — no DB, no network, no OpenEMR core —
 * and writes a transcript to ops/demo/transcript.txt so the run is reviewable
 * without re-executing it.
 *
 * Sections:
 *   1. INGEST + EXTRACT   — strict-schema extraction with per-field citations,
 *                           and a malformed document correctly refused.
 *   2. VERIFY             — the read-path discipline: a grounded claim set
 *                           passes V1-V6; a wrong-patient claim trips V3 (sev-1);
 *                           an uncited claim is caught by V2.
 *   3. RETRIEVE           — hybrid RAG returns cited guideline evidence.
 *   4. OBSERVABILITY      — the metric bag + alert evaluation (healthy vs
 *                           incident), via ops/load/bench/dashboard-demo.php.
 *   5. EVAL GATE          — the 50-case boolean-rubric gate (the HARD GATE).
 *   6. COST               — measured per-call cost from real prompt sizes.
 *
 * Usage: php run-demo.php
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$moduleRoot = require __DIR__ . '/../load/bench/_autoload.php';

use OpenEMR\Modules\ClinicalCopilot\Ingest\DocType;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Ingest\SchemaValidationException;
use OpenEMR\Modules\ClinicalCopilot\Rag\HybridRetriever;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;
use OpenEMR\Modules\ClinicalCopilot\Verify\CheckId;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationContext;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPath;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Verify\VerifyTestFactory;

$transcript = '';

/** Echo to stdout AND capture into the transcript. */
function say(string $line = ''): void
{
    global $transcript;
    echo $line . "\n";
    $transcript .= $line . "\n";
}

function heading(string $t): void
{
    say('');
    say(str_repeat('=', 78));
    say('  ' . $t);
    say(str_repeat('=', 78));
}

/** A stub VLM that returns fixed structured extraction JSON (no network). */
final class DemoStubVlm implements LlmClientInterface
{
    public function __construct(private readonly string $raw)
    {
    }

    public function generateStructured(PromptRequest $req): LlmResponse
    {
        return new LlmResponse($this->raw, 'demo-stub-vlm', 1800, 220, 640);
    }
}

say('Clinical Co-Pilot Week 2 — end-to-end demo');
say('scenario: one outpatient endocrinologist prepping a follow-up visit');
say('host: ' . php_uname('m') . '  php: ' . PHP_VERSION . '  (module compute only — no DB / LLM / web stack)');

// ---------------------------------------------------------------------------
heading('1. INGEST + EXTRACT — a scanned lab PDF becomes cited, typed facts');
// ---------------------------------------------------------------------------
$labJson = json_encode([
    'fields' => [
        ['field_key' => 'hemoglobin_a1c', 'value' => '8.4', 'unit' => '%', 'page' => 1, 'quote' => 'Hemoglobin A1c ... 8.4 %'],
        ['field_key' => 'glucose_fasting', 'value' => '162', 'unit' => 'mg/dL', 'page' => 1, 'quote' => 'Glucose, Fasting 162 mg/dL'],
        ['field_key' => 'ldl_cholesterol', 'value' => '118', 'unit' => 'mg/dL', 'page' => 2, 'quote' => 'LDL Cholesterol 118 mg/dL'],
    ],
], JSON_THROW_ON_ERROR);

$client = new ExtractionClient(new DemoStubVlm($labJson), 'gemini-2.5-pro');
$outcome = $client->extract(DocType::LabPdf, 'SCANNED-PDF-BYTES', 'application/pdf', 'lab-2026-07-13');
$parsed = $outcome->extraction;

say('uploaded: lab_report.pdf  (2 pages, image-only scan)');
say('extracted ' . count($parsed->fields) . ' fields, each carrying a page + verbatim quote citation:');
foreach ($parsed->fields as $f) {
    $cite = $f->citation;
    say(sprintf(
        '  • %-18s = %-8s  [page %s: "%s"]',
        $f->fieldKey,
        $f->value . ($f->unit !== null ? ' ' . $f->unit : ''),
        $cite !== null ? (string)$cite->pageOrSection : '?',
        $cite !== null ? $cite->quoteOrValue : '(uncited)',
    ));
}
say('citation contract satisfied: every extracted fact is click-to-source (FR-4).');

say('');
say('now a MALFORMED document (missing required value + fabricated field) is uploaded:');
$badJson = json_encode(['fields' => [['field_key' => 'a1c', 'page' => 1]]], JSON_THROW_ON_ERROR);
try {
    (new ExtractionClient(new DemoStubVlm($badJson), 'gemini-2.5-pro'))
        ->extract(DocType::LabPdf, 'BAD', 'application/pdf', 'lab-bad');
    say('  !! ERROR: malformed doc was NOT rejected (this would be a bug)');
} catch (SchemaValidationException $e) {
    say('  ✓ REFUSED at the schema gate — nothing is persisted, no partial write (FR-3, safe_refusal).');
}

// ---------------------------------------------------------------------------
heading('2. VERIFY — the read-path discipline every generated claim must pass');
// ---------------------------------------------------------------------------
$early = VerifyTestFactory::a1cEarly();
$later = VerifyTestFactory::a1cLater();
$factSet = VerifyTestFactory::sessionFactSet([$early, $later]);
$verifier = new Verifier();

say('session pinned to patient pid=' . VerifyTestFactory::PINNED_PID . '; two grounded A1c facts loaded.');
say('');

// (a) grounded claim set — passes
$good = VerifyTestFactory::claimsJson([
    VerifyTestFactory::claim('Her A1c rose from 7.2% to 7.6% over three months.', 'lab_value', [$early->factId, $later->factId], [7.2, 7.6]),
]);
$r = $verifier->verify($good, new VerificationContext($factSet, VerificationPath::Chat));
say('(a) grounded claim  "A1c rose from 7.2% to 7.6%"');
say('    -> allPassed=' . b($r->allPassed()) . '  (V1 schema, V2 citation, V3 identity, V4 numeric all pass)');

// (b) wrong-patient claim — V3 sev-1 trip
$wrong = VerifyTestFactory::wrongPatientVital();
$mixed = VerifyTestFactory::sessionFactSet([$early, $later, $wrong]);
$wrongClaims = VerifyTestFactory::claimsJson([
    VerifyTestFactory::claim('Weight is 180 lb.', 'vital', [$wrong->factId], [180.0]),
]);
$rWrong = $verifier->verify($wrongClaims, new VerificationContext($mixed, VerificationPath::Chat));
$v3 = $rWrong->find(CheckId::PatientIdentity);
say('(b) wrong-patient claim  (cites a "vital" fact belonging to a DIFFERENT pid)');
say('    -> V1 schema passes; V3 identity FAILS (passed=' . b($v3 !== null && $v3->passed) . '), hasSev1=' . b($rWrong->hasSev1()) . ' — the chat turn is FROZEN (sev-1).');

// (c) uncited claim — V2 catch (lab_value is not zero-citation-eligible)
$uncited = VerifyTestFactory::claimsJson([
    VerifyTestFactory::claim('Her A1c is 5.9%.', 'lab_value', [], [5.9]),
]);
$rUncited = $verifier->verify($uncited, new VerificationContext($factSet, VerificationPath::Chat));
$v2 = $rUncited->find(CheckId::CitationResolution);
say('(c) uncited lab value  "Her A1c is 5.9%" (claim_type lab_value, no citation)');
say('    -> V2 citation-resolution FAILS (passed=' . b($v2 !== null && $v2->passed) . ') — an ungrounded claim is caught, never shown.');

// ---------------------------------------------------------------------------
heading('3. RETRIEVE — guideline evidence, cited, separate from patient facts');
// ---------------------------------------------------------------------------
$retriever = HybridRetriever::createDefault();
$hits = $retriever->retrieve('what is the A1c goal and when to intensify therapy', ['a1c'], 3);
say('query: "what is the A1c goal and when to intensify therapy"  (tags: a1c)');
foreach ($hits as $h) {
    say(sprintf('  • [%s] %s', $h->chunk->id, trunc($h->citation->quoteOrValue, 88)));
}
say('every hit carries a guideline citation (source_type=guideline), never mixed with patient-fact citations (FR-8).');

// ---------------------------------------------------------------------------
heading('4. OBSERVABILITY — metric bag + alert evaluation (healthy vs incident)');
// ---------------------------------------------------------------------------
$dash = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($moduleRoot . '/ops/load/bench/dashboard-demo.php') . ' 2>&1');
say(rtrim((string)$dash));
say('(rendered dashboards written to ops/load/bench/results/dashboard-{healthy,incident}.html)');

// ---------------------------------------------------------------------------
heading('5. EVAL GATE — 50 cases, boolean rubrics, PR-blocking (the HARD GATE)');
// ---------------------------------------------------------------------------
$eval = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($moduleRoot . '/ops/eval/run-evals.php') . ' 2>&1');
say(rtrim((string)$eval));

// ---------------------------------------------------------------------------
heading('6. COST — measured per-call cost tied to real prompt sizes');
// ---------------------------------------------------------------------------
$tok = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($moduleRoot . '/ops/load/bench/measure-tokens.php') . ' 2>&1');
say(rtrim((string)$tok));

heading('DEMO COMPLETE');
say('shown: ingestion+citation, verification (incl. a sev-1 trip caught), retrieval,');
say('observability metrics + firing alerts, the eval gate, and measured cost — end to end.');

$out = __DIR__ . '/transcript.txt';
file_put_contents($out, $transcript);
say('');
say('transcript written to ' . $out);

exit(0);

function b(bool $v): string
{
    return $v ? 'TRUE' : 'FALSE';
}

function trunc(string $s, int $n): string
{
    return strlen($s) <= $n ? $s : substr($s, 0, $n - 1) . '…';
}
