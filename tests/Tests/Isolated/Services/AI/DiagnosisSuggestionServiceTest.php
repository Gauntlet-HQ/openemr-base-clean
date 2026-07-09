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

use OpenEMR\Services\AI\ActivePatientIssuesProviderInterface;
use OpenEMR\Services\AI\DiagnosisSuggestionResult;
use OpenEMR\Services\AI\DiagnosisSuggestionService;
use OpenEMR\Services\AI\Observability\AiObservability;
use OpenEMR\Services\AI\Observability\InMemoryObservabilityRecorder;
use OpenEMR\Services\AI\Observability\TokenUsage;
use OpenEMR\Services\AI\Observability\TransactionStatus;
use OpenEMR\Services\AI\OpenAiChatClientInterface;
use OpenEMR\Services\AI\OpenAiChatResult;
use OpenEMR\Services\AI\OpenAiException;
use OpenEMR\Services\AI\RecentEncounterProviderInterface;
use OpenEMR\Validators\ProcessingResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
final class DiagnosisSuggestionServiceTest extends TestCase
{
    private OpenAiChatClientInterface&MockObject $chatClient;
    private RecentEncounterProviderInterface&MockObject $encounterService;
    private ActivePatientIssuesProviderInterface&MockObject $issuesService;
    private InMemoryObservabilityRecorder $recorder;
    private DiagnosisSuggestionService $service;

    protected function setUp(): void
    {
        $this->chatClient = $this->createMock(OpenAiChatClientInterface::class);
        $this->encounterService = $this->createMock(RecentEncounterProviderInterface::class);
        $this->issuesService = $this->createMock(ActivePatientIssuesProviderInterface::class);
        $this->recorder = new InMemoryObservabilityRecorder();

        $this->service = new DiagnosisSuggestionService(
            $this->chatClient,
            $this->encounterService,
            $this->issuesService,
            new AiObservability($this->recorder),
        );
    }

    public function testReturnsInsufficientDataWhenNothingOnRecord(): void
    {
        $this->encounterService->method('getMostRecentEncounterForPatient')->willReturn(null);
        $this->issuesService->method('getActiveIssues')->willReturn(new ProcessingResult());

        // No data means we must never spend a call on the AI service.
        $this->chatClient->expects($this->never())->method('chatWithUsage');

        $result = $this->service->suggestByPid(42);

        $this->assertFalse($result->hasSufficientData);
        $this->assertSame(DiagnosisSuggestionResult::INSUFFICIENT_DATA_LABEL, $result->suggestions);
    }

    public function testSuggestsWhenOnlyEncounterExists(): void
    {
        $this->encounterService->method('getMostRecentEncounterForPatient')
            ->willReturn(['date' => '2026-01-15', 'reason' => 'Productive cough and fever']);
        $this->issuesService->method('getActiveIssues')->willReturn(new ProcessingResult());

        $this->chatClient->expects($this->once())
            ->method('chatWithUsage')
            ->willReturn(new OpenAiChatResult(
                '1. Community-acquired pneumonia — fever with productive cough; consider chest X-ray.',
                'gpt-4o-mini',
                new TokenUsage(140, 60, 200),
            ));

        $result = $this->service->suggestByPid(42);

        $this->assertTrue($result->hasSufficientData);
        $this->assertStringContainsString('pneumonia', $result->suggestions);
    }

    public function testPromptSeparatesMedicationsFromConditionsAndVisit(): void
    {
        $this->encounterService->method('getMostRecentEncounterForPatient')
            ->willReturn(['date' => '2026-01-15', 'reason' => 'Chest pain']);

        $issues = new ProcessingResult();
        $issues->addData([
            'type' => 'medication',
            'title' => 'Lisinopril 10mg',
            'diagnosis' => '',
            'begdate' => '2021-06-01',
        ]);
        $issues->addData([
            'type' => 'problem',
            'title' => 'Hypertension',
            'diagnosis' => 'ICD10:I10',
            'begdate' => '2020-03-01',
        ]);
        $this->issuesService->method('getActiveIssues')->willReturn($issues);

        $capturedMessages = null;
        $this->chatClient->method('chatWithUsage')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages): OpenAiChatResult {
                $capturedMessages = $messages;

                return new OpenAiChatResult('suggestions', 'gpt-4o-mini', TokenUsage::none());
            });

        $this->service->suggestByPid(42);

        $this->assertIsArray($capturedMessages);
        $this->assertArrayHasKey(1, $capturedMessages);
        $userEntry = $capturedMessages[1];
        $this->assertIsArray($userEntry);
        $userMessage = $userEntry['content'] ?? null;
        $this->assertIsString($userMessage);

        $this->assertStringContainsString('Current visit:', $userMessage);
        $this->assertStringContainsString('Chest pain', $userMessage);
        $this->assertStringContainsString('Active medications:', $userMessage);
        $this->assertStringContainsString('Lisinopril 10mg', $userMessage);
        $this->assertStringContainsString('Pre-existing conditions:', $userMessage);
        $this->assertStringContainsString('Hypertension', $userMessage);

        // A medication must not leak into the conditions section and vice versa.
        $medsPosition = strpos($userMessage, 'Active medications:');
        $conditionsPosition = strpos($userMessage, 'Pre-existing conditions:');
        $medicationEntryPosition = strpos($userMessage, 'Lisinopril 10mg');
        $this->assertIsInt($medsPosition);
        $this->assertIsInt($conditionsPosition);
        $this->assertIsInt($medicationEntryPosition);
        $this->assertGreaterThan($medicationEntryPosition, $conditionsPosition);
    }

    public function testRejectsNonPositivePid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->suggestByPid(0);
    }

    public function testRecordsSuccessfulTransactionWithTokenUsage(): void
    {
        $this->encounterService->method('getMostRecentEncounterForPatient')
            ->willReturn(['date' => '2026-01-15', 'reason' => 'Chest pain']);
        $this->issuesService->method('getActiveIssues')->willReturn(new ProcessingResult());
        $this->chatClient->method('chatWithUsage')->willReturn(
            new OpenAiChatResult('suggestions', 'gpt-4o-mini', new TokenUsage(1000, 200, 1200)),
        );

        $this->service->suggestByPid(42);

        $record = $this->recorder->latest();
        $this->assertNotNull($record);
        $this->assertSame(TransactionStatus::Success, $record->status);
        $this->assertSame('diagnosis_suggestion', $record->transactionType);
        $this->assertSame('gpt-4o-mini', $record->model);
        $this->assertNotNull($record->tokenUsage);
        $this->assertSame(1200, $record->tokenUsage->totalTokens);
        // gpt-4o-mini: 1000 in * 0.15/1M + 200 out * 0.60/1M = 0.00015 + 0.00012.
        $this->assertNotNull($record->estimatedCostUsd);
        $this->assertEqualsWithDelta(0.00027, $record->estimatedCostUsd, 1e-9);

        $stepLabels = array_map(static fn($step) => $step->label, $record->steps);
        $this->assertContains('fetch_current_visit', $stepLabels);
        $this->assertContains('fetch_active_issues', $stepLabels);
        $this->assertContains('openai_chat', $stepLabels);
    }

    public function testRecordsFailedTransactionWhenChatThrows(): void
    {
        $this->encounterService->method('getMostRecentEncounterForPatient')
            ->willReturn(['date' => '2026-01-15', 'reason' => 'Chest pain']);
        $this->issuesService->method('getActiveIssues')->willReturn(new ProcessingResult());
        $this->chatClient->method('chatWithUsage')
            ->willThrowException(new OpenAiException('The AI service returned an error.'));

        try {
            $this->service->suggestByPid(42);
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

    public function testRecordsInsufficientDataTransactionWithoutModel(): void
    {
        $this->encounterService->method('getMostRecentEncounterForPatient')->willReturn(null);
        $this->issuesService->method('getActiveIssues')->willReturn(new ProcessingResult());

        $this->service->suggestByPid(42);

        $record = $this->recorder->latest();
        $this->assertNotNull($record);
        $this->assertSame(TransactionStatus::Success, $record->status);
        $this->assertNull($record->model);
        $this->assertNull($record->tokenUsage);
        $this->assertNull($record->estimatedCostUsd);
    }
}
