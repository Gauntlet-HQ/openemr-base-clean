<?php

/**
 * Isolated W2 end-to-end companion: fixture doc -> stubbed VLM extraction -> retrieval -> cited, verified answer, no DB.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\E2e;

use OpenEMR\Modules\ClinicalCopilot\Chat\ChatAgent;
use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallOutcome;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallRequest;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\Ingest\DocType;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Ingest\SourceType;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineCorpus;
use OpenEMR\Modules\ClinicalCopilot\Rag\SparseRetriever;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Verify\CheckId;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat\ChatTestFactory;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat\QueuedChatLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat\StubToolExecutor;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce\StubLlmClient;
use PHPUnit\Framework\TestCase;

/**
 * The DB-free half of `tests/Db/E2e/W2EndToEndTest.php`, so the blocking
 * isolated CI gate carries end-to-end coverage of every segment that does
 * not inherently need a database:
 *
 *   fixture PDF bytes (`tests/fixtures/lab-report-a1c.pdf`)
 *     -> REAL {@see ExtractionClient} over a canned VLM response (real
 *        schema gate + parse, citation page/quote/bbox included)
 *     -> an in-memory chart boundary standing in for the ChartWriter commit:
 *        the extracted value becomes the committed Fact
 *        ({@see FactTestFactory::a1cTrendPoint()}), exactly what the
 *        capability layer re-derives from the committed `procedure_result`
 *        row in the Db version
 *     -> REAL {@see SparseRetriever} over the real committed corpus
 *     -> the REAL {@see \OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop} /
 *        {@see ChatAgent} with the queued chat-LLM stub and the stub tool
 *        executor returning that committed fact
 *     -> a `passed`, cited answer with the deterministic V1-V6 critic
 *        ({@see \OpenEMR\Modules\ClinicalCopilot\Verify\Verifier}) enforced.
 *
 * The two segments deliberately NOT covered here -- the `documents` store
 * and the `procedure_*` commit + real-capability read-back -- are inherently
 * DB-bound; the Db test remains the full-fidelity version of this walk.
 *
 * Failure modes guarded: a schema gate that rejects (or silently mangles)
 * a valid VLM lab payload; an extraction whose value/citation does not
 * survive parsing; sparse retrieval over the shipped corpus returning
 * nothing for the module's own A1c topic query; and an answer path that
 * either loses the extraction-derived fact's citation or renders without
 * all six verifier verdicts recorded.
 */
final class W2EndToEndIsolatedTest extends TestCase
{
    private const LOINC_A1C = '4548-4';

    protected function setUp(): void
    {
        // The answer-path assertions exercise the verifier GATE, so pin it
        // enforced regardless of the runtime default (enforced by default since FINAL_REVIEW) --
        // see OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPolicy.
        putenv('CLINICAL_COPILOT_VERIFY_ENFORCE=1');
    }

    protected function tearDown(): void
    {
        putenv('CLINICAL_COPILOT_VERIFY_ENFORCE');
    }

    public function testFixtureLabDocumentFlowsToACitedVerifiedAnswerWithoutADatabase(): void
    {
        // ---- Stage 1: fixture upload -> stubbed VLM -> REAL schema gate. ----
        $pdfBytes = file_get_contents(__DIR__ . '/../../fixtures/lab-report-a1c.pdf');
        self::assertIsString($pdfBytes);
        self::assertNotSame('', $pdfBytes);

        $visionLlm = StubLlmClient::up(new LlmResponse(self::cannedLabExtractionJson(), 'stub-vision-model', 850, 120, 40));
        $outcome = (new ExtractionClient($visionLlm, 'stub-vision-model'))
            ->extract(DocType::LabPdf, $pdfBytes, 'application/pdf', 'upload');

        self::assertSame(1, count($visionLlm->calls()));
        $sentPart = $visionLlm->lastCall()?->parts[0] ?? null;
        self::assertNotNull($sentPart, 'the vision call must attach the document as an inline part');
        self::assertSame(base64_encode($pdfBytes), $sentPart->base64Data, 'the vision call must carry the fixture document bytes');

        self::assertCount(1, $outcome->extraction->fields);
        $extracted = $outcome->extraction->fields[0];
        self::assertSame(self::LOINC_A1C, $extracted->fieldKey);
        self::assertSame('7.8', $extracted->value);
        self::assertSame('%', $extracted->unit);
        self::assertNotNull($extracted->citation, 'a valued lab field must carry its click-to-source citation');
        self::assertSame(1, $extracted->citation->pageOrSection);
        self::assertSame('Synthetic Patient', $outcome->extraction->patientName);
        self::assertSame('2026-01-10', $outcome->extraction->collectionDate, 'the printed specimen date must survive the parse (W5) — it prefills the review draw-date field');

        // ---- Stage 2: the in-memory chart boundary -- the extracted value
        // becomes the committed, citable Fact (the Db test's ChartWriter
        // commit + ControlProxy read-back, collapsed to its data contract). ----
        $committedFact = FactTestFactory::a1cTrendPoint(
            ChatTestFactory::PINNED_PID,
            4201,
            (string)$extracted->value,
            '2026-01-10',
        );
        self::assertNotNull($committedFact->value?->parsed);
        self::assertEqualsWithDelta(7.8, $committedFact->value->parsed, 0.0001);

        // ---- Stage 3: REAL sparse retrieval over the real shipped corpus. ----
        $snippets = (new SparseRetriever(GuidelineCorpus::createDefault()))
            ->retrieve('A1c glycemic target and monitoring', ['a1c'], 4);
        self::assertNotEmpty($snippets, 'sparse retrieval over the committed corpus is the floor that always works');
        self::assertSame(SourceType::Guideline, $snippets[0]->citation->sourceType, 'guideline evidence stays structurally separate from patient facts');
        self::assertNotSame('', $snippets[0]->citation->quoteOrValue);

        // ---- Stage 4: the question through the real answer path, LLM stubbed. ----
        $chatLlm = QueuedChatLlmClient::up([
            ChatTestFactory::toolCallResponse('get_control_trend', ['analyte' => 'a1c', 'window_months' => 24]),
            ChatTestFactory::finalAnswerResponse([
                [
                    'text' => 'The most recent hemoglobin A1c on file is 7.8 %.',
                    'claim_type' => 'lab_value',
                    'citation_ids' => [$committedFact->factId],
                    'numeric_values' => [7.8],
                    'flags' => [],
                    'order' => 0,
                    'emphasis' => null,
                ],
            ]),
        ]);

        $tools = new StubToolExecutor();
        $tools->enqueue('get_control_trend', ToolCallOutcome::ok('get_control_trend', [$committedFact]));

        $agent = ChatTestFactory::chatAgent($chatLlm, $tools);
        $answer = $agent->answer(ChatTestFactory::PINNED_PID, 'corr-w2-e2e-isolated', [], null, [], 'What is her most recent A1c?');

        // A grounded, cited answer whose citation resolves to the fact derived
        // from the fixture extraction.
        self::assertSame(VerifyStatus::Passed, $answer->verifyStatus, 'the turn must pass the enforced verifier gate');
        self::assertFalse($answer->frozen);
        self::assertSame(2, $chatLlm->callCount(), 'one tool-deciding round plus the final answering round');
        self::assertNotNull($answer->claims);
        self::assertCount(1, $answer->claims);
        self::assertContains($committedFact->factId, $answer->claims[0]->citationIds);
        $accumulatedIds = array_map(static fn ($f) => $f->factId, $answer->accumulatedFacts);
        self::assertContains($committedFact->factId, $accumulatedIds, 'the cited fact must be in the session fact set the verifier resolved against');

        // The critic/verifier ran: its recorded verdict ledger carries all six
        // V1-V6 checks, each passed, in one attempt.
        self::assertSame(1, $answer->attempts);
        $verdictsByCheck = [];
        foreach ($answer->verdicts as $verdict) {
            $verdictsByCheck[$verdict->checkId->value] = $verdict;
        }
        self::assertEqualsCanonicalizing(
            array_map(static fn (CheckId $c): string => $c->value, CheckId::cases()),
            array_keys($verdictsByCheck),
            'every deterministic check must have recorded a verdict',
        );
        foreach ($verdictsByCheck as $checkValue => $verdict) {
            self::assertTrue($verdict->passed, "check {$checkValue} must pass on this grounded, cited answer");
        }
    }

    /**
     * The canned VLM output for the fixture report -- identical in shape to
     * the Db test's payload: valued lab fields carry a positive-int `page`,
     * a `quote`, and a bbox, and the header identity matches the fixture.
     */
    private static function cannedLabExtractionJson(): string
    {
        return (string)json_encode([
            'patient_name' => 'Synthetic Patient',
            'patient_dob' => '1970-01-01',
            'collection_date' => '2026-01-10',
            'fields' => [
                [
                    'field_key' => self::LOINC_A1C,
                    'value' => '7.8',
                    'unit' => '%',
                    'reference_range' => '4.0-5.6',
                    'abnormal_flag' => 'H',
                    'confidence' => 0.97,
                    'page' => 1,
                    'quote' => 'Hemoglobin A1c (4548-4)   7.8   %   H   4.0-5.6',
                    'bbox' => [50, 645, 560, 665],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
