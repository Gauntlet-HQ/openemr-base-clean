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

use Lcobucci\Clock\FrozenClock;
use OpenEMR\Services\AI\Observability\AiObservability;
use OpenEMR\Services\AI\Observability\InMemoryObservabilityRecorder;
use OpenEMR\Services\AI\Observability\TokenPricing;
use OpenEMR\Services\AI\Observability\TokenUsage;
use OpenEMR\Services\AI\Observability\TransactionStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
final class AiTransactionTest extends TestCase
{
    public function testMeasuresStepDurationsFromMonotonicTimer(): void
    {
        $recorder = new InMemoryObservabilityRecorder();
        $observability = $this->observabilityWithTimer($recorder, [
            0,                 // step 1 start
            5_000_000,         // step 1 end (5 ms)
            5_000_000,         // step 2 start
            17_000_000,        // step 2 end (12 ms)
        ]);

        $transaction = $observability->begin('unit_test');
        $transaction->measure('first', static fn(): string => 'a');
        $transaction->measure('second', static fn(): string => 'b');
        $transaction->recordUsage('gpt-4o-mini', new TokenUsage(1000, 200, 1200));
        $transaction->markSuccess();
        $record = $transaction->commit();

        $this->assertSame(TransactionStatus::Success, $record->status);
        $this->assertCount(2, $record->steps);
        $this->assertSame('first', $record->steps[0]->label);
        $this->assertEqualsWithDelta(5.0, $record->steps[0]->durationMs, 1e-6);
        $this->assertEqualsWithDelta(12.0, $record->steps[1]->durationMs, 1e-6);
        $this->assertEqualsWithDelta(17.0, $record->totalDurationMs, 1e-6);
        $this->assertNotNull($record->estimatedCostUsd);
    }

    public function testMeasureRecordsDurationEvenWhenOperationThrows(): void
    {
        $recorder = new InMemoryObservabilityRecorder();
        $observability = $this->observabilityWithTimer($recorder, [0, 3_000_000]);

        $transaction = $observability->begin('unit_test');

        try {
            $transaction->measure('failing', static function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected exception to propagate.');
        } catch (\RuntimeException $e) {
            $transaction->markFailure($e);
        }
        $record = $transaction->commit();

        $this->assertCount(1, $record->steps);
        $this->assertEqualsWithDelta(3.0, $record->steps[0]->durationMs, 1e-6);
        $this->assertSame(TransactionStatus::Failure, $record->status);
        $this->assertNotNull($record->failureReason);
        $this->assertStringContainsString('RuntimeException', $record->failureReason);
        $this->assertStringContainsString('boom', $record->failureReason);
    }

    public function testCommitIsIdempotent(): void
    {
        $recorder = new InMemoryObservabilityRecorder();
        $observability = $this->observabilityWithTimer($recorder, [0, 0]);

        $transaction = $observability->begin('unit_test');
        $transaction->markSuccess();
        $transaction->commit();
        $transaction->commit();

        $this->assertCount(1, $recorder->records());
    }

    public function testUsesInjectedClockForTimestamp(): void
    {
        $recorder = new InMemoryObservabilityRecorder();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-02-01 08:00:00'));
        $observability = new AiObservability(
            $recorder,
            new TokenPricing(),
            $clock,
            $this->timer([0, 0]),
        );

        $record = $observability->begin('unit_test')->commit();

        $this->assertSame('2026-02-01 08:00:00', $record->occurredAt->format('Y-m-d H:i:s'));
    }

    /**
     * @param list<int> $ticks
     */
    private function observabilityWithTimer(InMemoryObservabilityRecorder $recorder, array $ticks): AiObservability
    {
        return new AiObservability(
            $recorder,
            new TokenPricing(),
            new FrozenClock(new \DateTimeImmutable('2026-01-01 00:00:00')),
            $this->timer($ticks),
        );
    }

    /**
     * A deterministic monotonic timer that returns each tick in turn, then
     * repeats the final value for any extra calls.
     *
     * @param list<int> $ticks
     *
     * @return \Closure(): int
     */
    private function timer(array $ticks): \Closure
    {
        $index = 0;

        return static function () use (&$index, $ticks): int {
            $value = $ticks[$index] ?? ($ticks[array_key_last($ticks)] ?? 0);
            $index++;

            return $value;
        };
    }
}
