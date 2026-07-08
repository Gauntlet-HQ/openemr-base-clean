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

use OpenEMR\Services\AI\OpenAiChatClient;
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
    private PatientHistorySummaryService $service;

    protected function setUp(): void
    {
        $this->chatClient = $this->createMock(OpenAiChatClient::class);
        $this->encounterService = $this->createMock(EncounterService::class);
        $this->socialHistoryService = $this->createMock(SocialHistoryService::class);
        $this->issuesService = $this->createMock(PatientIssuesService::class);

        $this->service = new PatientHistorySummaryService(
            $this->chatClient,
            $this->encounterService,
            $this->socialHistoryService,
            $this->issuesService,
        );
    }

    public function testReturnsNewPatientWhenNoHistoryExists(): void
    {
        $this->encounterService->method('getMostRecentEncounterForPatient')->willReturn(null);
        $this->socialHistoryService->method('getHistoryData')->willReturn(false);
        $this->issuesService->method('getActiveIssues')->willReturn(new ProcessingResult());

        // No history means we must never spend a call on the AI service.
        $this->chatClient->expects($this->never())->method('chat');

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
            ->method('chat')
            ->willReturn('Patient presented with chest pain on 2026-01-15.');

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
        $this->chatClient->method('chat')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages): string {
                $capturedMessages = $messages;

                return 'summary';
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
}
