<?php

/**
 * Shared fixture-building helpers for the U11 isolated Chat test suite.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat;

use OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatAgent;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatPromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallRequest;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolExecutorInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptContext;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;

/**
 * Not a TestCase -- mirrors `tests/Isolated/Verify/VerifyTestFactory.php`'s
 * role for this suite: one place every Chat isolated test builds its
 * {@see AgentLoop}/{@see ChatAgent} from, so a reader can trust the wiring
 * is identical across tests and only the stubs differ.
 */
final class ChatTestFactory
{
    public const PINNED_PID = 42;

    private function __construct()
    {
        // static-only
    }

    public static function identifiers(): PatientIdentifiers
    {
        return new PatientIdentifiers('Test Patient', 'PID-42', '1970-01-01', '123 Main St');
    }

    public static function promptContext(): PromptContext
    {
        return new PromptContext('endo-previsit-chat-v1', 'chat-v1', 'gemini-2.5-pro');
    }

    public static function agentLoop(
        ChatLlmClientInterface $llmClient,
        ToolExecutorInterface $toolExecutor,
        ?\Closure $onStatus = null,
    ): AgentLoop {
        return new AgentLoop(
            $llmClient,
            $toolExecutor,
            new ChatPromptAssembler(),
            new Redactor(),
            'chat:test-session',
            self::identifiers(),
            self::promptContext(),
            $onStatus,
            // Tests exercise the (production-dormant) tool loop -- keep it on
            // here so the dedup/budget/tool-failure coverage still runs.
            toolsEnabled: true,
        );
    }

    public static function chatAgent(
        ChatLlmClientInterface $llmClient,
        ToolExecutorInterface $toolExecutor,
        ?\Closure $onStatus = null,
    ): ChatAgent {
        return new ChatAgent(self::agentLoop($llmClient, $toolExecutor, $onStatus), new Verifier(), $onStatus);
    }

    /**
     * A round response that requests one tool call.
     *
     * @param array<string, mixed> $arguments
     */
    public static function toolCallResponse(string $toolName, array $arguments, string $model = 'gemini-2.5-pro'): ChatLlmResponse
    {
        return ChatLlmResponse::toolCalls([new ToolCallRequest($toolName, $arguments)], $model, 100, 20, 50);
    }

    /**
     * A round response that answers directly (no further tool calls).
     *
     * @param list<array<string, mixed>> $claims
     */
    public static function finalAnswerResponse(array $claims, string $model = 'gemini-2.5-pro'): ChatLlmResponse
    {
        return ChatLlmResponse::finalAnswer((string)json_encode($claims, JSON_THROW_ON_ERROR), $model, 100, 40, 60);
    }
}
