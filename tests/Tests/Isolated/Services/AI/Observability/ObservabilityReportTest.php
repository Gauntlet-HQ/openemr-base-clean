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
use OpenEMR\Services\AI\Observability\ObservabilityReport;
use OpenEMR\Services\AI\Observability\StepTiming;
use OpenEMR\Services\AI\Observability\TokenUsage;
use OpenEMR\Services\AI\Observability\TransactionStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
final class ObservabilityReportTest extends TestCase
{
    public function testAggregatesTransactionsFromMarkdown(): void
    {
        $markdown = <<<'MD'
# AI Observability Ledger

Some intro text with a | pipe in a reference table.

| Column | Meaning |
| --- | --- |
| Status | success or failure |

## Transactions

| Timestamp (UTC) | ID | Transaction | Status | Model | Prompt | Completion | Total Tokens | Est. Cost (USD) | Duration (ms) | Step Timings (ms) | Detail |
| --- | --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | --- | --- |
| 2026-01-15 10:30:00 | aaa | patient_history_summary | SUCCESS | gpt-4o-mini | 1000 | 200 | 1200 | 0.000270 | 805.00 | fetch_recent_encounter=5.00; openai_chat=800.00 | - |
| 2026-01-15 10:31:00 | bbb | patient_history_summary | FAILURE | n/a | - | - | - | - | 4.00 | fetch_recent_encounter=4.00 | OpenAiException: boom |
| 2026-01-15 10:32:00 | ccc | patient_history_summary | SUCCESS | gpt-4o-mini | 500 | 100 | 600 | 0.000120 | 405.00 | fetch_recent_encounter=5.00; openai_chat=400.00 | - |
MD;

        $report = ObservabilityReport::fromMarkdown($markdown);

        $this->assertSame(3, $report->totalTransactions());
        $this->assertSame(1, $report->failureCount());
        $this->assertSame(2, $report->successCount());
        $this->assertEqualsWithDelta(1 / 3, $report->failureRate(), 1e-9);
        $this->assertSame(1800, $report->totalTokens());
        $this->assertEqualsWithDelta(0.00039, $report->totalCostUsd(), 1e-9);
        $this->assertEqualsWithDelta((805.0 + 4.0 + 405.0) / 3, $report->averageDurationMs(), 1e-2);
        $this->assertEqualsWithDelta(805.0, $report->maxDurationMs(), 1e-9);

        $stepAverages = $report->averageStepDurationsMs();
        $this->assertArrayHasKey('openai_chat', $stepAverages);
        $this->assertEqualsWithDelta(600.0, $stepAverages['openai_chat'], 1e-9);
        $this->assertEqualsWithDelta((5.0 + 4.0 + 5.0) / 3, $stepAverages['fetch_recent_encounter'], 1e-2);
    }

    public function testEmptyLedgerReportsZeros(): void
    {
        $report = ObservabilityReport::fromMarkdown("# AI Observability Ledger\n\nNo rows yet.\n");

        $this->assertSame(0, $report->totalTransactions());
        $this->assertSame(0.0, $report->failureRate());
        $this->assertSame(0, $report->totalTokens());
        $this->assertSame(0.0, $report->totalCostUsd());
        $this->assertStringContainsString('Transactions:   0', $report->render());
    }

    public function testRoundTripsThroughRecorder(): void
    {
        $path = sys_get_temp_dir() . '/obs-report-' . bin2hex(random_bytes(4)) . '.md';
        $recorder = new MarkdownObservabilityRecorder($path);

        $recorder->record(new AiTransactionRecord(
            id: 'aaa',
            occurredAt: new DateTimeImmutable('2026-01-15 10:30:00'),
            transactionType: 'patient_history_summary',
            status: TransactionStatus::Success,
            model: 'gpt-4o-mini',
            tokenUsage: new TokenUsage(1000, 200, 1200),
            estimatedCostUsd: 0.00027,
            steps: [new StepTiming('openai_chat', 800.0)],
            totalDurationMs: 800.0,
            failureReason: null,
        ));

        try {
            $report = ObservabilityReport::fromFile($path);
            $this->assertSame(1, $report->totalTransactions());
            $this->assertSame(1200, $report->totalTokens());
            $this->assertEqualsWithDelta(0.00027, $report->totalCostUsd(), 1e-9);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
