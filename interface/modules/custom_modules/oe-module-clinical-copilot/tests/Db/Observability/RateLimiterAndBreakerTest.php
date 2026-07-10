<?php

/**
 * DB-backed U12 acceptance evals: per-user rate limits trip; the breaker opens on error/spend and honors manual force/reset.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Observability;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnRole;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\NewChatSession;
use OpenEMR\Modules\ClinicalCopilot\Chat\NewChatTurn;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceCircuitBreaker;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceConfigStore;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceRateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §3.7: "per user: max 3 active sessions; max 60 turns/hour"
 * (the config-driven half U12 owns; session-scoped limits are
 * {@see \OpenEMR\Modules\ClinicalCopilot\Controller\ChatController}'s own
 * concern). These evals assume the seeded `rate_limit_breaker` config row
 * from table.sql/install.sql is present with its documented defaults
 * (`max_active_sessions_per_user: 3`, `max_turns_per_user_per_hour: 60`,
 * `hourly_llm_burn_cap_usd: 10.0`, `breaker_error_threshold: 5`,
 * `breaker_window_seconds: 60`) -- a properly installed dev/test database
 * always has this row (`#IfNotRow` guard at install time).
 */
final class RateLimiterAndBreakerTest extends TestCase
{
    private const SYNTHETIC_PID = 999401;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    public function testTripsOnTooManyActiveSessionsForOneUser(): void
    {
        $userId = self::syntheticUserId();
        $sessionStore = new ChatSessionStore();

        for ($i = 0; $i < 4; $i++) {
            $sessionStore->insert(new NewChatSession(self::SYNTHETIC_PID, $userId, null, 'digest-' . $i));
        }

        $decision = (new CadenceRateLimiter())->checkTurn(self::SYNTHETIC_PID, $userId, 1);

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('active sessions', (string)$decision->reason);
    }

    public function testTripsOnTooManyTurnsPerHourForOneUser(): void
    {
        $userId = self::syntheticUserId();
        $sessionStore = new ChatSessionStore();
        $turnStore = new ChatTurnStore();

        $sessionId = $sessionStore->insert(new NewChatSession(self::SYNTHETIC_PID, $userId, null, 'digest-x'));

        for ($i = 0; $i < 61; $i++) {
            $turnStore->insert(new NewChatTurn(
                $sessionId,
                $i + 1,
                ChatTurnRole::User,
                ['text' => "question {$i}"],
                null,
                null,
                'ccp-rl-test-' . bin2hex(random_bytes(4)),
                null,
                null,
                null,
            ));
        }

        $decision = (new CadenceRateLimiter())->checkTurn(self::SYNTHETIC_PID, $userId, $sessionId);

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('hourly turn limit', (string)$decision->reason);
    }

    public function testAllowsAWellBehavedUser(): void
    {
        $userId = self::syntheticUserId();
        $sessionStore = new ChatSessionStore();
        $sessionId = $sessionStore->insert(new NewChatSession(self::SYNTHETIC_PID, $userId, null, 'digest-y'));

        $decision = (new CadenceRateLimiter())->checkTurn(self::SYNTHETIC_PID, $userId, $sessionId);

        self::assertTrue($decision->allowed);
    }

    public function testBreakerOpensOnErrorRateWithinWindow(): void
    {
        $threshold = (new CadenceConfigStore())->limits()['breaker_error_threshold'];

        for ($i = 0; $i < $threshold; $i++) {
            $this->insertTraceRow('error', 0.0);
        }

        $snapshot = (new CadenceCircuitBreaker())->snapshot();

        self::assertTrue($snapshot['open']);
        self::assertStringContainsString('error rate', (string)$snapshot['reason']);
    }

    public function testBreakerOpensOnHourlyBurnCap(): void
    {
        $cap = (new CadenceConfigStore())->limits()['hourly_llm_burn_cap_usd'];

        $this->insertTraceRow('ok', $cap + 1.0);

        $snapshot = (new CadenceCircuitBreaker())->snapshot();

        self::assertTrue($snapshot['open']);
        self::assertStringContainsString('burn', (string)$snapshot['reason']);
    }

    public function testBreakerClosedWithNoAdverseSignal(): void
    {
        $snapshot = (new CadenceCircuitBreaker())->snapshot();

        self::assertFalse($snapshot['open']);
        self::assertNull($snapshot['reason']);
    }

    public function testManualForceOpenAndResetOverrideAutomaticState(): void
    {
        $configStore = new CadenceConfigStore();
        $breaker = new CadenceCircuitBreaker($configStore);

        self::assertFalse($breaker->isOpen());

        $configStore->forceOpen('unit-test-admin', 'testing manual trip');
        self::assertTrue($breaker->isOpen());

        $configStore->manualReset('unit-test-admin');
        self::assertFalse($breaker->isOpen());
    }

    private function insertTraceRow(string $status, float $costUsd): void
    {
        QueryUtils::sqlInsert(
            'INSERT INTO `mod_copilot_trace` (`correlation_id`, `span_id`, `kind`, `started_at`, `status`, `cost_usd`, `pid`)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                'ccp-breaker-test-' . bin2hex(random_bytes(8)),
                bin2hex(random_bytes(8)),
                'llm_reduce',
                (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                $status,
                $costUsd,
                self::SYNTHETIC_PID,
            ],
        );
    }

    private static function syntheticUserId(): int
    {
        return random_int(900001, 999999);
    }
}
