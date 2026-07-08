<?php

/**
 * Wires the ChatLlmClientInterface implementation for the chat composition root.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Llm;

use OpenEMR\Modules\ClinicalCopilot\Config\LlmEnv;

/**
 * Mirrors {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory}
 * exactly, reusing the SAME environment variables (one GCP project, one
 * chat surface -- there is no reason chat and synthesis would ever target
 * different Vertex deployments) rather than minting a parallel config
 * surface. Empty/unset `CLINICAL_COPILOT_GCP_PROJECT_ID` is the honest
 * dev/test default and MUST degrade through {@see UnavailableChatLlmClient}.
 *
 * T23 (build-notes.md "dev/test Gemini API-key fast-path"): same three-way
 * precedence as the reduce path's factory, production first --
 *
 *   1. `CLINICAL_COPILOT_GCP_PROJECT_ID` (+ `..._GCP_LOCATION`) set =>
 *      {@see VertexChatLlmClient} (production, ADC, HIPAA-eligible).
 *   2. else `CLINICAL_COPILOT_GEMINI_API_KEY` set =>
 *      {@see GeminiApiChatLlmClient} (dev/test only, synthetic data, no BAA
 *      -- see `docs/configuration.md`).
 *   3. else => {@see UnavailableChatLlmClient} (the default in this
 *      environment; chat degrades to a facts browser, I6/I11).
 *
 * Vertex always wins when both are configured.
 */
final class ChatLlmClientFactory
{
    private function __construct()
    {
        // static-only
    }

    public static function create(): ChatLlmClientInterface
    {
        $projectId = LlmEnv::gcpProjectId();
        if ($projectId !== '') {
            return new VertexChatLlmClient($projectId, LlmEnv::gcpLocation());
        }

        $apiKey = LlmEnv::geminiApiKey();
        if ($apiKey !== '') {
            return new GeminiApiChatLlmClient($apiKey);
        }

        return new UnavailableChatLlmClient();
    }
}
