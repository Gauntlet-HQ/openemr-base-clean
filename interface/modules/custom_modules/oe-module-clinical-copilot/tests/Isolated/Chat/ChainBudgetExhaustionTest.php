<?php

/**
 * Guards: hitting the tool-chaining budget degrades transparently, never silently or with an error.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat;

use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ChainBudget;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallOutcome;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ClaimType;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory;
use PHPUnit\Framework\TestCase;
use OpenEMR\Modules\ClinicalCopilot\Verify\SessionFactSet;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationContext;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPath;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;

/**
 * ARCHITECTURE.md §1.2: "Bounded chaining: max 5 tool calls per turn, max 3
 * rounds ... hitting the budget degrades transparently ('I retrieved X and
 * Y; I did not retrieve Z -- ask again to continue')." A model that NEVER
 * stops requesting tools (every round returns another tool call) must not
 * loop forever, must not throw, and must not silently return nothing --
 * {@see \OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop} synthesizes exactly
 * that transparent message itself once the round budget is spent.
 */
final class ChainBudgetExhaustionTest extends TestCase
{
    public function testExhaustingTheRoundBudgetProducesATransparentDegradation(): void
    {
        $fact = FactTestFactory::a1cTrendPoint(ChatTestFactory::PINNED_PID, 1, '7.2', '2025-01-10');

        // A pathological model that always asks for another tool call --
        // never settles on a final answer. Only ChainBudget::MAX_ROUNDS
        // rounds are ever requested from the queue (proving the loop stops
        // itself rather than the stub running out first).
        $llm = QueuedChatLlmClient::up(array_fill(
            0,
            ChainBudget::MAX_ROUNDS,
            ChatTestFactory::toolCallResponse('get_overdue', []),
        ));

        $tools = new StubToolExecutor();
        for ($i = 0; $i < ChainBudget::MAX_ROUNDS; $i++) {
            $tools->enqueue('get_overdue', ToolCallOutcome::ok('get_overdue', [$fact]));
        }

        $loop = ChatTestFactory::agentLoop($llm, $tools);
        $result = $loop->run([], null, [], 'Is anything overdue?');

        self::assertTrue($result->budgetExhausted);
        self::assertSame(ChainBudget::MAX_ROUNDS, $llm->callCount(), 'the loop must stop itself at MAX_ROUNDS, never loop past it');

        $decoded = json_decode($result->finalClaimsJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $decoded);
        self::assertSame(ClaimType::UncertaintyStatement->value, $decoded[0]['claim_type']);
        self::assertSame([], $decoded[0]['citation_ids'], 'the degradation message asserts no clinical content, so it is legally zero-citation');
        self::assertStringContainsString('Ask again to continue', $decoded[0]['text']);

        // The synthesized message must itself pass the SAME verifier gate
        // every other candidate answer passes through (ChatAgent reuses
        // Verifier unconditionally) -- never a special-cased bypass.
        $verification = (new Verifier())->verify(
            $result->finalClaimsJson,
            new VerificationContext(new SessionFactSet(ChatTestFactory::PINNED_PID, $result->accumulatedFacts), VerificationPath::Chat),
        );
        self::assertTrue($verification->allPassed(), 'the budget-exhaustion message must itself be a verifiable, citation-honest claim');
    }
}
