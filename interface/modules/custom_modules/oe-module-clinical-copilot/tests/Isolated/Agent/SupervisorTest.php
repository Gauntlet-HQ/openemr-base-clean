<?php

/**
 * The deterministic supervisor routes correctly and degrades without throwing.
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
use OpenEMR\Modules\ClinicalCopilot\Agent\WorkerName;
use OpenEMR\Modules\ClinicalCopilot\Ingest\DocType;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineCorpus;
use OpenEMR\Modules\ClinicalCopilot\Rag\SparseRetriever;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\NullTraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce\StubLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: the router invoking a worker it shouldn't (wasted
 * cost), skipping one it should, or letting a degraded model take the whole
 * orchestration down instead of returning a partial, honest result.
 */
final class SupervisorTest extends TestCase
{
    private function supervisor(StubLlmClient $llm): Supervisor
    {
        $tracer = new NullTraceRecorder();
        $extractor = new IntakeExtractorWorker(new ExtractionClient($llm, 'gemini-2.5-pro'), $tracer);
        $retriever = new EvidenceRetrieverWorker(
            new SparseRetriever(new GuidelineCorpus(dirname(__DIR__, 3) . '/src/Rag/corpus')),
            $tracer,
        );

        return new Supervisor($extractor, $retriever, new CriticWorker(new Verifier(), $tracer), $tracer);
    }

    private function labResponse(): LlmResponse
    {
        $json = json_encode(['fields' => [
            ['field_key' => 'Hemoglobin A1c', 'value' => '7.8', 'unit' => '%', 'page' => 1, 'quote' => 'A1c 7.8 %'],
        ]], JSON_THROW_ON_ERROR);

        return new LlmResponse($json, 'gemini-2.5-pro', 900, 30, 500);
    }

    public function testQuestionOnlyRoutesToEvidenceRetrieverOnly(): void
    {
        $result = $this->supervisor(StubLlmClient::up($this->labResponse()))
            ->handle(new AgentRequest(pid: 1, correlationId: 'c1', question: 'what is the A1c goal', tags: ['a1c']));

        self::assertSame([WorkerName::EvidenceRetriever], $result->routed);
        self::assertNull($result->extraction);
        self::assertNotSame([], $result->evidence);
        self::assertSame('ada-a1c-target', $result->evidence[0]->chunk->id);
    }

    public function testDocumentOnlyRoutesToIntakeExtractorOnly(): void
    {
        $result = $this->supervisor(StubLlmClient::up($this->labResponse()))
            ->handle(new AgentRequest(
                pid: 1,
                correlationId: 'c2',
                docType: DocType::LabPdf,
                documentBytes: 'PDFBYTES',
                mimeType: 'application/pdf',
            ));

        self::assertSame([WorkerName::IntakeExtractor], $result->routed);
        self::assertNotNull($result->extraction);
        self::assertSame([], $result->evidence);
    }

    public function testDocumentAndQuestionRouteToBothWorkers(): void
    {
        $result = $this->supervisor(StubLlmClient::up($this->labResponse()))
            ->handle(new AgentRequest(
                pid: 1,
                correlationId: 'c3',
                docType: DocType::LabPdf,
                documentBytes: 'PDFBYTES',
                mimeType: 'application/pdf',
                question: 'is this A1c above target',
                tags: ['a1c'],
            ));

        self::assertTrue($result->routedTo(WorkerName::IntakeExtractor));
        self::assertTrue($result->routedTo(WorkerName::EvidenceRetriever));
        self::assertNotNull($result->extraction);
        self::assertNotSame([], $result->evidence);
    }

    public function testEmptyRequestRoutesNowhere(): void
    {
        $result = $this->supervisor(StubLlmClient::up($this->labResponse()))
            ->handle(new AgentRequest(pid: 1, correlationId: 'c4'));

        self::assertSame([], $result->routed);
        self::assertNull($result->extraction);
        self::assertSame([], $result->evidence);
    }

    public function testUnavailableModelDegradesWithoutThrowing(): void
    {
        // Model down: the extractor worker returns null, the supervisor still
        // completes and evidence retrieval (offline) still works.
        $result = $this->supervisor(StubLlmClient::down())
            ->handle(new AgentRequest(
                pid: 1,
                correlationId: 'c5',
                docType: DocType::LabPdf,
                documentBytes: 'PDFBYTES',
                mimeType: 'application/pdf',
                tags: ['a1c'],
            ));

        self::assertTrue($result->routedTo(WorkerName::IntakeExtractor));
        self::assertNull($result->extraction, 'no model => no extraction, but no exception');
        self::assertNotSame([], $result->evidence, 'offline retrieval still grounds the answer');
    }
}
