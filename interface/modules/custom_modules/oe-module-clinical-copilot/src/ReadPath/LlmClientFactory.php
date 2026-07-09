<?php

/**
 * Wires the LlmClientInterface implementation for the read path's composition root.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Config\LlmEnv;
use OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiApiLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\VertexLlmClient;

/**
 * T18 (build-notes.md "LLM platform"): project/location are deployment
 * config, never hardcoded and never an API key -- read from environment
 * variables (set in the site's Apache/PHP-FPM environment, never
 * committed). Empty/unset `CLINICAL_COPILOT_GCP_PROJECT_ID`
 * is the honest dev/test default (no GCP project here) and MUST degrade
 * through {@see UnavailableLlmClient}, never through
 * {@see VertexLlmClient}'s constructor guard (a plain
 * {@see \DomainException} on an empty project id -- the wrong shape of
 * failure for "unconfigured," see that class's docblock).
 *
 * T23 (build-notes.md "dev/test Gemini API-key fast-path"): three-way
 * precedence, checked in this order, production first --
 *
 *   1. `CLINICAL_COPILOT_GCP_PROJECT_ID` (+ `..._GCP_LOCATION`) set =>
 *      {@see VertexLlmClient} (production, ADC, HIPAA-eligible).
 *   2. else `CLINICAL_COPILOT_GEMINI_API_KEY` set =>
 *      {@see GeminiApiLlmClient} (dev/test only, synthetic data, no BAA --
 *      see `docs/configuration.md`).
 *   3. else => {@see UnavailableLlmClient} (the default in this environment;
 *      synthesis degrades to facts-only, I6).
 *
 * Vertex always wins when both are configured -- this factory never has to
 * choose between two live credentials, only between "production is set up"
 * and "it isn't yet." Every caller (the reduce path and, via the SAME
 * {@see LlmClientInterface} instance, {@see \OpenEMR\Modules\ClinicalCopilot\Observability\Qa\FlashReviewer}'s
 * `gemini-2.5-flash` calls) goes through this one selection -- there is no
 * separate factory per model, since the model string is a {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest}
 * field, not a client-construction concern.
 */
final class LlmClientFactory
{
    private function __construct()
    {
        // static-only
    }

    public static function create(): LlmClientInterface
    {
        $projectId = LlmEnv::gcpProjectId();
        if ($projectId !== '') {
            return new VertexLlmClient($projectId, LlmEnv::gcpLocation());
        }

        $apiKey = LlmEnv::geminiApiKey();
        if ($apiKey !== '') {
            return new GeminiApiLlmClient($apiKey);
        }

        return new UnavailableLlmClient();
    }
}
