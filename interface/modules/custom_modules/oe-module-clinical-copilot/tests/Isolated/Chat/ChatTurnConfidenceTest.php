<?php

/**
 * ChatTurnConfidence: the deterministic confidence derived from a turn's verifier outcome.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat;

use OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoopResult;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatAnswer;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnConfidence;
use OpenEMR\Modules\ClinicalCopilot\Reduce\RedactionMap;
use OpenEMR\Modules\ClinicalCopilot\Verify\ReduceUsage;
use OpenEMR\Modules\ClinicalCopilot\Verify\Sev1Signal;
use PHPUnit\Framework\TestCase;

final class ChatTurnConfidenceTest extends TestCase
{
    public function testVerifiedOnFirstAttemptIsHigh(): void
    {
        $c = ChatTurnConfidence::fromAnswer(ChatAnswer::passed(self::loop(), [], [], ReduceUsage::none(), 1));
        self::assertSame('high', $c->label);
        self::assertSame(1.0, $c->score);
    }

    public function testVerifiedOnlyAfterRetryIsMedium(): void
    {
        $c = ChatTurnConfidence::fromAnswer(ChatAnswer::passed(self::loop(), [], [], ReduceUsage::none(), 2));
        self::assertSame('medium', $c->label);
        self::assertSame(0.7, $c->score);
    }

    public function testVerificationFailedDegradeIsLow(): void
    {
        $c = ChatTurnConfidence::fromAnswer(ChatAnswer::degradedVerificationFailed(self::loop(), [], ReduceUsage::none(), 2));
        self::assertSame('low', $c->label);
        self::assertSame(0.2, $c->score);
    }

    public function testLlmUnavailableAndBreakerOpenAreUnavailable(): void
    {
        $unavailable = ChatTurnConfidence::fromAnswer(ChatAnswer::degradedLlmUnavailable([], 'unreachable'));
        self::assertSame('unavailable', $unavailable->label);
        self::assertSame(0.0, $unavailable->score);

        $breaker = ChatTurnConfidence::fromAnswer(ChatAnswer::degradedBreakerOpen([]));
        self::assertSame('unavailable', $breaker->label);
        self::assertSame(0.0, $breaker->score);
    }

    public function testFrozenSev1IsBlocked(): void
    {
        $signal = new Sev1Signal('corr-1', 42, [], new \DateTimeImmutable('2026-07-08 08:50:00'));
        $c = ChatTurnConfidence::fromAnswer(ChatAnswer::frozen(self::loop(), [], $signal, ReduceUsage::none(), 1));
        self::assertSame('blocked', $c->label);
        self::assertSame(0.0, $c->score);
    }

    private static function loop(): AgentLoopResult
    {
        return new AgentLoopResult(
            '[]',
            new RedactionMap('session-1', [], []),
            [],
            [],
            0,
            0,
            0,
            'gemini-2.5-pro',
            false,
        );
    }
}
