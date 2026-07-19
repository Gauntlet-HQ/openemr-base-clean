<?php

/**
 * CadenceConfigStore::isLoadTestActive — the temporary cap-lift auto-reverts.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Observability;

use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceConfigStore;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: load-test mode (which lifts the per-user chat caps for a
 * burst test) being left ON indefinitely and quietly disabling the throttle. The
 * lift MUST auto-revert purely on the clock — active only while enabled AND the
 * window has not expired — so a forgotten toggle can never keep the caps off.
 */
final class LoadTestModeTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-07-14 12:00:00');
    }

    private function at(string $offset): string
    {
        return $this->now->modify($offset)->format(DATE_ATOM);
    }

    public function testActiveWhileEnabledAndInsideTheWindow(): void
    {
        self::assertTrue(CadenceConfigStore::isLoadTestActive(
            ['active' => true, 'expires_at' => $this->at('+30 minutes')],
            $this->now,
        ));
    }

    public function testAutoRevertsOnceTheWindowHasExpired(): void
    {
        self::assertFalse(
            CadenceConfigStore::isLoadTestActive(
                ['active' => true, 'expires_at' => $this->at('-1 minute')],
                $this->now,
            ),
            'an expired window must read as OFF with no manual reset or cron',
        );
    }

    public function testOffWhenDisabledEvenWithAFutureExpiry(): void
    {
        self::assertFalse(CadenceConfigStore::isLoadTestActive(
            ['active' => false, 'expires_at' => $this->at('+30 minutes')],
            $this->now,
        ));
    }

    public function testOffWhenExpiryMissingOrGarbageOrEmpty(): void
    {
        self::assertFalse(CadenceConfigStore::isLoadTestActive(['active' => true], $this->now));
        self::assertFalse(CadenceConfigStore::isLoadTestActive(['active' => true, 'expires_at' => 'not-a-date'], $this->now));
        self::assertFalse(CadenceConfigStore::isLoadTestActive([], $this->now));
    }
}
