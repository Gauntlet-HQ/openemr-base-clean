<?php

/**
 * Entry point for instrumenting AI transactions.
 *
 * Bundles the record sink, the pricing table, the clock, and the monotonic
 * timer, and hands out a fresh AiTransaction for each unit of work. Inject a
 * configured instance to control where records go (Markdown ledger in
 * production, in-memory in tests); the zero-argument default writes to the
 * repository's OBSERVABILITY.md.
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
use DateTimeZone;
use Lcobucci\Clock\SystemClock;
use Psr\Clock\ClockInterface;

final class AiObservability
{
    private readonly ObservabilityRecorderInterface $recorder;
    private readonly TokenPricing $pricing;
    private readonly ClockInterface $clock;
    /** @var Closure(): int */
    private readonly Closure $monotonicNanoseconds;

    /**
     * @param (Closure(): int)|null $monotonicNanoseconds Monotonic timer,
     *        defaulting to hrtime(true); overridable for deterministic tests.
     */
    public function __construct(
        ?ObservabilityRecorderInterface $recorder = null,
        ?TokenPricing $pricing = null,
        ?ClockInterface $clock = null,
        ?Closure $monotonicNanoseconds = null,
    ) {
        $this->recorder = $recorder ?? new MarkdownObservabilityRecorder();
        $this->pricing = $pricing ?? new TokenPricing();
        // Construct SystemClock directly with the system timezone; the
        // fromSystemTimezone() factory is flagged by ForbiddenStaticMethodsRule.
        $this->clock = $clock ?? new SystemClock(new DateTimeZone(date_default_timezone_get()));
        $this->monotonicNanoseconds = $monotonicNanoseconds ?? static fn(): int => hrtime(true);
    }

    public function begin(string $transactionType): AiTransaction
    {
        return new AiTransaction(
            $transactionType,
            $this->recorder,
            $this->pricing,
            $this->clock,
            $this->monotonicNanoseconds,
        );
    }
}
