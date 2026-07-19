<?php

/**
 * DB-backed W2 end-to-end eval: fixture lab document -> stubbed VLM extraction -> ChartWriter commit -> retrieval -> cited, verified chat answer.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\E2e;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\DbLabTurnaroundConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Capability\ControlProxy;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedResponse;
use OpenEMR\Modules\ClinicalCopilot\Capability\OverdueTests;
use OpenEMR\Modules\ClinicalCopilot\Capability\PendingResults;
use OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend;
use OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatAgent;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatPromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallRequest;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolExecutor;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Ingest\AttachAndExtract;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ChartWriter;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionReview;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionStatus;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionStore;
use OpenEMR\Modules\ClinicalCopilot\Ingest\LabIdentityStatus;
use OpenEMR\Modules\ClinicalCopilot\Ingest\SourceType;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineCorpus;
use OpenEMR\Modules\ClinicalCopilot\Rag\SparseRetriever;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\NullAlertSink;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\PatientIdentifierLookup;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptContext;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor;
use OpenEMR\Modules\ClinicalCopilot\Verify\CheckId;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use OpenEMR\Services\PatientService;
use OpenEMR\Services\PrescriptionService;
use PHPUnit\Framework\TestCase;

/**
 * The W2 remediation plan's single walking-skeleton eval: ONE test that
 * drives the whole Week 2 pipeline over a real database with every model
 * call stubbed (build-notes.md: no live LLM calls anywhere in tests):
 *
 *   fixture PDF (`tests/fixtures/lab-report-a1c.pdf`)
 *     -> {@see AttachAndExtract::ingestLab()} with a canned VLM response
 *        flowing through the REAL {@see ExtractionClient} schema gate
 *     -> human verify & lock ({@see ExtractionReview::lock()} ->
 *        {@see ChartWriter::commitLabResults()}, idempotent on re-lock)
 *     -> real {@see SparseRetriever} over the committed guideline corpus
 *     -> a chat question through the REAL {@see AgentLoop}/{@see ChatAgent}
 *        with a queued chat-LLM stub and the REAL {@see ToolExecutor} reading
 *        the rows the commit just wrote
 *     -> a `passed` answer whose citations resolve to those committed rows,
 *        with the deterministic V1-V6 critic ({@see Verifier}) enforced.
 *
 * Failure modes guarded, per stage: a source upload that does not land as a
 * retrievable `documents` row; a lock that does not commit the extracted
 * fact down the procedure chain (or that duplicates rows on re-lock); a
 * committed result the capability layer cannot see again; a chat answer
 * whose citation does not resolve back to the committed `procedure_result`
 * row; and a turn that renders without the verifier's six verdicts on
 * record.
 *
 * Fixture note: the canned extraction's `field_key` is the LOINC code
 * (`4548-4`) exactly as the fixture report prints it. That is load-bearing:
 * {@see ChartWriter::commitLabResults()} writes `procedure_result.result_code
 * = field_key`, and {@see LabSliceReader} reads results by LOINC
 * `result_code` -- a report whose printed test identifier is the LOINC code
 * is what closes the loop from ingest to the chat capabilities (there is no
 * test-name -> LOINC mapping layer today).
 *
 * Like every eval in this suite, all DB writes roll back on teardown. The
 * one deliberate residue is the stored source file on disk (the `documents`
 * ROW rolls back; `Document::createDocument()`'s file write is not
 * transactional) -- same class of residue as the E2E suite's uploads,
 * harmless in the dev container.
 */
final class W2EndToEndTest extends TestCase
{
    private const LOINC_A1C = '4548-4';
    private const USER_ID = 1;
    private const PROVIDER_ID = 1;
    private const CORRELATION_ID = 'corr-w2-e2e-test';
    private const COLLECTION_DATE = '2026-01-10';
    /** IngestController::DEFAULT_DOCUMENT_CATEGORY_ID -- the root category. */
    private const DOCUMENT_CATEGORY_ID = 1;

    private int $pid;

    protected function setUp(): void
    {
        // The answer-path assertions exercise the verifier GATE, so pin it
        // enforced regardless of the runtime default (enforced by default since FINAL_REVIEW) --
        // see OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPolicy.
        putenv('CLINICAL_COPILOT_VERIFY_ENFORCE=1');
        QueryUtils::startTransaction();
        $this->pid = self::insertSyntheticPatient();
    }

    protected function tearDown(): void
    {
        putenv('CLINICAL_COPILOT_VERIFY_ENFORCE');
        QueryUtils::rollbackTransaction();
    }

    public function testFixtureLabDocumentFlowsToACitedVerifiedAnswer(): void
    {
        $pdfBytes = self::fixturePdfBytes();

        // ---- Stage 1: upload + stubbed VLM extraction (real schema gate). ----
        $visionLlm = StubVisionLlmClient::up(new LlmResponse(self::cannedLabExtractionJson(), 'stub-vision-model', 850, 120, 40));
        $store = new ExtractionStore();
        $chartWriter = new ChartWriter(new PatientService());
        $tracer = new TraceRecorder();
        $ingest = new AttachAndExtract(
            new ExtractionClient($visionLlm, 'stub-vision-model'),
            $store,
            $chartWriter,
            self::DOCUMENT_CATEGORY_ID,
            $tracer,
        );

        $result = $ingest->ingestLab($this->pid, $pdfBytes, 'lab-report-a1c.pdf', 'application/pdf', self::CORRELATION_ID, self::USER_ID);

        self::assertTrue($result->visionUsed, 'the stubbed vision call must count as a real extraction');
        self::assertFalse($result->schemaRejected, 'the canned payload must satisfy the strict lab extraction schema');
        self::assertSame(1, $visionLlm->callCount());
        $sentPart = $visionLlm->lastCall()?->parts[0] ?? null;
        self::assertNotNull($sentPart);
        self::assertSame(base64_encode($pdfBytes), $sentPart->base64Data, 'the vision call must carry the fixture document bytes');

        $header = $store->findHeader($result->extractionId);
        self::assertNotNull($header);
        self::assertSame(ExtractionStatus::Draft, $header->status, 'nothing reaches the chart at upload -- the draft awaits human review');
        self::assertSame(LabIdentityStatus::Match, $header->identityStatus, 'the fixture header identity must match the chart (PHI-mixing guard)');

        // Assertion (1): the source document is stored and its content retrievable.
        $documentId = $header->sourceDocumentId;
        self::assertNotNull($documentId, 'the uploaded source must be stored as a real documents row');
        $docRow = QueryUtils::querySingleRow(
            'SELECT `foreign_id`, `foreign_reference_id`, `foreign_reference_table`, `name`, `mimetype` FROM `documents` WHERE `id` = ?',
            [$documentId],
        );
        self::assertIsArray($docRow);
        self::assertSame($this->pid, (int)$docRow['foreign_id'], 'the stored document must belong to the target chart');
        self::assertSame($result->extractionId, (int)$docRow['foreign_reference_id'], 'provenance: the document links back to the staging extraction');
        self::assertSame('mod_copilot_extraction', (string)$docRow['foreign_reference_table']);
        self::assertSame('application/pdf', (string)$docRow['mimetype']);
        $storedDoc = new \Document($documentId);
        self::assertSame($pdfBytes, $storedDoc->get_data(), 'the stored source content must read back byte-identical');

        // ---- Stage 2: verify & lock -- ChartWriter commit, idempotent re-lock. ----
        $review = new ExtractionReview($store, $chartWriter, $tracer);
        $review->lock($result->extractionId, self::USER_ID, self::PROVIDER_ID, self::COLLECTION_DATE);

        $locked = $store->findHeader($result->extractionId);
        self::assertNotNull($locked);
        self::assertSame(ExtractionStatus::Locked, $locked->status);
        self::assertNotNull($locked->fieldAccuracy);
        self::assertEqualsWithDelta(1.0, $locked->fieldAccuracy, 0.0001, 'the model value was accepted unchanged -- accuracy 1.0');

        // Assertion (2): the extracted fact is committed via ChartWriter with lineage.
        $fields = $store->listFields($result->extractionId);
        self::assertCount(1, $fields);
        $field = $fields[0]->field;
        self::assertTrue($field->isCommitted(), 'lock must commit the valued field to the chart');
        self::assertSame('procedure_result', $field->committedCoreTable);
        $committedResultId = $field->committedCorePk;
        self::assertIsInt($committedResultId);

        $resultRow = QueryUtils::querySingleRow(
            'SELECT pr.`result_code`, pr.`result`, pr.`units`, pr.`document_id`, po.`patient_id`
             FROM `procedure_result` pr
             INNER JOIN `procedure_report` prep ON prep.`procedure_report_id` = pr.`procedure_report_id`
             INNER JOIN `procedure_order` po ON po.`procedure_order_id` = prep.`procedure_order_id`
             WHERE pr.`procedure_result_id` = ?',
            [$committedResultId],
        );
        self::assertIsArray($resultRow);
        self::assertSame(self::LOINC_A1C, (string)$resultRow['result_code']);
        self::assertSame('7.8', (string)$resultRow['result']);
        self::assertSame('%', (string)$resultRow['units']);
        self::assertSame($this->pid, (int)$resultRow['patient_id'], 'the committed result must land on the target chart');
        self::assertSame($documentId, (int)$resultRow['document_id'], 'each committed value binds back to the stored source PDF');

        // Idempotent re-lock: a second lock of the same extraction is a no-op.
        $resultCountBefore = self::countProcedureResults($this->pid);
        $review->lock($result->extractionId, self::USER_ID, self::PROVIDER_ID, self::COLLECTION_DATE);
        self::assertSame($resultCountBefore, self::countProcedureResults($this->pid), 're-locking must never duplicate chart rows');

        // The ingest + commit left a reconstructable trace (I12). This drive is
        // the STANDALONE path (no parent span id passed to ingestLab()/lock()),
        // so `ingest` and `chart_commit` must stay ROOT spans of their own
        // correlation tree — only the agent path, which passes its worker span
        // id down, attaches them under a supervisor tree (W8; see
        // Tests\Isolated\Agent\SupervisorTraceTreeTest for that shape).
        $spanRows = QueryUtils::fetchRecords(
            'SELECT `kind`, `span_id`, `parent_span_id` FROM `mod_copilot_trace` WHERE `correlation_id` = ?',
            [self::CORRELATION_ID],
        );
        $spansByKind = [];
        foreach ($spanRows as $row) {
            $spansByKind[(string)$row['kind']] = $row;
        }
        self::assertArrayHasKey('vision_extract', $spansByKind, 'the stubbed VLM call must still record its child span');
        self::assertArrayHasKey('ingest', $spansByKind);
        self::assertArrayHasKey('chart_commit', $spansByKind, 'the lock must record the chart-commit span');
        self::assertNull($spansByKind['ingest']['parent_span_id'], 'standalone ingest stays a root span');
        self::assertNull($spansByKind['chart_commit']['parent_span_id'], 'standalone chart_commit stays a root span');
        self::assertSame(
            (string)$spansByKind['ingest']['span_id'],
            (string)$spansByKind['vision_extract']['parent_span_id'],
            'the VLM child span must parent to the ingest span',
        );

        // ---- Stage 3: retrieval -- the REAL SparseRetriever over the real corpus. ----
        $snippets = (new SparseRetriever(GuidelineCorpus::createDefault()))
            ->retrieve('A1c glycemic target and monitoring', ['a1c'], 4);
        self::assertNotEmpty($snippets, 'sparse retrieval over the committed corpus is the floor that always works');
        self::assertSame(SourceType::Guideline, $snippets[0]->citation->sourceType, 'guideline evidence stays structurally separate from patient facts');
        self::assertNotSame('', $snippets[0]->citation->quoteOrValue);

        // ---- Stage 4: the committed row is visible to the real capability layer. ----
        // Probe the tool once to learn the content-addressed fact id the agent
        // loop will deterministically re-derive from the same committed rows.
        $probe = $this->buildToolExecutor()->execute(new ToolCallRequest('get_control_trend', ['analyte' => 'a1c', 'window_months' => 24]));
        self::assertTrue($probe->ok, (string)$probe->errorMessage);
        $committedFact = self::findFactCiting($probe->facts, $committedResultId);
        self::assertInstanceOf(Fact::class, $committedFact, 'the committed procedure_result must resurface as a citable fact');
        self::assertSame($this->pid, $committedFact->pid);
        self::assertNotNull($committedFact->value);
        self::assertNotNull($committedFact->value->parsed, 'the committed A1c must parse to a groundable numeric');
        self::assertEqualsWithDelta(7.8, $committedFact->value->parsed, 0.0001);

        // ---- Stage 5: a question through the real answer path, LLM stubbed. ----
        $chatLlm = QueuedChatLlmClient::up([
            ChatLlmResponse::toolCalls([new ToolCallRequest('get_control_trend', ['analyte' => 'a1c', 'window_months' => 24])], 'stub-chat-model', 100, 20, 50),
            ChatLlmResponse::finalAnswer((string)json_encode([
                [
                    'text' => 'The most recent hemoglobin A1c on file is 7.8 %.',
                    'claim_type' => 'lab_value',
                    'citation_ids' => [$committedFact->factId],
                    'numeric_values' => [7.8],
                    'flags' => [],
                    'order' => 0,
                    'emphasis' => null,
                ],
            ], JSON_THROW_ON_ERROR), 'stub-chat-model', 100, 40, 60),
        ]);

        $identifiers = (new PatientIdentifierLookup())->forPid($this->pid);
        self::assertNotNull($identifiers);
        $agentLoop = new AgentLoop(
            $chatLlm,
            $this->buildToolExecutor(),
            new ChatPromptAssembler(),
            new Redactor(),
            'chat:w2-e2e-test',
            $identifiers,
            new PromptContext('endo-previsit-chat-v1', 'chat-v1', 'stub-chat-model'),
            null,
            toolsEnabled: true,
        );
        $agent = new ChatAgent($agentLoop, new Verifier());

        $answer = $agent->answer($this->pid, self::CORRELATION_ID, [], null, [], 'What is her most recent A1c?');

        // Assertion (3): a grounded, cited answer whose citations resolve to the
        // committed fact (and through it to the committed procedure_result row).
        self::assertSame(VerifyStatus::Passed, $answer->verifyStatus, 'the turn must pass the enforced verifier gate');
        self::assertFalse($answer->frozen);
        self::assertSame(2, $chatLlm->callCount(), 'one tool-deciding round plus the final answering round');
        self::assertNotNull($answer->claims);
        self::assertCount(1, $answer->claims);
        self::assertContains($committedFact->factId, $answer->claims[0]->citationIds);
        $answerFact = self::findFactCiting($answer->accumulatedFacts, $committedResultId);
        self::assertInstanceOf(Fact::class, $answerFact, 'the cited fact in the session fact set must trace to the committed row');
        self::assertSame($committedFact->factId, $answerFact->factId);

        // Assertion (4): the critic/verifier ran -- its recorded verdict ledger
        // (the same list ChatController persists onto mod_copilot_chat_turn)
        // carries all six V1-V6 checks, each passed, in one attempt.
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

    private function buildToolExecutor(): ToolExecutor
    {
        $labContractConfigProvider = new DbLabContractConfigProvider();
        $labSliceReader = new LabSliceReader($labContractConfigProvider);
        $turnaroundConfigProvider = new DbLabTurnaroundConfigProvider();

        return new ToolExecutor(
            $this->pid,
            self::CORRELATION_ID,
            new ControlProxy($labSliceReader),
            new MedResponse(new PrescriptionService(), $labSliceReader),
            new VitalsTrend(),
            new OverdueTests($labSliceReader, $labContractConfigProvider, ServiceContainer::getClock()),
            new PendingResults($labSliceReader, $turnaroundConfigProvider),
            new NullAlertSink(),
        );
    }

    /**
     * @param list<Fact> $facts
     */
    private static function findFactCiting(array $facts, int $procedureResultId): ?Fact
    {
        foreach ($facts as $fact) {
            foreach ($fact->citations as $citation) {
                if ($citation->table === 'procedure_result' && $citation->pk === $procedureResultId) {
                    return $fact;
                }
            }
        }

        return null;
    }

    private static function fixturePdfBytes(): string
    {
        $path = __DIR__ . '/../../fixtures/lab-report-a1c.pdf';
        $bytes = file_get_contents($path);
        self::assertIsString($bytes, 'the fixture lab report must be readable');
        self::assertNotSame('', $bytes);

        return $bytes;
    }

    /**
     * The canned VLM output for the fixture report -- a payload that satisfies
     * the strict lab extraction schema (valued fields carry a positive-int
     * `page`, a `quote`, and a bbox; the header identity matches the chart).
     */
    private static function cannedLabExtractionJson(): string
    {
        return (string)json_encode([
            'patient_name' => 'Synthetic Patient',
            'patient_dob' => '1970-01-01',
            'collection_date' => self::COLLECTION_DATE,
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

    private static function countProcedureResults(int $pid): int
    {
        return (int)QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c
             FROM `procedure_result` pr
             INNER JOIN `procedure_report` prep ON prep.`procedure_report_id` = pr.`procedure_report_id`
             INNER JOIN `procedure_order` po ON po.`procedure_order_id` = prep.`procedure_order_id`
             WHERE po.`patient_id` = ?',
            'c',
            [$pid],
        );
    }

    private static function insertSyntheticPatient(): int
    {
        $pid = QueryUtils::fetchSingleValue('SELECT MAX(`pid`) + 1 AS pid FROM `patient_data`', 'pid');
        $pid = $pid !== null ? (int)$pid : 1;

        $uuid = (new UuidRegistry(['table_name' => 'patient_data']))->createUuid();
        QueryUtils::sqlInsert(
            'INSERT INTO `patient_data`
                (`uuid`, `pid`, `pubpid`, `fname`, `lname`, `DOB`, `sex`, `date`, `regdate`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), \'clinical_copilot_w2_e2e_test\')',
            [$uuid, $pid, 'CCP-E2E-' . $pid, 'Synthetic', 'Patient', '1970-01-01', 'Female'],
        );

        return $pid;
    }
}
