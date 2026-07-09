<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\AI\Observability;

use DateTimeImmutable;
use OpenEMR\Services\AI\Observability\AiTransactionRecord;
use OpenEMR\Services\AI\Observability\MarkdownObservabilityRecorder;
use OpenEMR\Services\AI\Observability\StepTiming;
use OpenEMR\Services\AI\Observability\TokenUsage;
use OpenEMR\Services\AI\Observability\TransactionStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
final class MarkdownObservabilityRecorderTest extends TestCase
{
    private string $ledgerPath;

    protected function setUp(): void
    {
        $this->ledgerPath = sys_get_temp_dir() . '/obs-' . bin2hex(random_bytes(4)) . '.md';
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledgerPath)) {
            unlink($this->ledgerPath);
        }
    }

    public function testInitializesFileWithHeaderAndAppendsRow(): void
    {
        $recorder = new MarkdownObservabilityRecorder($this->ledgerPath);
        $recorder->record($this->successRecord());

        $contents = (string) file_get_contents($this->ledgerPath);

        $this->assertStringContainsString('# AI Observability Ledger', $contents);
        $this->assertStringContainsString('| Timestamp (UTC) | ID |', $contents);
        $this->assertStringContainsString('patient_history_summary', $contents);
        $this->assertStringContainsString('SUCCESS', $contents);
        $this->assertStringContainsString('gpt-4o-mini', $contents);
        $this->assertStringContainsString('fetch_recent_encounter=5.00; openai_chat=800.00', $contents);
        $this->assertStringContainsString('| 1200 |', $contents);
    }

    public function testAppendsWithoutRewritingHeader(): void
    {
        $recorder = new MarkdownObservabilityRecorder($this->ledgerPath);
        $recorder->record($this->successRecord());
        $recorder->record($this->failureRecord());

        $contents = (string) file_get_contents($this->ledgerPath);

        $this->assertSame(1, substr_count($contents, '# AI Observability Ledger'));
        $this->assertStringContainsString('FAILURE', $contents);
        $this->assertStringContainsString('OpenAiException: the service failed', $contents);
    }

    public function testEscapesPipesInValues(): void
    {
        $record = new AiTransactionRecord(
            id: 'abc123',
            occurredAt: new DateTimeImmutable('2026-01-15 10:30:00'),
            transactionType: 'patient_history_summary',
            status: TransactionStatus::Failure,
            model: 'gpt-4o-mini',
            tokenUsage: null,
            estimatedCostUsd: null,
            steps: [],
            totalDurationMs: 12.0,
            failureReason: 'Weird | pipe in message',
        );

        $recorder = new MarkdownObservabilityRecorder($this->ledgerPath);
        $recorder->record($record);

        $contents = (string) file_get_contents($this->ledgerPath);
        $this->assertStringContainsString('Weird \\| pipe in message', $contents);
    }

    private function successRecord(): AiTransactionRecord
    {
        return new AiTransactionRecord(
            id: 'abc123',
            occurredAt: new DateTimeImmutable('2026-01-15 10:30:00'),
            transactionType: 'patient_history_summary',
            status: TransactionStatus::Success,
            model: 'gpt-4o-mini',
            tokenUsage: new TokenUsage(1000, 200, 1200),
            estimatedCostUsd: 0.00027,
            steps: [
                new StepTiming('fetch_recent_encounter', 5.0),
                new StepTiming('openai_chat', 800.0),
            ],
            totalDurationMs: 805.0,
            failureReason: null,
        );
    }

    private function failureRecord(): AiTransactionRecord
    {
        return new AiTransactionRecord(
            id: 'def456',
            occurredAt: new DateTimeImmutable('2026-01-15 10:31:00'),
            transactionType: 'patient_history_summary',
            status: TransactionStatus::Failure,
            model: null,
            tokenUsage: null,
            estimatedCostUsd: null,
            steps: [new StepTiming('fetch_recent_encounter', 4.0)],
            totalDurationMs: 4.0,
            failureReason: 'OpenAiException: the service failed',
        );
    }
}
