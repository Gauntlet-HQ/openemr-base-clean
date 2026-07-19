<?php

/**
 * The supervisor's handoff graph records the spec's full 4-level span tree under one correlation id.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Agent;

use OpenEMR\Modules\ClinicalCopilot\Agent\AgentRequest;
use OpenEMR\Modules\ClinicalCopilot\Agent\CriticWorker;
use OpenEMR\Modules\ClinicalCopilot\Agent\EvidenceRetrieverWorker;
use OpenEMR\Modules\ClinicalCopilot\Agent\IntakeExtractorWorker;
use OpenEMR\Modules\ClinicalCopilot\Agent\Supervisor;
use OpenEMR\Modules\ClinicalCopilot\Ingest\AttachAndExtract;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ChartWriter;
use OpenEMR\Modules\ClinicalCopilot\Ingest\DocType;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionStore;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineCorpus;
use OpenEMR\Modules\ClinicalCopilot\Rag\SparseRetriever;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce\StubLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * Failure modes guarded: the dashboard waterfall (W8) reconstructing a FLAT or
 * DISCONNECTED handoff graph — a `retrieve`/`vision_extract` sub-call that
 * never records its own span (the RAG/VLM cost hides inside the worker span),
 * a child span whose `parent_span_id` does not point at its real invoker (the
 * tree lies about who called what), the VLM's token counts double-counted on
 * both the worker and its `vision_extract` child (inflating the token/cost
 * sums MetricsService reads off `mod_copilot_trace`), an agent-driven ingest
 * recording ROOT spans disconnected from the supervisor tree, or the
 * standalone upload path regressing from root spans (its correlation id is its
 * own tree). The spec's target shape, all under ONE correlation id:
 *
 *   supervisor -> worker -> {retrieve | vision_extract}  (agent gather paths)
 *   supervisor -> worker -> preview/ingest -> vision_extract  (agent-driven ingest)
 *
 * `chart_commit` joins the same tree via {@see \OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionReview::lock()}'s
 * optional parent span id — DB-backed, so its parentage is pinned by
 * {@see \OpenEMR\Modules\ClinicalCopilot\Tests\Db\E2e\W2EndToEndTest}, not here.
 */
final class SupervisorTraceTreeTest extends TestCase
{
    private const PID = 42;

    private function supervisor(StubLlmClient $llm, RecordingTraceRecorder $tracer): Supervisor
    {
        return new Supervisor(
            new IntakeExtractorWorker(new ExtractionClient($llm, 'gemini-2.5-pro'), $tracer),
            new EvidenceRetrieverWorker(
                new SparseRetriever(new GuidelineCorpus(dirname(__DIR__, 3) . '/src/Rag/corpus')),
                $tracer,
            ),
            new CriticWorker(new Verifier(), $tracer),
            $tracer,
        );
    }

    private function labResponse(): LlmResponse
    {
        $json = json_encode(['fields' => [
            ['field_key' => 'Hemoglobin A1c', 'value' => '7.8', 'unit' => '%', 'page' => 1, 'quote' => 'A1c 7.8 %'],
        ]], JSON_THROW_ON_ERROR);

        return new LlmResponse($json, 'gemini-2.5-pro', 900, 30, 500);
    }

    private function intakeResponse(): LlmResponse
    {
        $json = json_encode(['fields' => [
            ['field_key' => 'first_name', 'value' => 'Jane'],
            ['field_key' => 'last_name', 'value' => 'Doe'],
        ]], JSON_THROW_ON_ERROR);

        return new LlmResponse($json, 'gemini-2.5-pro', 300, 20, 120);
    }

    /**
     * An {@see AttachAndExtract} wired for the DB-free `previewIntake` path.
     * `previewIntake` never touches the store or the chart writer, so the
     * ChartWriter (whose real constructor needs a DB-backed PatientService) is
     * instantiated without its constructor — its properties are never read.
     */
    private function attachAndExtract(StubLlmClient $llm, RecordingTraceRecorder $tracer): AttachAndExtract
    {
        /** @var ChartWriter $chartWriter */
        $chartWriter = (new \ReflectionClass(ChartWriter::class))->newInstanceWithoutConstructor();

        return new AttachAndExtract(
            new ExtractionClient($llm, 'gemini-2.5-pro'),
            new ExtractionStore(),
            $chartWriter,
            1,
            $tracer,
        );
    }

    private static function spanById(RecordingTraceRecorder $tracer, ?string $spanId): ?TraceSpan
    {
        foreach ($tracer->spans() as $span) {
            if ($span->spanId === $spanId) {
                return $span;
            }
        }

        return null;
    }

    /**
     * Walks parent ids from `$leaf` up to a root, returning the chain of span
     * KINDS leaf-first. Fails the test on a dangling parent pointer — a child
     * that names a parent nobody recorded is exactly the disconnected-tree bug
     * this suite guards.
     *
     * @return list<string>
     */
    private static function chainToRoot(RecordingTraceRecorder $tracer, TraceSpan $leaf): array
    {
        $kinds = [$leaf->kind];
        $current = $leaf;
        while ($current->parentSpanId !== null) {
            $parent = self::spanById($tracer, $current->parentSpanId);
            self::assertNotNull(
                $parent,
                "span '{$current->kind}' names parent '{$current->parentSpanId}' but no such span was recorded",
            );
            self::assertSame(
                $leaf->correlationId,
                $parent->correlationId,
                'every span on one chain must share one correlation id',
            );
            $kinds[] = $parent->kind;
            $current = $parent;
        }

        return $kinds;
    }

    public function testEvidencePathRecordsSupervisorWorkerRetrieveChainUnderOneCorrelationId(): void
    {
        $tracer = new RecordingTraceRecorder();
        $this->supervisor(StubLlmClient::up($this->labResponse()), $tracer)
            ->handle(new AgentRequest(pid: self::PID, correlationId: 'tree-ev', question: 'what is the A1c goal', tags: ['a1c']));

        $retrieveSpans = $tracer->spansOfKind('retrieve');
        self::assertCount(1, $retrieveSpans, 'the RAG call must record its own retrieve span');
        self::assertSame(
            ['retrieve', 'worker', 'supervisor'],
            self::chainToRoot($tracer, $retrieveSpans[0]),
            'the evidence path must link retrieve -> worker -> supervisor by parent ids',
        );
        self::assertSame('tree-ev', $retrieveSpans[0]->correlationId);
        self::assertSame('ok', $retrieveSpans[0]->status, 'a corpus hit is an ok retrieval');
        self::assertNull($retrieveSpans[0]->errorDetail, 'happy-path spans carry no detail line (module convention)');
    }

    public function testEmptyRetrievalDegradesWithPhiFreeCountsOnly(): void
    {
        $tracer = new RecordingTraceRecorder();
        $this->supervisor(StubLlmClient::up($this->labResponse()), $tracer)
            ->handle(new AgentRequest(pid: self::PID, correlationId: 'tree-empty', question: 'zzzq wwwx yyyv'));

        $retrieve = $tracer->spansOfKind('retrieve')[0];
        self::assertSame('degraded', $retrieve->status, 'zero hits is a degraded retrieval, not an ok one');
        self::assertSame('EmptyRetrieval', $retrieve->errorClass);
        self::assertSame('query_terms=3 top_k=4 hits=0', $retrieve->errorDetail);
        self::assertStringNotContainsString('zzzq', (string)$retrieve->errorDetail, 'the query text itself must never reach the trace row');
    }

    public function testIntakePathRecordsSupervisorWorkerVisionExtractChainWithSingleCountedTokens(): void
    {
        $tracer = new RecordingTraceRecorder();
        $this->supervisor(StubLlmClient::up($this->labResponse()), $tracer)
            ->handle(new AgentRequest(
                pid: self::PID,
                correlationId: 'tree-doc',
                docType: DocType::LabPdf,
                documentBytes: 'PDFBYTES',
                mimeType: 'application/pdf',
            ));

        $visionSpans = $tracer->spansOfKind('vision_extract');
        self::assertCount(1, $visionSpans, 'the VLM call must record its own vision_extract span');
        $vision = $visionSpans[0];
        self::assertSame(
            ['vision_extract', 'worker', 'supervisor'],
            self::chainToRoot($tracer, $vision),
            'the intake path must link vision_extract -> worker -> supervisor by parent ids',
        );

        // The model/token metadata lives on the vision_extract child ONLY —
        // duplicated onto the worker span it would double-count in the
        // dashboard's SUM(tokens_in)/SUM(cost_usd) over mod_copilot_trace.
        self::assertSame('gemini-2.5-pro', $vision->model);
        self::assertSame(900, $vision->tokensIn);
        self::assertSame(30, $vision->tokensOut);
        $worker = self::spanById($tracer, $vision->parentSpanId);
        self::assertNotNull($worker);
        self::assertNull($worker->model, 'the worker handoff span must not repeat the model');
        self::assertNull($worker->tokensIn, 'token counts on the worker span would double-count the call');
        self::assertNull($worker->tokensOut);
    }

    public function testUnavailableVlmDegradesTheVisionExtractSpanWithoutBreakingTheChain(): void
    {
        $tracer = new RecordingTraceRecorder();
        $this->supervisor(StubLlmClient::down(), $tracer)
            ->handle(new AgentRequest(
                pid: self::PID,
                correlationId: 'tree-down',
                docType: DocType::LabPdf,
                documentBytes: 'PDFBYTES',
                mimeType: 'application/pdf',
            ));

        $vision = $tracer->spansOfKind('vision_extract')[0];
        self::assertSame('degraded', $vision->status, 'no model => a degraded vision_extract span, never a missing one');
        self::assertNull($vision->model);
        self::assertSame(['vision_extract', 'worker', 'supervisor'], self::chainToRoot($tracer, $vision));
    }

    public function testAgentDrivenIngestAttachesUnderTheSupervisorTreeAsAFourLevelChain(): void
    {
        // One recorder, one correlation id: first the supervisor run, then the
        // ingest path driven "from the agent" — the caller hands the worker's
        // span id (and the run's correlation id) down to AttachAndExtract.
        $tracer = new RecordingTraceRecorder();
        $this->supervisor(StubLlmClient::up($this->labResponse()), $tracer)
            ->handle(new AgentRequest(
                pid: self::PID,
                correlationId: 'tree-ingest',
                docType: DocType::LabPdf,
                documentBytes: 'PDFBYTES',
                mimeType: 'application/pdf',
            ));
        $workerSpanId = $tracer->spansOfKind('worker')[0]->spanId;

        $this->attachAndExtract(StubLlmClient::up($this->intakeResponse()), $tracer)
            ->previewIntake('PDFBYTES', 'application/pdf', 'tree-ingest', $workerSpanId);

        $preview = $tracer->spansOfKind('preview')[0];
        self::assertSame($workerSpanId, $preview->parentSpanId, 'the agent-driven ingest span must parent to the worker that drove it');
        self::assertSame('tree-ingest', $preview->correlationId);
        self::assertSame(
            ['preview', 'worker', 'supervisor'],
            self::chainToRoot($tracer, $preview),
            'agent-driven ingest joins the supervisor tree instead of starting a disconnected root',
        );

        // Its vision_extract child completes the 4-level waterfall:
        // supervisor -> worker -> preview -> vision_extract.
        $ingestVision = null;
        foreach ($tracer->spansOfKind('vision_extract') as $span) {
            if ($span->parentSpanId === $preview->spanId) {
                $ingestVision = $span;
            }
        }
        self::assertNotNull($ingestVision, 'the ingest-path VLM call must parent to the ingest span');
        self::assertSame(['vision_extract', 'preview', 'worker', 'supervisor'], self::chainToRoot($tracer, $ingestVision));
    }

    public function testStandaloneIngestStaysARootSpanWithItsOwnCorrelationId(): void
    {
        // The upload endpoints pass no parent span id — their behavior is
        // unchanged: the preview/ingest span is the root of its OWN tree.
        $tracer = new RecordingTraceRecorder();
        $this->attachAndExtract(StubLlmClient::up($this->intakeResponse()), $tracer)
            ->previewIntake('PDFBYTES', 'application/pdf', 'ingest-standalone');

        $preview = $tracer->spansOfKind('preview')[0];
        self::assertNull($preview->parentSpanId, 'standalone ingest must remain a root span');
        self::assertSame('ingest-standalone', $preview->correlationId);
        self::assertSame($preview->spanId, $tracer->spansOfKind('vision_extract')[0]->parentSpanId);
    }
}
