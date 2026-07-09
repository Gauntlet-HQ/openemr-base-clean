<?php

/**
 * Aggregates the OBSERVABILITY.md ledger into a summary report.
 *
 * Parses the transaction table written by MarkdownObservabilityRecorder and
 * computes headline metrics: how many transactions ran, how many failed,
 * total and average latency, per-step average latency, and total token
 * consumption and estimated cost. Used by the `bin/ai-observability-report`
 * CLI tool but kept free of I/O concerns so it is unit-testable.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI\Observability;

final class ObservabilityReport
{
    private const EXPECTED_COLUMNS = 12;
    private const PIPE_PLACEHOLDER = "\0";

    /**
     * @param list<array{status: string, model: string, totalTokens: int, cost: float|null, hasCost: bool, durationMs: float, steps: array<string, float>}> $rows
     */
    private function __construct(private readonly array $rows)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Observability ledger not found.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the observability ledger.');
        }

        return self::fromMarkdown($contents);
    }

    public static function fromMarkdown(string $contents): self
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $row = self::parseRow($line);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return new self($rows);
    }

    public function totalTransactions(): int
    {
        return count($this->rows);
    }

    public function failureCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn(array $row): bool => strtoupper($row['status']) === 'FAILURE',
        ));
    }

    public function successCount(): int
    {
        return $this->totalTransactions() - $this->failureCount();
    }

    public function failureRate(): float
    {
        if ($this->rows === []) {
            return 0.0;
        }

        return $this->failureCount() / $this->totalTransactions();
    }

    public function totalTokens(): int
    {
        return array_sum(array_map(static fn(array $row): int => $row['totalTokens'], $this->rows));
    }

    public function totalCostUsd(): float
    {
        $total = 0.0;
        foreach ($this->rows as $row) {
            if ($row['hasCost'] && $row['cost'] !== null) {
                $total += $row['cost'];
            }
        }

        return round($total, 6);
    }

    public function averageDurationMs(): float
    {
        if ($this->rows === []) {
            return 0.0;
        }
        $total = array_sum(array_map(static fn(array $row): float => $row['durationMs'], $this->rows));

        return round($total / $this->totalTransactions(), 2);
    }

    public function maxDurationMs(): float
    {
        $durations = array_map(static fn(array $row): float => $row['durationMs'], $this->rows);

        return $durations === [] ? 0.0 : round(max($durations), 2);
    }

    /**
     * Average duration per step label across all transactions.
     *
     * @return array<string, float>
     */
    public function averageStepDurationsMs(): array
    {
        $totals = [];
        $counts = [];
        foreach ($this->rows as $row) {
            foreach ($row['steps'] as $label => $duration) {
                $totals[$label] = ($totals[$label] ?? 0.0) + $duration;
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
        }

        $averages = [];
        foreach ($totals as $label => $sum) {
            $averages[$label] = round($sum / $counts[$label], 2);
        }
        arsort($averages);

        return $averages;
    }

    public function render(): string
    {
        $lines = [];
        $lines[] = 'AI Observability Report';
        $lines[] = '=======================';
        $lines[] = '';
        $lines[] = sprintf('Transactions:   %d', $this->totalTransactions());
        $lines[] = sprintf(
            'Successes:      %d',
            $this->successCount(),
        );
        $lines[] = sprintf(
            'Failures:       %d (%.1f%%)',
            $this->failureCount(),
            $this->failureRate() * 100,
        );
        $lines[] = '';
        $lines[] = sprintf('Total tokens:   %d', $this->totalTokens());
        $lines[] = sprintf('Est. total cost: $%.6f', $this->totalCostUsd());
        $lines[] = '';
        $lines[] = sprintf('Avg duration:   %.2f ms', $this->averageDurationMs());
        $lines[] = sprintf('Max duration:   %.2f ms', $this->maxDurationMs());

        $stepAverages = $this->averageStepDurationsMs();
        if ($stepAverages !== []) {
            $lines[] = '';
            $lines[] = 'Average time per step:';
            foreach ($stepAverages as $label => $average) {
                $lines[] = sprintf('  %-28s %8.2f ms', $label, $average);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array{status: string, model: string, totalTokens: int, cost: float|null, hasCost: bool, durationMs: float, steps: array<string, float>}|null
     */
    private static function parseRow(string $line): ?array
    {
        $trimmed = trim($line);
        if (!str_starts_with($trimmed, '|')) {
            return null;
        }

        // Protect escaped pipes before splitting on the cell delimiter.
        $protected = str_replace('\\|', self::PIPE_PLACEHOLDER, $trimmed);
        $cells = array_map('trim', explode('|', $protected));
        // Drop the empty first/last cells created by the leading/trailing pipe.
        array_shift($cells);
        array_pop($cells);
        if (count($cells) !== self::EXPECTED_COLUMNS) {
            return null;
        }
        $cells = array_map(
            static fn(string $cell): string => str_replace(self::PIPE_PLACEHOLDER, '|', $cell),
            $cells,
        );

        // Only data rows begin with a timestamp; this skips header/divider rows.
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cells[0]) !== 1) {
            return null;
        }

        $hasCost = is_numeric($cells[8]);

        return [
            'status' => $cells[3],
            'model' => $cells[4],
            'totalTokens' => is_numeric($cells[7]) ? (int) $cells[7] : 0,
            'cost' => $hasCost ? (float) $cells[8] : null,
            'hasCost' => $hasCost,
            'durationMs' => is_numeric($cells[9]) ? (float) $cells[9] : 0.0,
            'steps' => self::parseSteps($cells[10]),
        ];
    }

    /**
     * @return array<string, float>
     */
    private static function parseSteps(string $value): array
    {
        if (trim($value) === '' || trim($value) === '-') {
            return [];
        }

        $steps = [];
        foreach (explode(';', $value) as $part) {
            $pair = explode('=', trim($part));
            if (count($pair) !== 2) {
                continue;
            }
            $label = trim($pair[0]);
            if ($label === '' || !is_numeric($pair[1])) {
                continue;
            }
            $steps[$label] = (float) $pair[1];
        }

        return $steps;
    }
}
