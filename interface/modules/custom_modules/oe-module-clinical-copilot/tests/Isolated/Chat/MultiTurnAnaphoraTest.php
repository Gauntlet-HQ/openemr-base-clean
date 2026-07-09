<?php

/**
 * Guards: a follow-up turn's prompt must carry the prior turn's Q&A verbatim, or anaphora is unparseable.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat;

use OpenEMR\Modules\ClinicalCopilot\Reduce\ClaimType;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * USERS.md UC6: "her real follow-ups are anaphoric -- 'and the one before
 * that?', 'same for lipids' -- questions that are literally unparseable
 * without the conversation that precedes them." This is the mechanism that
 * makes that possible: {@see \OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop}
 * is handed the PRIOR turn's rendered transcript on every subsequent call
 * (ARCHITECTURE.md §1.1), and {@see \OpenEMR\Modules\ClinicalCopilot\Chat\ChatPromptAssembler}
 * places it verbatim in the round's prompt. A stub model cannot itself
 * "resolve" an anaphoric reference, so this test asserts the one thing that
 * IS this module's responsibility: the second turn's request byte-for-byte
 * contains the first turn's question and answer, not a summary or nothing.
 */
final class MultiTurnAnaphoraTest extends TestCase
{
    public function testFollowUpTurnPromptCarriesPriorTurnVerbatim(): void
    {
        $a1c = FactTestFactory::a1cTrendPoint(ChatTestFactory::PINNED_PID, 1, '7.2', '2025-01-10');
        $firstAnswerText = 'Her most recent A1c is 7.2%.';

        $llm = QueuedChatLlmClient::up([
            ChatTestFactory::finalAnswerResponse([
                ['text' => $firstAnswerText, 'claim_type' => ClaimType::LabValue->value, 'citation_ids' => [$a1c->factId], 'numeric_values' => [7.2], 'flags' => [], 'order' => 0, 'emphasis' => null],
            ]),
            ChatTestFactory::finalAnswerResponse([
                ['text' => 'irrelevant for this test', 'claim_type' => ClaimType::UncertaintyStatement->value, 'citation_ids' => [], 'numeric_values' => [], 'flags' => [], 'order' => 0, 'emphasis' => null],
            ]),
        ]);
        $tools = new StubToolExecutor();
        $loop = ChatTestFactory::agentLoop($llm, $tools);

        $firstResult = $loop->run([$a1c], null, [], 'What is her latest A1c?');

        $transcript = [
            'Physician: What is her latest A1c?',
            'Assistant: ' . $firstAnswerText,
        ];
        $loop->run($firstResult->accumulatedFacts, null, $transcript, 'And the one before that?');

        $secondRequest = $llm->calls()[1];
        self::assertStringContainsString('What is her latest A1c?', $secondRequest->prompt->userContent);
        self::assertStringContainsString($firstAnswerText, $secondRequest->prompt->userContent);
        self::assertStringContainsString('And the one before that?', $secondRequest->prompt->userContent);
    }
}
