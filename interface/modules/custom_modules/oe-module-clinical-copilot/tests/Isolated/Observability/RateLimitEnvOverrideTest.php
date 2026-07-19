<?php

/**
 * CadenceConfigStore::resolveLimits — env overrides for the operator-facing caps.
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
 * Failure mode guarded: a deployment unable to change its cost/abuse caps
 * without editing a seeded DB row (they were hard-coded defaults), or a
 * mis-set env var silently DISABLING a cap (0/negative/garbage). Precedence is
 * env > DB config row > default, and any non-positive/garbage env value falls
 * back rather than removing the cap.
 */
final class RateLimitEnvOverrideTest extends TestCase
{
    private const ENVS = [
        'CLINICAL_COPILOT_MAX_ACTIVE_SESSIONS_PER_USER',
        'CLINICAL_COPILOT_MAX_TURNS_PER_USER_PER_HOUR',
        'CLINICAL_COPILOT_DAILY_LLM_SPEND_CAP_USD',
        'CLINICAL_COPILOT_HOURLY_LLM_BURN_CAP_USD',
    ];

    /** @var array<string, string|false> */
    private array $prior = [];

    protected function setUp(): void
    {
        foreach (self::ENVS as $e) {
            $this->prior[$e] = getenv($e);
            putenv($e);
            unset($_SERVER[$e], $_ENV[$e]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->prior as $e => $v) {
            $v === false ? putenv($e) : putenv($e . '=' . $v);
        }
    }

    public function testDefaultsWhenNoEnvAndNoDbConfig(): void
    {
        $limits = CadenceConfigStore::resolveLimits([]);

        self::assertSame(3, $limits['max_active_sessions_per_user']);
        self::assertSame(60, $limits['max_turns_per_user_per_hour']);
        self::assertSame(50.0, $limits['daily_llm_spend_cap_usd']);
        self::assertSame(10.0, $limits['hourly_llm_burn_cap_usd']);
    }

    public function testDbConfigRowIsUsedWhenNoEnvOverride(): void
    {
        $limits = CadenceConfigStore::resolveLimits([
            'max_active_sessions_per_user' => 10,
            'daily_llm_spend_cap_usd' => 75.0,
        ]);

        self::assertSame(10, $limits['max_active_sessions_per_user']);
        self::assertSame(75.0, $limits['daily_llm_spend_cap_usd']);
    }

    public function testEnvOverridesBothDefaultAndDbConfig(): void
    {
        putenv('CLINICAL_COPILOT_MAX_ACTIVE_SESSIONS_PER_USER=25');
        putenv('CLINICAL_COPILOT_MAX_TURNS_PER_USER_PER_HOUR=120');
        putenv('CLINICAL_COPILOT_DAILY_LLM_SPEND_CAP_USD=200.5');
        putenv('CLINICAL_COPILOT_HOURLY_LLM_BURN_CAP_USD=25');

        $limits = CadenceConfigStore::resolveLimits(['max_active_sessions_per_user' => 10]);

        self::assertSame(25, $limits['max_active_sessions_per_user']);
        self::assertSame(120, $limits['max_turns_per_user_per_hour']);
        self::assertSame(200.5, $limits['daily_llm_spend_cap_usd']);
        self::assertSame(25.0, $limits['hourly_llm_burn_cap_usd']);
    }

    public function testGarbageZeroOrNegativeEnvFallsBackNeverDisablesACap(): void
    {
        putenv('CLINICAL_COPILOT_MAX_ACTIVE_SESSIONS_PER_USER=0');    // would disable → fallback
        putenv('CLINICAL_COPILOT_MAX_TURNS_PER_USER_PER_HOUR=-5');    // negative → fallback
        putenv('CLINICAL_COPILOT_DAILY_LLM_SPEND_CAP_USD=abc');       // garbage → fallback
        putenv('CLINICAL_COPILOT_HOURLY_LLM_BURN_CAP_USD=0');         // zero → fallback

        $limits = CadenceConfigStore::resolveLimits([
            'max_active_sessions_per_user' => 10,
            'max_turns_per_user_per_hour' => 60,
        ]);

        self::assertSame(10, $limits['max_active_sessions_per_user'], 'falls back to DB config, not disabled');
        self::assertSame(60, $limits['max_turns_per_user_per_hour']);
        self::assertSame(50.0, $limits['daily_llm_spend_cap_usd'], 'falls back to default');
        self::assertSame(10.0, $limits['hourly_llm_burn_cap_usd']);
    }
}
