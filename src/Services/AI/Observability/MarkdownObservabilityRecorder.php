<?php

/**
 * Appends AI transaction records to the OBSERVABILITY.md ledger.
 *
 * Each transaction becomes one row in a Markdown table capturing the
 * timestamp, outcome (including failures), per-step timings, token counts,
 * and estimated cost. The file is created with a documented header the
 * first time a record is written.
 *
 * Writing is best-effort and fail-safe: any I/O problem is logged via PSR-3
 * and swallowed so that instrumentation can never break the AI request it
 * is measuring. The ledger intentionally stores no patient identifiers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI\Observability;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MarkdownObservabilityRecorder implements ObservabilityRecorderInterface
{
    public const DEFAULT_FILENAME = 'OBSERVABILITY.md';

    private const TABLE_HEADER = '| Timestamp (UTC) | ID | Transaction | Status | Model '
        . '| Prompt | Completion | Total Tokens | Est. Cost (USD) | Duration (ms) | Step Timings (ms) | Detail |';

    private const TABLE_DIVIDER = '| --- | --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | --- | --- |';

    private readonly string $filePath;
    private readonly LoggerInterface $logger;

    public function __construct(
        ?string $filePath = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->filePath = $filePath ?? self::defaultFilePath();
        $this->logger = $logger ?? new NullLogger();
    }

    public function record(AiTransactionRecord $record): void
    {
        try {
            $this->ensureInitialized();
            $line = $this->formatRow($record) . "\n";
            if (file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX) === false) {
                $this->logger->error('Failed to append AI observability record', [
                    'file' => $this->filePath,
                    'transaction_id' => $record->id,
                ]);
            }
        } catch (\Throwable $e) {
            // Observability must never break the request it measures.
            $this->logger->error('Unable to record AI observability entry', ['exception' => $e]);
        }
    }

    private function ensureInitialized(): void
    {
        if (is_file($this->filePath)) {
            return;
        }

        $header = self::documentHeader();
        if (file_put_contents($this->filePath, $header, LOCK_EX) === false) {
            $this->logger->error('Failed to initialize AI observability ledger', ['file' => $this->filePath]);
        }
    }

    private function formatRow(AiTransactionRecord $record): string
    {
        $usage = $record->tokenUsage;
        $cost = $record->estimatedCostUsd;

        return '| ' . implode(' | ', [
            $record->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $record->id,
            $this->escape($record->transactionType),
            $record->status->label(),
            $this->escape($record->model ?? 'n/a'),
            $usage !== null ? (string) $usage->promptTokens : '-',
            $usage !== null ? (string) $usage->completionTokens : '-',
            $usage !== null ? (string) $usage->totalTokens : '-',
            $this->formatCost($cost, $usage),
            number_format($record->totalDurationMs, 2, '.', ''),
            $this->formatSteps($record->steps),
            $this->formatDetail($record->failureReason),
        ]) . ' |';
    }

    private function formatCost(?float $cost, ?TokenUsage $usage): string
    {
        if ($usage === null || $usage->isEmpty()) {
            return '-';
        }
        if ($cost === null) {
            return 'unknown';
        }

        return number_format($cost, 6, '.', '');
    }

    /**
     * @param list<StepTiming> $steps
     */
    private function formatSteps(array $steps): string
    {
        if ($steps === []) {
            return '-';
        }

        $parts = array_map(
            fn(StepTiming $step): string =>
                $this->escape($step->label) . '=' . number_format($step->durationMs, 2, '.', ''),
            $steps,
        );

        return implode('; ', $parts);
    }

    private function formatDetail(?string $failureReason): string
    {
        if ($failureReason === null || trim($failureReason) === '') {
            return '-';
        }

        return $this->escape($failureReason);
    }

    /**
     * Neutralize pipes and newlines so a value cannot break the table row.
     */
    private function escape(string $value): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($value)) ?? $value;

        return str_replace('|', '\\|', $collapsed);
    }

    private static function defaultFilePath(): string
    {
        // src/Services/AI/Observability -> repository root is four levels up.
        return dirname(__DIR__, 4) . '/' . self::DEFAULT_FILENAME;
    }

    private static function documentHeader(): string
    {
        $intro = <<<'MARKDOWN'
# AI Observability Ledger

This file is an append-only ledger of every AI transaction the Clinical
Co-Pilot performs. Each row records one transaction so the team can track
**failures**, **time spent in each step**, and the **number and cost of
tokens** consumed.

Rows are written automatically by
`OpenEMR\Services\AI\Observability\MarkdownObservabilityRecorder`. Do not
edit rows by hand. For an aggregated view (failure rate, average latency,
total spend) run:

```bash
php bin/ai-observability-report.php
```

**Privacy note:** this ledger deliberately contains no patient identifiers
or clinical content — only operational metrics — so it is safe to commit.

## Column reference

| Column | Meaning |
| --- | --- |
| Timestamp (UTC) | When the transaction completed. |
| ID | Random per-transaction correlation id. |
| Transaction | The instrumented operation (e.g. `patient_history_summary`). |
| Status | `SUCCESS` or `FAILURE`. |
| Model | The AI model used, or `n/a` when no external call was made. |
| Prompt / Completion / Total Tokens | Token counts reported by the provider. |
| Est. Cost (USD) | Estimated spend from token counts and list pricing. |
| Duration (ms) | Total measured time across all steps. |
| Step Timings (ms) | Per-step wall-clock breakdown. |
| Detail | Failure reason, or `-` on success. |

## Transactions


MARKDOWN;

        return $intro . self::TABLE_HEADER . "\n" . self::TABLE_DIVIDER . "\n";
    }
}
