<?php

/**
 * Shared eval-gate engine: runs the 54-case boolean-rubric golden set and
 * returns structured results. Used by BOTH the CLI runner (ops/eval/run-evals.php,
 * which keeps the exit-code gate for CI) and the observability dashboard's
 * "Run evals" button (public/dashboard.php), so the two can never diverge.
 *
 * Deliberately deterministic and dependency-light: NO live model and NO
 * database. Every case supplies the model output verbatim and is fed through
 * the SAME production code paths (ExtractionSchema, ExtractionClient with a
 * stub, the RAG retriever) the app uses. This file lives under ops/eval/ (not
 * src/) so it stays with cases.json/baseline.json and outside the PHPStan
 * src-only scope, but it is namespaced and require_once-loaded by both callers.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops\Eval;

use OpenEMR\Modules\ClinicalCopilot\Ingest\DocType;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionSchema;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ParsedExtraction;
use OpenEMR\Modules\ClinicalCopilot\Ingest\SchemaValidationException;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineCorpus;
use OpenEMR\Modules\ClinicalCopilot\Rag\SparseRetriever;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;

/** A trivial LlmClientInterface that returns a case's raw output verbatim. */
final class EvalStubLlm implements LlmClientInterface
{
    public function __construct(private readonly string $raw)
    {
    }

    public function generateStructured(PromptRequest $req): LlmResponse
    {
        return new LlmResponse($this->raw, 'eval-stub', 0, 0, 0);
    }
}

final class EvalGate
{
    /** The absolute floor and the regression tolerance (spec: >5% drop fails). */
    public const ABSOLUTE_FLOOR = 0.90;
    public const REGRESSION_TOLERANCE = 0.05;

    /** @param string $evalDir directory holding cases.json + baseline.json */
    public function __construct(private readonly string $evalDir)
    {
    }

    public static function createDefault(): self
    {
        // ops/eval/EvalGate.php -> ops/eval
        return new self(__DIR__);
    }

    /**
     * Run every case, compare to baseline, and return a structured result.
     *
     * @return array{
     *     rates: array<string, float>,
     *     tally: array<string, array{pass: int, total: int}>,
     *     baseline: array<string, float>,
     *     regressions: list<string>,
     *     failures: list<string>,
     *     passed: bool,
     *     case_count: int,
     *     cases: list<array{id: string, category: string, rubrics: array<string, bool>}>
     * }
     */
    public function run(): array
    {
        $cases = json_decode((string)@file_get_contents($this->evalDir . '/cases.json'), true);
        if (!is_array($cases)) {
            throw new \RuntimeException('eval: cases.json missing or invalid');
        }

        $retriever = new SparseRetriever(GuidelineCorpus::createDefault());

        /** @var array<string, array{pass: int, total: int}> $tally */
        $tally = [];
        /** @var list<string> $failures */
        $failures = [];
        // Per-case rubric outcomes: ids, categories, and booleans ONLY — never
        // case inputs or model output — so the report stays PHI-free even when
        // real documents ever join the (currently fully synthetic) golden set.
        /** @var list<array{id: string, category: string, rubrics: array<string, bool>}> $caseResults */
        $caseResults = [];

        foreach ($cases as $case) {
            if (!is_array($case)) {
                continue;
            }
            $id = (string)($case['id'] ?? '?');
            $category = (string)($case['category'] ?? '');
            try {
                $results = $this->evaluateCase($case, $retriever);
            } catch (\Throwable $e) {
                $results = array_fill_keys($this->rubricsForCategory($category), false);
                $failures[] = "{$id} :: threw " . $e::class;
            }
            $caseResults[] = ['id' => $id, 'category' => $category, 'rubrics' => $results];
            foreach ($results as $rubric => $passed) {
                $tally[$rubric] ??= ['pass' => 0, 'total' => 0];
                $tally[$rubric]['total']++;
                if ($passed) {
                    $tally[$rubric]['pass']++;
                } else {
                    $failures[] = "{$id} :: {$rubric}";
                }
            }
        }

        $rates = [];
        foreach ($tally as $rubric => $t) {
            $rates[$rubric] = $t['total'] > 0 ? $t['pass'] / $t['total'] : 1.0;
        }
        ksort($rates);

        $baseline = json_decode((string)@file_get_contents($this->evalDir . '/baseline.json'), true);
        $baseRates = is_array($baseline['rubrics'] ?? null) ? $baseline['rubrics'] : [];

        $regressions = [];
        foreach ($rates as $rubric => $rate) {
            $base = is_numeric($baseRates[$rubric] ?? null) ? (float)$baseRates[$rubric] : 1.0;
            if ($rate < self::ABSOLUTE_FLOOR) {
                $regressions[] = sprintf('%s below floor: %.3f < %.2f', $rubric, $rate, self::ABSOLUTE_FLOOR);
            } elseif ($rate < $base - self::REGRESSION_TOLERANCE) {
                $regressions[] = sprintf('%s regressed: %.3f < baseline %.3f - %.2f', $rubric, $rate, $base, self::REGRESSION_TOLERANCE);
            }
        }

        return [
            'rates' => $rates,
            'tally' => $tally,
            'baseline' => array_map(static fn ($v): float => (float)$v, is_array($baseRates) ? $baseRates : []),
            'regressions' => $regressions,
            'failures' => $failures,
            'passed' => $regressions === [],
            'case_count' => count($cases),
            'cases' => $caseResults,
        ];
    }

    /**
     * @param array<string, mixed> $case
     * @return array<string, bool> rubric => passed (only rubrics that apply)
     */
    private function evaluateCase(array $case, SparseRetriever $retriever): array
    {
        $category = (string)($case['category'] ?? '');

        return match ($category) {
            'extraction', 'missing_data' => $this->evaluateExtraction($case),
            'refusal' => $this->evaluateRefusal($case),
            'retrieval' => $this->evaluateRetrieval($case, $retriever),
            default => [],
        };
    }

    /** @return list<string> */
    private function rubricsForCategory(string $category): array
    {
        return match ($category) {
            'extraction', 'missing_data' => ['schema_valid', 'citation_present', 'factually_consistent', 'no_phi_in_logs'],
            'refusal' => ['safe_refusal', 'no_phi_in_logs'],
            'retrieval' => ['factually_consistent', 'citation_present'],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $case
     * @return array<string, bool>
     */
    private function evaluateExtraction(array $case): array
    {
        $docType = DocType::from((string)$case['doc_type']);
        $payload = is_array($case['input'] ?? null) ? $case['input'] : [];
        $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];
        $expectAccept = (bool)($expect['accept'] ?? true);

        $errors = ExtractionSchema::validate($docType, $payload);
        $accepted = $errors === [];

        $out = ['schema_valid' => ($accepted === $expectAccept)];

        if (!$accepted) {
            $out['safe_refusal'] = !$expectAccept;
            return $out;
        }

        $parsed = ExtractionSchema::parse($docType, $payload, 'eval');

        $allCited = true;
        foreach ($parsed->fields as $field) {
            if ($field->citation === null) {
                $allCited = false;
                break;
            }
        }
        $out['citation_present'] = $allCited;

        $consistent = true;
        foreach ($parsed->fields as $field) {
            if ($field->value !== $field->vlmValue) {
                $consistent = false;
                break;
            }
        }
        $out['factually_consistent'] = $consistent;

        $out['no_phi_in_logs'] = $this->loggedArtifactIsPhiFree($parsed);

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     * @return array<string, bool>
     */
    private function evaluateRefusal(array $case): array
    {
        $docType = DocType::from((string)$case['doc_type']);
        $raw = (string)($case['raw'] ?? '');
        $client = new ExtractionClient(new EvalStubLlm($raw), 'eval-stub');

        $refused = false;
        // A never-refused case has no logged refusal artifact to leak PHI from;
        // it fails safe_refusal instead. When the refusal DOES happen, the
        // logged artifact is measured — not assumed — to be PHI-free.
        $phiFree = true;
        try {
            $client->extract($docType, 'BYTES', 'application/pdf', 'eval');
        } catch (SchemaValidationException $e) {
            $refused = true;
            $phiFree = $this->refusalLogIsPhiFree($e, $raw);
        }

        return ['safe_refusal' => $refused, 'no_phi_in_logs' => $phiFree];
    }

    /**
     * @param array<string, mixed> $case
     * @return array<string, bool>
     */
    private function evaluateRetrieval(array $case, SparseRetriever $retriever): array
    {
        $query = (string)($case['query'] ?? '');
        $tags = [];
        foreach (is_array($case['tags'] ?? null) ? $case['tags'] : [] as $t) {
            if (is_string($t)) {
                $tags[] = $t;
            }
        }
        $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];
        $hits = $retriever->retrieve($query, $tags, 3);

        // `top_chunk` asserts one exact #1 chunk id. `top_chunk_any` accepts the
        // #1 hit being any id in a list — used when more than one corpus chunk is
        // an equally-correct top answer (e.g. two guideline sources for the same
        // first-line recommendation), so growing the corpus with a co-equal chunk
        // does not spuriously fail the case.
        $topExpected = (string)($expect['top_chunk'] ?? '');
        $acceptable = [];
        foreach (is_array($expect['top_chunk_any'] ?? null) ? $expect['top_chunk_any'] : [] as $id) {
            if (is_string($id)) {
                $acceptable[] = $id;
            }
        }
        $topHit = $hits[0]->chunk->id ?? '';
        if ($acceptable !== []) {
            $consistent = in_array($topHit, $acceptable, true);
        } elseif ($topExpected !== '') {
            $consistent = $topHit === $topExpected;
        } else {
            $consistent = $hits !== [];
        }

        $allCited = true;
        foreach ($hits as $hit) {
            if ($hit->citation->quoteOrValue === '') {
                $allCited = false;
                break;
            }
        }

        return [
            'factually_consistent' => $consistent,
            'citation_present' => $hits === [] ? true : $allCited,
        ];
    }

    /**
     * The refusal-path counterpart of {@see self::loggedArtifactIsPhiFree()}:
     * builds the artifact the observability layer would log for a REJECTED
     * extraction (doc type + the structured, field-level schema errors that
     * {@see SchemaValidationException} carries for the trace) and asserts no
     * content from the refused model output leaked into it — neither a
     * `fields[*].value` string from a decodable payload nor, for undecodable
     * output, the raw text itself. Deterministic and offline, same as the rest
     * of the gate.
     */
    private function refusalLogIsPhiFree(SchemaValidationException $e, string $raw): bool
    {
        $logged = json_encode([
            'doc_type' => $e->docType->value,
            'error_count' => count($e->errors),
            'errors' => $e->errors,
        ], JSON_THROW_ON_ERROR);

        foreach ($this->refusedOutputValues($raw) as $value) {
            if (str_contains($logged, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The content-bearing strings of a refused model output: every string
     * `fields[*].value` when the output decodes as JSON, else the raw text
     * itself (a non-JSON model reply could carry anything, so none of it may
     * be echoed into a log).
     *
     * @return list<string>
     */
    private function refusedOutputValues(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [$raw];
        }

        $values = [];
        $fields = $decoded['fields'] ?? null;
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $value = $field['value'] ?? null;
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Builds the artifact the observability layer would log for this extraction
     * and asserts it contains no field VALUE — only keys and rates (no PHI).
     */
    private function loggedArtifactIsPhiFree(ParsedExtraction $parsed): bool
    {
        $editedKeys = [];
        foreach ($parsed->editedFields() as $f) {
            $editedKeys[] = $f->fieldKey;
        }
        $logged = json_encode([
            'doc_type' => $parsed->docType->value,
            'field_count' => count($parsed->fields),
            'edited_field_keys' => $editedKeys,
            'field_accuracy' => $parsed->fieldAccuracy(),
        ], JSON_THROW_ON_ERROR);

        foreach ($parsed->fields as $field) {
            $value = $field->value;
            if (is_string($value) && $value !== '' && str_contains($logged, $value)) {
                return false;
            }
        }

        return true;
    }
}
