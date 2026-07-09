<?php

/**
 * Immutable record of a single instrumented AI transaction.
 *
 * Captures everything the observability tool tracks for one transaction:
 * when it happened, whether it succeeded or failed, per-step timings, token
 * usage, and the estimated cost. Deliberately contains no patient
 * identifiers or clinical content so the OBSERVABILITY.md ledger never
 * becomes a PHI store.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI\Observability;

use DateTimeImmutable;

final readonly class AiTransactionRecord
{
    /**
     * @param list<StepTiming> $steps
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $occurredAt,
        public string $transactionType,
        public TransactionStatus $status,
        public ?string $model,
        public ?TokenUsage $tokenUsage,
        public ?float $estimatedCostUsd,
        public array $steps,
        public float $totalDurationMs,
        public ?string $failureReason = null,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->status === TransactionStatus::Success;
    }
}
