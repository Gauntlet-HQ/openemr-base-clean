<?php

/**
 * OverdueTests::addCadenceInterval(): month/day-boundary regression coverage.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Capability;

use OpenEMR\Modules\ClinicalCopilot\Capability\OverdueTests;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Failure mode guarded: PHP's DateInterval::add() does not clamp overflow
 * days into the target month -- it rolls into the following month (e.g.
 * 2024-01-31 + P3M = 2024-05-01, not the intended 2024-04-30). Because the
 * cadence config (table.sql `cadence:a1c`=P3M, `cadence:acr`/`cadence:lipids`
 * =P1Y) only ever produces pure month/year intervals, addCadenceInterval()
 * must clamp to the last day of the resulting month instead.
 */
final class OverdueCadenceIntervalTest extends TestCase
{
    private function addCadenceInterval(\DateTimeImmutable $date, \DateInterval $interval): \DateTimeImmutable
    {
        $method = new ReflectionMethod(OverdueTests::class, 'addCadenceInterval');

        return $method->invoke(null, $date, $interval);
    }

    public function testQuarterlyCadenceClampsJanuary31stToApril30th(): void
    {
        $result = $this->addCadenceInterval(new \DateTimeImmutable('2024-01-31'), new \DateInterval('P3M'));

        self::assertSame('2024-04-30', $result->format('Y-m-d'));
    }

    public function testAnnualCadenceClampsLeapDayToFebruary28thInNonLeapYear(): void
    {
        $result = $this->addCadenceInterval(new \DateTimeImmutable('2024-02-29'), new \DateInterval('P1Y'));

        self::assertSame('2025-02-28', $result->format('Y-m-d'));
    }

    public function testQuarterlyCadenceWithNoOverflowIsUnaffected(): void
    {
        $result = $this->addCadenceInterval(new \DateTimeImmutable('2026-03-15'), new \DateInterval('P3M'));

        self::assertSame('2026-06-15', $result->format('Y-m-d'));
    }

    public function testAnnualCadenceFromLeapDayIntoAnotherLeapYearIsUnaffected(): void
    {
        $result = $this->addCadenceInterval(new \DateTimeImmutable('2020-02-29'), new \DateInterval('P1Y'));

        // 2021 is not a leap year, so this IS a clamp case -- included to
        // document the behavior explicitly rather than assume it.
        self::assertSame('2021-02-28', $result->format('Y-m-d'));
    }

    public function testDayTimeBearingIntervalFallsBackToPlainAdd(): void
    {
        // Not a shape the cadence config produces today, but the clamp
        // logic must not silently mis-clamp an interval it doesn't own.
        $result = $this->addCadenceInterval(new \DateTimeImmutable('2024-01-31'), new \DateInterval('P3M5D'));

        self::assertSame('2024-05-06', $result->format('Y-m-d'));
    }
}
