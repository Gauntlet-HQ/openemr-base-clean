<?php

/**
 * Lab contract C2: result_status -> presentation / clock / in-flight mapping.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Lab\ResultStatusClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: an unperformed test ("cannot be done", "pending",
 * ...) resetting the OverdueTests clock -- exactly the bug that would make
 * an order that was never actually completed look like proof the patient is
 * current on monitoring.
 */
final class ResultStatusClassifierTest extends TestCase
{
    public function testFinalIsPresentedAndResetsClock(): void
    {
        $c = ResultStatusClassifier::classify('final');

        self::assertTrue($c->presented);
        self::assertTrue($c->resetsClock);
        self::assertFalse($c->inFlight);
        self::assertSame(FactStatus::Final, $c->factStatus);
    }

    public function testCorrectedIsPresentedAndResetsClock(): void
    {
        $c = ResultStatusClassifier::classify('corrected');

        self::assertTrue($c->presented);
        self::assertTrue($c->resetsClock);
        self::assertSame(FactStatus::Corrected, $c->factStatus);
    }

    public function testEmptyStringIsPresentedAsUnstatedAndResetsClock(): void
    {
        $c = ResultStatusClassifier::classify('');

        self::assertTrue($c->presented);
        self::assertTrue($c->resetsClock);
        self::assertSame(FactStatus::Unstated, $c->factStatus);
    }

    /**
     * The core preliminary-vs-clock-reset failure mode (T9/T10): a
     * preliminary result is presented (in the in-flight section) but must
     * NEVER reset the overdue clock -- only a final/corrected/unstated
     * result proves the monitoring interval was actually satisfied.
     */
    public function testPreliminaryIsPresentedInFlightButDoesNotResetClock(): void
    {
        $c = ResultStatusClassifier::classify('preliminary');

        self::assertTrue($c->presented);
        self::assertTrue($c->inFlight);
        self::assertFalse($c->resetsClock);
        self::assertSame(FactStatus::Preliminary, $c->factStatus);
    }

    /**
     * @return iterable<string, array{0: string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function unperformedStatuses(): iterable
    {
        yield 'cannot be done' => ['cannot be done'];
        yield 'incomplete' => ['incomplete'];
        yield 'error' => ['error'];
        yield 'pending' => ['pending'];
        yield 'canceled' => ['canceled'];
    }

    #[DataProvider('unperformedStatuses')]
    public function testUnperformedStatusesAreExcludedAndNeverResetTheClock(string $status): void
    {
        $c = ResultStatusClassifier::classify($status);

        self::assertFalse($c->presented);
        self::assertFalse($c->resetsClock);
        self::assertSame(ExclusionReason::UnresultedStatus, $c->exclusionReason);
    }

    public function testUnrecognizedStatusIsExcludedAndFlaggedDistinctlyFromUnperformed(): void
    {
        $c = ResultStatusClassifier::classify('amended');

        self::assertFalse($c->presented);
        self::assertFalse($c->resetsClock);
        self::assertSame(ExclusionReason::UnrecognizedStatus, $c->exclusionReason);
    }
}
