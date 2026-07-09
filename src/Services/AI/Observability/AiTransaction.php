<?php

/**
 * Collects timings, token usage, and outcome for one AI transaction.
 *
 * A short-lived, mutable builder created by AiObservability::begin(). The
 * caller wraps each unit of work in measure(), records token usage after
 * the model call, and marks the transaction succeeded or failed. commit()
 * assembles an immutable AiTransactionRecord and hands it to the recorder.
 *
 * Step durations are measured with a monotonic timer (hrtime by default) so
 * they are unaffected by wall-clock adjustments; the record timestamp comes
 * from an injected PSR-20 clock for deterministic testing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI\Observability;

use Closure;
use Psr\Clock\ClockInterface;

final class AiTransaction
{
    private const MAX_DETAIL_LENGTH = 300;

    /** @var list<StepTiming> */
    private array $steps = [];
    private ?TokenUsage $tokenUsage = null;
    private ?string $model = null;
    private TransactionStatus $status = TransactionStatus::Success;
    private ?string $failureReason = null;
    private ?AiTransactionRecord $record = null;

    /**
     * @param Closure(): int $monotonicNanoseconds Returns a monotonically
     *        increasing nanosecond counter (defaults to hrtime(true)).
     */
    public function __construct(
        private readonly string $transactionType,
        private readonly ObservabilityRecorderInterface $recorder,
        private readonly TokenPricing $pricing,
        private readonly ClockInterface $clock,
        private readonly Closure $monotonicNanoseconds,
    ) {
    }

    /**
     * Run and time a step. The duration is recorded whether or not the
     * operation throws, and any throwable propagates unchanged.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function measure(string $label, callable $operation): mixed
    {
        $start = ($this->monotonicNanoseconds)();
        try {
            return $operation();
        } finally {
            $elapsedNs = ($this->monotonicNanoseconds)() - $start;
            $this->steps[] = new StepTiming($label, max(0.0, $elapsedNs / 1_000_000));
        }
    }

    public function recordUsage(string $model, TokenUsage $usage): void
    {
        $this->model = $model;
        $this->tokenUsage = $usage;
    }

    public function markSuccess(): void
    {
        $this->status = TransactionStatus::Success;
        $this->failureReason = null;
    }

    public function markFailure(\Throwable $error): void
    {
        $this->status = TransactionStatus::Failure;
        $this->failureReason = $this->summarizeError($error);
    }

    public function markFailureReason(string $reason): void
    {
        $this->status = TransactionStatus::Failure;
        $this->failureReason = $this->truncate($reason);
    }

    /**
     * Assemble the record and hand it to the recorder. Idempotent, so it is
     * safe to call from a finally block even after an early commit; the
     * record is built and recorded exactly once.
     */
    public function commit(): AiTransactionRecord
    {
        if ($this->record === null) {
            $this->record = $this->buildRecord();
            $this->recorder->record($this->record);
        }

        return $this->record;
    }

    private function buildRecord(): AiTransactionRecord
    {
        $totalMs = 0.0;
        foreach ($this->steps as $step) {
            $totalMs += $step->durationMs;
        }

        $cost = null;
        if ($this->model !== null && $this->tokenUsage !== null) {
            $cost = $this->pricing->estimateCostUsd($this->model, $this->tokenUsage);
        }

        return new AiTransactionRecord(
            id: $this->generateId(),
            occurredAt: $this->clock->now(),
            transactionType: $this->transactionType,
            status: $this->status,
            model: $this->model,
            tokenUsage: $this->tokenUsage,
            estimatedCostUsd: $cost,
            steps: $this->steps,
            totalDurationMs: round($totalMs, 4),
            failureReason: $this->failureReason,
        );
    }

    private function generateId(): string
    {
        try {
            return bin2hex(random_bytes(6));
        } catch (\Throwable) {
            return substr(md5((string) ($this->monotonicNanoseconds)()), 0, 12);
        }
    }

    private function summarizeError(\Throwable $error): string
    {
        return $this->truncate($error::class . ': ' . $error->getMessage());
    }

    private function truncate(string $value): string
    {
        $single = preg_replace('/\s+/', ' ', trim($value)) ?? $value;
        if (mb_strlen($single) <= self::MAX_DETAIL_LENGTH) {
            return $single;
        }

        return mb_substr($single, 0, self::MAX_DETAIL_LENGTH - 1) . '…';
    }
}
