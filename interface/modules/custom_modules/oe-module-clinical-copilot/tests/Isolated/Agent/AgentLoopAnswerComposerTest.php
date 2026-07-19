<?php

/**
 * The production answer composer: one AgentLoop run becomes a critic-ready draft, or an honest null.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Agent;

use OpenEMR\Modules\ClinicalCopilot\Agent\AgentLoopAnswerComposer;
use OpenEMR\Modules\ClinicalCopilot\Agent\AgentRequest;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat\ChatTestFactory;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat\QueuedChatLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat\StubToolExecutor;
use PHPUnit\Framework\TestCase;

/**
 * Failure modes guarded: the composer inventing a draft for a request with
 * no question (the supervisor would then run the critic over noise); an LLM
 * outage crashing the whole supervisor run instead of degrading to a
 * no-answer result (I6) -- with the machine reason lost; the composed draft
 * carrying a DIFFERENT fact set than the loop actually accumulated (the
 * critic would then resolve citations against facts the model never saw, a
 * grounding lie in either direction); and the redaction map of the run not
 * being retrievable, which would surface raw pseudonym tokens to the
 * clinician.
 */
final class AgentLoopAnswerComposerTest extends TestCase
{
    private static function request(?string $question): AgentRequest
    {
        return new AgentRequest(ChatTestFactory::PINNED_PID, 'corr-composer-test', question: $question, tags: ['a1c']);
    }

    public function testComposesTheLoopsClaimsJsonAsTheDraft(): void
    {
        $claims = [[
            'text' => 'No lab data is available for this question.',
            'claim_type' => 'uncertainty',
            'citation_ids' => [],
            'numeric_values' => [],
            'flags' => [],
            'order' => 0,
            'emphasis' => null,
        ]];
        $llm = QueuedChatLlmClient::up([ChatTestFactory::finalAnswerResponse($claims)]);
        $composer = new AgentLoopAnswerComposer(ChatTestFactory::agentLoop($llm, new StubToolExecutor()));

        $draft = $composer->compose(self::request('What was the last A1c?'), null, []);

        self::assertNotNull($draft);
        self::assertSame((string)json_encode($claims, JSON_THROW_ON_ERROR), $draft->rawClaimsJson);
        self::assertSame([], $draft->groundingFacts, 'no tool calls ran, so the grounding set is exactly the (empty) accumulated set');
        self::assertNotNull($composer->lastRedactionMap(), 'the run redacted its prompt; the map must be retrievable for rehydration');
        self::assertNull($composer->lastUnavailableReason());
    }

    public function testNoQuestionMeansNoDraftAndNoModelCall(): void
    {
        // An empty queue throws on ANY call -- passing proves the composer
        // never reached the model.
        $llm = QueuedChatLlmClient::up([]);
        $composer = new AgentLoopAnswerComposer(ChatTestFactory::agentLoop($llm, new StubToolExecutor()));

        self::assertNull($composer->compose(self::request(null), null, []));
        self::assertNull($composer->compose(self::request('   '), null, []));
        self::assertNull($composer->lastUnavailableReason());
    }

    public function testLlmOutageDegradesToNullWithTheMachineReason(): void
    {
        $composer = new AgentLoopAnswerComposer(ChatTestFactory::agentLoop(QueuedChatLlmClient::down(), new StubToolExecutor()));

        self::assertNull($composer->compose(self::request('What was the last A1c?'), null, []));
        self::assertSame('no_credentials', $composer->lastUnavailableReason());
        self::assertNull($composer->lastRedactionMap());
    }
}
