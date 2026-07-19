<?php

/**
 * Benchmark workload registry.
 *
 * Each workload is a name => setup closure. The setup runs ONCE (its cost is
 * excluded from timing) and returns a `callable(): void` that performs exactly
 * one unit of real work on every call — the thing the harness times. Every
 * workload here is one of the module's real hot code paths, exercised through
 * its production entry point over committed fixture data, with no DB, no
 * network, and no OpenEMR core (see ops/load/bench/README.md for the map).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Fact\CanonicalSerializer;
use OpenEMR\Modules\ClinicalCopilot\Fact\Digest;
use OpenEMR\Modules\ClinicalCopilot\Ingest\DocType;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionSchema;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineCorpus;
use OpenEMR\Modules\ClinicalCopilot\Rag\HybridRetriever;
use OpenEMR\Modules\ClinicalCopilot\Rag\SparseRetriever;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce\ReduceTestFactory;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationContext;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPath;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Verify\VerifyTestFactory;

/** A stub LLM that returns a fixed structured payload verbatim (no network). */
final class BenchStubLlm implements LlmClientInterface
{
    public function __construct(private readonly string $raw)
    {
    }

    public function generateStructured(PromptRequest $req): LlmResponse
    {
        return new LlmResponse($this->raw, 'bench-stub', 0, 0, 0);
    }
}

/**
 * @return array<string, callable(): (callable(): void)>
 */
function bench_workloads(string $moduleRoot): array
{
    return [
        // --- FR-7: hybrid RAG over the committed guideline corpus (sparse
        // degraded path — the always-on floor, no embeddings/network). ---
        'guideline_retrieval_sparse' => static function (): callable {
            $retriever = new SparseRetriever(GuidelineCorpus::createDefault());
            $queries = [
                ['what is the A1c goal for this patient', ['a1c']],
                ['lipid statin therapy indication', ['lipids']],
                ['annual kidney screening albumin creatinine', ['acr']],
                ['blood pressure target in diabetes', ['blood_pressure']],
                ['metformin first line dose response', ['metformin']],
                ['hypoglycemia risk management', ['hypoglycemia']],
            ];
            $i = 0;
            return static function () use ($retriever, $queries, &$i): void {
                [$q, $tags] = $queries[$i++ % count($queries)];
                $retriever->retrieve($q, $tags, 3);
            };
        },

        'guideline_retrieval_hybrid' => static function (): callable {
            $retriever = HybridRetriever::createDefault();
            $queries = [
                ['what is the A1c goal for this patient', ['a1c']],
                ['lipid statin therapy indication', ['lipids']],
                ['annual kidney screening albumin creatinine', ['acr']],
                ['blood pressure target in diabetes', ['blood_pressure']],
            ];
            $i = 0;
            return static function () use ($retriever, $queries, &$i): void {
                [$q, $tags] = $queries[$i++ % count($queries)];
                $retriever->retrieve($q, $tags, 3);
            };
        },

        // --- FR-3: strict-schema extraction validate + parse (the CPU cost
        // of turning raw VLM output into typed, cited fields). ---
        'extraction_validate_parse' => static function () use ($moduleRoot): callable {
            $cases = json_decode((string)file_get_contents($moduleRoot . '/ops/eval/cases.json'), true);
            $accepted = [];
            foreach (is_array($cases) ? $cases : [] as $c) {
                if (!is_array($c) || !in_array(($c['category'] ?? ''), ['extraction', 'missing_data'], true)) {
                    continue;
                }
                if (($c['expect']['accept'] ?? true) !== true) {
                    continue;
                }
                $accepted[] = [DocType::from((string)$c['doc_type']), is_array($c['input'] ?? null) ? $c['input'] : []];
            }
            if ($accepted === []) {
                throw new RuntimeException('no accepted extraction cases in cases.json');
            }
            $i = 0;
            return static function () use ($accepted, &$i): void {
                [$docType, $payload] = $accepted[$i++ % count($accepted)];
                $errors = ExtractionSchema::validate($docType, $payload);
                if ($errors === []) {
                    ExtractionSchema::parse($docType, $payload, 'bench');
                }
            };
        },

        // --- FR-1/FR-2: the full ExtractionClient.extract() path with a stub
        // VLM (json_decode + validate + parse), one document per call. ---
        'extraction_client_full' => static function (): callable {
            $raw = json_encode([
                'fields' => [
                    ['field_key' => 'glucose_fasting', 'value' => '142', 'unit' => 'mg/dL', 'page' => 1, 'quote' => 'Glucose, Fasting 142 mg/dL'],
                    ['field_key' => 'hemoglobin_a1c', 'value' => '8.1', 'unit' => '%', 'page' => 1, 'quote' => 'Hemoglobin A1c 8.1 %'],
                    ['field_key' => 'ldl_cholesterol', 'value' => '118', 'unit' => 'mg/dL', 'page' => 1, 'quote' => 'LDL Cholesterol 118 mg/dL'],
                ],
            ], JSON_THROW_ON_ERROR);
            $client = new ExtractionClient(new BenchStubLlm($raw), 'bench-stub');
            return static function () use ($client): void {
                $client->extract(DocType::LabPdf, 'PDFBYTES', 'application/pdf', 'bench');
            };
        },

        // --- V1-V6 verification over a fixed fact set + claims (the read-path
        // discipline: every claim cited, patient-scoped, numerically grounded). ---
        'verify_chat' => static function (): callable {
            $a = VerifyTestFactory::a1cEarly();
            $b = VerifyTestFactory::a1cLater();
            $factSet = VerifyTestFactory::sessionFactSet([$a, $b]);
            $ctx = new VerificationContext($factSet, VerificationPath::Chat);
            $claimsJson = VerifyTestFactory::claimsJson([
                VerifyTestFactory::claim('A1c rose to 7.6%.', 'lab_value', [$b->factId], [7.6]),
                VerifyTestFactory::claim('Earlier A1c was 7.2%.', 'lab_value', [$a->factId], [7.2]),
            ]);
            $verifier = new Verifier();
            return static function () use ($verifier, $claimsJson, $ctx): void {
                $verifier->verify($claimsJson, $ctx);
            };
        },

        'verify_synthesis' => static function (): callable {
            $a = VerifyTestFactory::a1cEarly();
            $b = VerifyTestFactory::a1cLater();
            $factSet = VerifyTestFactory::sessionFactSet([$a, $b]);
            $ctx = new VerificationContext($factSet, VerificationPath::Synthesis);
            $claimsJson = VerifyTestFactory::claimsJson([
                VerifyTestFactory::claim('A1c rose to 7.6%.', 'lab_value', [$b->factId], [7.6]),
                VerifyTestFactory::claim('Earlier A1c was 7.2%.', 'lab_value', [$a->factId], [7.2]),
            ]);
            $verifier = new Verifier();
            return static function () use ($verifier, $claimsJson, $ctx): void {
                $verifier->verify($claimsJson, $ctx);
            };
        },

        // --- Content-address canonicalization + digest (the idempotency /
        // cache-key seam under every warm-vs-cold decision). ---
        'canonical_serialize_digest' => static function (): callable {
            $facts = [
                FactTestFactory::a1cTrendPoint(1, 1, '7.2', '2024-07-07'),
                FactTestFactory::a1cTrendPoint(1, 2, '7.6', '2024-10-07'),
                FactTestFactory::a1cTrendPoint(1, 3, '8.1', '2025-01-07'),
                FactTestFactory::a1cTrendPoint(1, 4, '8.4', '2025-04-07'),
                FactTestFactory::medEvent(1, 2),
                FactTestFactory::censoredResult(3, 12),
                FactTestFactory::unitlessExclusion(3, 13),
            ];
            return static function () use ($facts): void {
                CanonicalSerializer::serializeFacts($facts);
                Digest::compute(
                    $facts,
                    ['control_proxy' => 'v1', 'med_response' => 'v1'],
                    ['a1c' => 'v1'],
                    'cs-v1',
                    'previsit',
                    'reduce-v1',
                );
            };
        },

        // --- Reduce prompt assembly (the bulk of the synthesis CPU work off
        // the LLM call: window + canonicalize + render the full prompt). ---
        'prompt_assemble_reduce' => static function (): callable {
            $facts = ReduceTestFactory::twoFactSet(7);
            $context = ReduceTestFactory::context();
            $identifiers = ReduceTestFactory::patientIdentifiers();
            $assembler = new PromptAssembler();
            return static function () use ($assembler, $facts, $context, $identifiers): void {
                $assembler->assemble($facts, $context, $identifiers);
            };
        },
    ];
}
