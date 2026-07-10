<?php

/**
 * Guards: LLM unavailable => chat degrades to a facts browser (I6/I11), never an error or a hang.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat;

use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §1.3/I6: "LLM unavailable => the chat degrades to a facts
 * browser (the capabilities still run; prose is unavailable)." This uses
 * the SAME {@see \OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmClientInterface}
 * seam every production wiring depends on -- {@see QueuedChatLlmClient::down()}
 * throws {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException}
 * on the very first call, exactly {@see \OpenEMR\Modules\ClinicalCopilot\Chat\Llm\UnavailableChatLlmClient}'s
 * real behavior when no ADC is configured (the honest dev/test default,
 * build-notes.md).
 */
final class LlmUnavailableFactsBrowserTest extends TestCase
{
    public function testLlmUnavailableDegradesToFactsBrowser(): void
    {
        $preloadedFact = FactTestFactory::a1cTrendPoint(ChatTestFactory::PINNED_PID, 1, '7.2', '2025-01-10');

        $agent = ChatTestFactory::chatAgent(QueuedChatLlmClient::down(), new StubToolExecutor());
        $answer = $agent->answer(ChatTestFactory::PINNED_PID, 'corr-llm-down', [$preloadedFact], null, [], 'What is her A1c?');

        self::assertSame(VerifyStatus::Degraded, $answer->verifyStatus);
        self::assertNull($answer->claims, 'no prose may be rendered when the model could not be reached (I11)');
        self::assertSame('llm_unavailable', $answer->degradedReason);
        self::assertFalse($answer->frozen);

        // The facts browser IS the accumulated fact set -- the preloaded
        // fact must still be present and citable/visible even though prose
        // is unavailable.
        $factIds = array_map(static fn ($f) => $f->factId, $answer->accumulatedFacts);
        self::assertContains($preloadedFact->factId, $factIds);
    }
}
