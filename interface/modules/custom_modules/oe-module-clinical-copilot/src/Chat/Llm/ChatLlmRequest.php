<?php

/**
 * One round's request to a tool-calling ChatLlmClientInterface implementation.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Llm;

use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolDefinition;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;

/**
 * Deliberately WRAPS a {@see PromptRequest} rather than re-declaring
 * `systemInstructions`/`userContent`/`responseSchema`/`model` a second time:
 * this is what lets {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor::redactPrompt()}
 * (U7, ARCHITECTURE.md §4 egress redaction) run unmodified over every chat
 * round's prompt, not only the synthesis path's -- direct identifiers are
 * tokenized before EVERY Vertex call a chat turn makes, tool-decision rounds
 * included, never only the final answer.
 *
 * `tools` is empty on the one round that follows a fail-closed retry (the
 * agent loop asks the model to resolve the verifier's findings using
 * already-retrieved facts only -- no new tool access on that round, keeping
 * the one-retry contract identical in shape to U10's synthesis retry).
 */
final readonly class ChatLlmRequest
{
    /**
     * @param list<ToolDefinition> $tools
     */
    public function __construct(
        public PromptRequest $prompt,
        public array $tools,
    ) {
    }

    /**
     * Rebuilds this request around a (possibly redacted) PromptRequest,
     * keeping the same tool declarations -- used by the agent loop after
     * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor::redactPrompt()}
     * hands back a new inner request.
     */
    public function withPrompt(PromptRequest $prompt): self
    {
        return new self($prompt, $this->tools);
    }
}
