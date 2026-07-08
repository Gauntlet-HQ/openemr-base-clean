<?php

/**
 * Guards: tool chaining where the second call's arguments depend on the first call's result.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat;

use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallOutcome;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ClaimType;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * USERS.md UC6's worked example: "did her weight change after the insulin
 * started?" requires `get_med_history` (find the start date) THEN
 * `get_vitals_trend` (weights since that date) -- two tool calls where the
 * second's arguments come from the first's result. This test drives exactly
 * that two-round shape through {@see \OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop}
 * and asserts: both tools were called (in order), both tools' facts landed
 * in the accumulated fact set the final answer can cite, and the loop used
 * exactly 2 of its 3-round / 5-call budget -- proving chaining works without
 * exhausting the budget on a two-hop question.
 */
final class ToolChainingKnownAnswerTest extends TestCase
{
    public function testMedDateThenVitalsWindowChains(): void
    {
        $medEvent = FactTestFactory::medEvent(ChatTestFactory::PINNED_PID, 5);
        $weightVital = FactTestFactory::a1cTrendPoint(ChatTestFactory::PINNED_PID, 9, '180', '2025-04-01');

        $llm = QueuedChatLlmClient::up([
            ChatTestFactory::toolCallResponse('get_med_history', ['window_months' => 12, 'drug_filter' => 'insulin']),
            ChatTestFactory::toolCallResponse('get_vitals_trend', ['metric' => 'weight', 'window_months' => 6]),
            ChatTestFactory::finalAnswerResponse([
                ['text' => 'Weight after the insulin start.', 'claim_type' => ClaimType::Vital->value, 'citation_ids' => [$weightVital->factId], 'numeric_values' => [180.0], 'flags' => [], 'order' => 0, 'emphasis' => null],
            ]),
        ]);

        $tools = new StubToolExecutor();
        $tools->enqueue('get_med_history', ToolCallOutcome::ok('get_med_history', [$medEvent]));
        $tools->enqueue('get_vitals_trend', ToolCallOutcome::ok('get_vitals_trend', [$weightVital]));

        $loop = ChatTestFactory::agentLoop($llm, $tools);
        $result = $loop->run([], null, [], 'Did her weight change after the insulin started?');

        self::assertCount(2, $result->toolCallLog, 'both tools in the chain must be recorded');
        self::assertSame('get_med_history', $result->toolCallLog[0]->request->name);
        self::assertSame('get_vitals_trend', $result->toolCallLog[1]->request->name);
        self::assertTrue($result->toolCallLog[0]->outcome->ok);
        self::assertTrue($result->toolCallLog[1]->outcome->ok);

        $factIds = array_map(static fn ($f) => $f->factId, $result->accumulatedFacts);
        self::assertContains($medEvent->factId, $factIds);
        self::assertContains($weightVital->factId, $factIds);
        self::assertFalse($result->budgetExhausted, 'a two-hop chain must not exhaust the budget');
        self::assertSame(3, $llm->callCount(), 'two tool-deciding rounds plus the final answering round');
    }
}
