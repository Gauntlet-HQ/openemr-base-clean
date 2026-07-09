<?php

/**
 * The one seam between the chat agent loop and any concrete tool-calling LLM provider.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Llm;

use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;

/**
 * Extends the reduce path's seam ({@see \OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface})
 * with native function-calling (T18: "native function calling for the five
 * tools") rather than reusing its single `generateStructured()` method
 * directly: a chat round can produce EITHER a batch of tool-call requests OR
 * a final claims answer, whereas the reduce path's one-shot
 * `generateStructured()` only ever produces the latter. {@see \OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop}
 * depends on this interface, never on a concrete provider client --
 * {@see UnavailableChatLlmClient} is the default (no ADC configured, the
 * honest dev/test state) and every isolated/DB chat test binds a
 * hand-written stub instead (build-notes.md: "No live LLM calls anywhere in
 * tests").
 */
interface ChatLlmClientInterface
{
    /**
     * @throws LlmUnavailableException when credentials cannot be resolved or
     *         the provider endpoint cannot be reached -- the default outcome
     *         in dev/test, exactly {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface::generateStructured()}'s
     *         contract, reused verbatim so the agent loop's degradation
     *         handling (I6/I11: LLM unavailable => chat becomes a facts
     *         browser) needs only one catch clause.
     */
    public function converse(ChatLlmRequest $req): ChatLlmResponse;
}
