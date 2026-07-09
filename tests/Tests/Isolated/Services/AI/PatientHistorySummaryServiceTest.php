<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\AI;

use OpenEMR\Services\AI\Observability\AiObservability;
use OpenEMR\Services\AI\Observability\InMemoryObservabilityRecorder;
use OpenEMR\Services\AI\Observability\TokenUsage;
use OpenEMR\Services\AI\Observability\TransactionStatus;
use OpenEMR\Services\AI\OpenAiChatClient;
use OpenEMR\Services\AI\OpenAiChatResult;
use OpenEMR\Services\AI\OpenAiException;
use OpenEMR\Services\AI\PatientHistorySummaryResult;
use OpenEMR\Services\AI\PatientHistorySummaryService;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\PatientIssuesService;
use OpenEMR\Services\SocialHistoryService;
use OpenEMR\Validators\ProcessingResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
final class PatientHistorySummaryServiceTest extends TestCase
{
    private OpenAiChatClient&MockObject $chatClient;
    private EncounterService&MockObject $encounterService;
    private SocialHistoryService&MockObject $socialHistoryService;
    private PatientIssuesService&MockObject $issuesService;
    private InMemoryObservabilityRecorder $recorder;
    private PatientHistorySummaryService $service;

    protected function setUp(): void
    {
        $this->chatClient = $this->createMock(OpenAiChatClient::class);
        $this->encounterService = $this->createMock(EncounterService::class);
        $this->socialHistoryService = $this->createMock(SocialHistoryService::class);
        $this->issuesService = $this->createMock(PatientIssuesService::class);
        $this->recorder = new InMemoryObservabilityRecorder();

        $this->service = new PatientHistorySummaryService(
            $this->chatClient,
            $this->encounterService,
            $this->socialHistoryService,
            $this->issuesService,
            new AiObservability($this->recorder),
        );
    }

    public function testReturnsNewPatientWhenNoHistoryExists(): void
    {
        $this->encounterService->method('getMostRecentEncounterForPatient')->willReturn(null);
        $this->socialHistoryService->method('getHistoryData')->willReturn(false);
        $this->issuesService->method('getActiveIssues')->willReturn(new ProcessingResult());

        // No history means we must never spend a call on the AI service.
        $this->chatClient->expects($this->never())->method('chatWithUsage');

        $result = $this->service->summarizeByPid(42);

        $this->assertTrue($result->isNewPatient);
        $this->assertSame(PatientHistorySummaryResult::NEW_PATIENT_LABEL, $result->summary);
    }

    public function testSummarizesWhenEncounterExists(): void
    {
        $this->encounterService->method('getMostRecentEncounterForPatient')
            ->willReturn(['date' => '2026-01-15', 'reason' => 'Chest pain']);
        $this->socialHistoryService->method('getHistoryData')->willReturn(false);
        $this->issuesService->method('getActiveIssues')->willReturn(new ProcessingResult());

        $this->chatClient->expects($this->once())
            ->method('chatWithUsage')
            ->willReturn(new OpenAiChatResult(
                'Patient presented with chest pain on 2026-01-15.',
                'gpt-4o-mini',
                new TokenUsage(120, 30, 150),
            ));

        $result = $this->service->summarizeByPid(42);

        $this->assertFalse($result->isNewPatient);
        $this->assertSame('Patient presented with chest pain on 2026-01-15.', $result->summary);
    }

    public function testPromptContainsHistoryDetailsButNoRequestForIdentifiers(): void
    {
        $this->encounterService->method('getMostRecentEncounterForPatient')
            ->willReturn(['date' => '2026-01-15', 'reason' => 'Chest pain']);
        $this->socialHistoryService->method('getHistoryData')
            ->willReturn(['id' => 7, 'tobacco' => 'Never smoker', 'alcohol' => 'Social']);
        $issues = new ProcessingResult();
        $issues->addData(['title' => 'Hypertension', 'diagnosis' => 'ICD10:I10', 'begdate' => '2020-03-01']);
        $this->issuesService->method('getActiveIssues')->willReturn($issues);

        $capturedMessages = null;
        $this->chatClient->method('chatWithUsage')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages): OpenAiChatResult {
                $capturedMessages = $messages;

                return new OpenAiChatResult('summary', 'gpt-4o-mini', TokenUsage::none());
            });

        $this->service->summarizeByPid(42);

        $this->assertIsArray($capturedMessages);
        $userMessage = $capturedMessages[1]['content'] ?? '';
        $this->assertStringContainsString('Chest pain', $userMessage);
        $this->assertStringContainsString('Never smoker', $userMessage);
        $this->assertStringContainsString('Hypertension', $userMessage);
    }

    public function testRejectsNonPositivePid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->summarizeByPid(0);
    }

    public function testRecordsSuccessfulTransactionWithTokenUsage(): void
    {
        $this->encounterService->method('getMostRecentEncounterForPatient')
            ->willReturn(['date' => '2026-01-15', 'reason' => 'Chest pain']);
        $this->socialHistoryService->method('getHistoryData')->willReturn(false);
        $this->issuesService->method('getActiveIssues')->willReturn(new ProcessingResult());
        $this->chatClient->method('chatWithUsage')->willReturn(
            new OpenAiChatResult('summary', 'gpt-4o-mini', new TokenUsage(1000, 200, 1200)),
        );

        $this->service->summarizeByPid(42);

        $record = $this->recorder->latest();
        $this->assertNotNull($record);
        $this->assertSame(TransactionStatus::Success, $record->status);
        $this->assertSame('patient_history_summary', $record->transactionType);
        $this->assertSame('gpt-4o-mini', $record->model);
        $this->assertNotNull($record->tokenUsage);
        $this->assertSame(1200, $record->tokenUsage->totalTokens);
        // gpt-4o-mini: 1000 in * 0.15/1M + 200 out * 0.60/1M = 0.00015 + 0.00012.
        $this->assertNotNull($record->estimatedCostUsd);
        $this->assertEqualsWithDelta(0.00027, $record->estimatedCostUsd, 1e-9);

        $stepLabels = array_map(static fn($step) => $step->label, $record->steps);
        $this->assertContains('fetch_recent_encounter', $stepLabels);
        $this->assertContains('openai_chat', $stepLabels);
    }

    public function testRecordsFailedTransactionWhenChatThrows(): void
    {
        $this->encounterService->method('getMostRecentEncounterForPatient')
            ->willReturn(['date' => '2026-01-15', 'reason' => 'Chest pain']);
        $this->socialHistoryService->method('getHistoryData')->willReturn(false);
        $this->issuesService->method('getActiveIssues')->willReturn(new ProcessingResult());
        $this->chatClient->method('chatWithUsage')
            ->willThrowException(new OpenAiException('The AI service returned an error.'));

        try {
            $this->service->summarizeByPid(42);
            $this->fail('Expected OpenAiException to propagate.');
        } catch (OpenAiException) {
            // expected
        }

        $record = $this->recorder->latest();
        $this->assertNotNull($record);
        $this->assertSame(TransactionStatus::Failure, $record->status);
        $this->assertNotNull($record->failureReason);
        $this->assertStringContainsString('OpenAiException', $record->failureReason);
    }

    public function testRecordsNewPatientTransactionWithoutModel(): void
    {
        $this->encounterService->method('getMostRecentEncounterForPatient')->willReturn(null);
        $this->socialHistoryService->method('getHistoryData')->willReturn(false);
        $this->issuesService->method('getActiveIssues')->willReturn(new ProcessingResult());

        $this->service->summarizeByPid(42);

        $record = $this->recorder->latest();
        $this->assertNotNull($record);
        $this->assertSame(TransactionStatus::Success, $record->status);
        $this->assertNull($record->model);
        $this->assertNull($record->tokenUsage);
        $this->assertNull($record->estimatedCostUsd);
    }
}
