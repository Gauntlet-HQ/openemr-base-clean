<?php

/**
 * Guards: a tool call that fails is reported to the model AND recorded, never silently absorbed.
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
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §1.3: "a tool failure is reported to the model AND the
 * user ('vitals lookup failed -- answering from labs and meds only'), never
 * silently absorbed." This test forces {@see \OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolExecutorInterface}
 * to fail on the first requested tool and asserts three things: (1) the
 * failure is recorded on {@see \OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoopResult::$toolCallLog}
 * with its exact reason (what the user-facing banner is built from), (2)
 * the NEXT round's prompt contains that same failure text (what the model
 * sees, so it can say so in its answer), and (3) the loop does not abort --
 * it continues to a normal final answer using whatever else it has.
 */
final class ToolFailureSurfacesTest extends TestCase
{
    public function testFailedToolCallSurfacesToBothTheNextRoundPromptAndTheCallLog(): void
    {
        $llm = QueuedChatLlmClient::up([
            ChatTestFactory::toolCallResponse('get_vitals_trend', ['metric' => 'weight', 'window_months' => 6]),
            ChatTestFactory::finalAnswerResponse([
                ['text' => 'Vitals lookup failed -- answering from labs and meds only.', 'claim_type' => ClaimType::UncertaintyStatement->value, 'citation_ids' => [], 'numeric_values' => [], 'flags' => [], 'order' => 0, 'emphasis' => null],
            ]),
        ]);

        $tools = new StubToolExecutor();
        $tools->enqueue('get_vitals_trend', ToolCallOutcome::failed('get_vitals_trend', 'capability threw during extraction: simulated data-shape surprise'));

        $loop = ChatTestFactory::agentLoop($llm, $tools);
        $result = $loop->run([], null, [], 'Did her weight change?');

        self::assertCount(1, $result->toolCallLog);
        self::assertFalse($result->toolCallLog[0]->outcome->ok);
        self::assertStringContainsString('simulated data-shape surprise', (string)$result->toolCallLog[0]->outcome->errorMessage);

        $secondRoundRequest = $llm->calls()[1];
        self::assertStringContainsString('FAILED', $secondRoundRequest->prompt->userContent);
        self::assertStringContainsString('get_vitals_trend', $secondRoundRequest->prompt->userContent);

        self::assertFalse($result->budgetExhausted, 'a single tool failure must not itself exhaust the turn');
    }
}
