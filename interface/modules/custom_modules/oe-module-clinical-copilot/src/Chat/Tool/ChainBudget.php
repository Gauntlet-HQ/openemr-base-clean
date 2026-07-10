<?php

/**
 * Tracks the per-turn tool-chaining budget: max 5 calls, max 3 rounds (ARCHITECTURE.md §1.2).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Tool;

/**
 * "Tool chaining is allowed to a bounded depth (max 5 tool calls per turn,
 * max 3 rounds) ... The chaining budget exists because unbounded loops are a
 * latency and cost failure mode; hitting the budget degrades transparently"
 * (ARCHITECTURE.md §1.2). Mutable by design (a turn owns exactly one
 * instance, consumed round by round) -- this is turn-scoped bookkeeping, not
 * a Fact or a ledger row.
 */
final class ChainBudget
{
    public const MAX_CALLS = 5;
    public const MAX_ROUNDS = 3;

    private int $callsUsed = 0;
    private int $roundsUsed = 0;

    public function startRound(): bool
    {
        if ($this->roundsUsed >= self::MAX_ROUNDS) {
            return false;
        }
        $this->roundsUsed++;

        return true;
    }

    /**
     * How many more calls this round may make without exceeding the
     * per-turn call budget -- callers should stop issuing calls once this
     * reaches 0, even mid-round.
     */
    public function remainingCalls(): int
    {
        return max(0, self::MAX_CALLS - $this->callsUsed);
    }

    public function recordCall(): void
    {
        $this->callsUsed++;
    }

    public function roundsUsed(): int
    {
        return $this->roundsUsed;
    }
}
