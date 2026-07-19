<?php

/**
 * TelemetryRetention: the retention-horizon parsing and cutoff computation.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Observability;

use OpenEMR\Modules\ClinicalCopilot\Observability\TelemetryRetention;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a mis-set retention env turning the cron prune into
 * "delete everything just written" (a 0/negative horizon), or the default
 * drifting off the documented 3 days. The DELETE itself is DB-backed and
 * covered by tests/Db/Observability/TelemetryRetentionDbTest.php; here we pin
 * the pure horizon/cutoff logic with no database.
 */
final class TelemetryRetentionTest extends TestCase
{
    private string|false $priorEnv;

    protected function setUp(): void
    {
        $this->priorEnv = getenv(TelemetryRetention::ENV_RETENTION_DAYS);
        putenv(TelemetryRetention::ENV_RETENTION_DAYS);
        unset($_SERVER[TelemetryRetention::ENV_RETENTION_DAYS], $_ENV[TelemetryRetention::ENV_RETENTION_DAYS]);
    }

    protected function tearDown(): void
    {
        if ($this->priorEnv === false) {
            putenv(TelemetryRetention::ENV_RETENTION_DAYS);
        } else {
            putenv(TelemetryRetention::ENV_RETENTION_DAYS . '=' . $this->priorEnv);
        }
    }

    public function testDefaultsToThreeDaysWhenUnset(): void
    {
        self::assertSame(3, TelemetryRetention::DEFAULT_RETENTION_DAYS);
        self::assertSame(3, TelemetryRetention::retentionDays());
    }

    public function testReadsAWholeDayOverrideFromEnv(): void
    {
        putenv(TelemetryRetention::ENV_RETENTION_DAYS . '=7');
        self::assertSame(7, TelemetryRetention::retentionDays());
    }

    /**
     * The load-bearing safety clamp: a 0/negative/blank/garbage horizon must
     * never delete freshly-written telemetry. 0 clamps to 1; non-digits fall
     * back to the default.
     */
    public function testNeverDropsBelowOneDay(): void
    {
        putenv(TelemetryRetention::ENV_RETENTION_DAYS . '=0');
        self::assertSame(1, TelemetryRetention::retentionDays());
    }

    public function testGarbageOrNegativeFallsBackToDefault(): void
    {
        putenv(TelemetryRetention::ENV_RETENTION_DAYS . '=abc');
        self::assertSame(3, TelemetryRetention::retentionDays());

        // A leading '-' is not ctype_digit, so it is treated as unset (default),
        // never as a negative window.
        putenv(TelemetryRetention::ENV_RETENTION_DAYS . '=-5');
        self::assertSame(3, TelemetryRetention::retentionDays());
    }

    public function testCutoffSubtractsTheHorizonFromNow(): void
    {
        $now = new \DateTimeImmutable('2026-07-14 12:00:00');

        self::assertSame(
            '2026-07-11 12:00:00',
            TelemetryRetention::cutoff($now, 3)->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            '2026-07-07 12:00:00',
            TelemetryRetention::cutoff($now, 7)->format('Y-m-d H:i:s'),
        );
    }

    public function testCutoffHonoursTheEnvHorizonWhenNotOverridden(): void
    {
        putenv(TelemetryRetention::ENV_RETENTION_DAYS . '=2');
        $now = new \DateTimeImmutable('2026-07-14 00:00:00');

        self::assertSame(
            '2026-07-12 00:00:00',
            TelemetryRetention::cutoff($now)->format('Y-m-d H:i:s'),
        );
    }

    public function testCutoffClampsAZeroOverrideToOneDay(): void
    {
        $now = new \DateTimeImmutable('2026-07-14 12:00:00');

        self::assertSame(
            '2026-07-13 12:00:00',
            TelemetryRetention::cutoff($now, 0)->format('Y-m-d H:i:s'),
        );
    }
}
