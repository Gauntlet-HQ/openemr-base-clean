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

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmEnv;
use OpenEMR\Modules\ClinicalCopilot\Reduce\FailoverLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiApiLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;

/**
 * Selects the LLM client from environment, with a clean degrade:
 *
 *   1. `CLINICAL_COPILOT_GEMINI_API_KEY` set => {@see GeminiApiLlmClient}
 *      (optionally wrapped in {@see FailoverLlmClient} when a second key,
 *      `..._GEMINI_API_KEY_BACKUP`, is also set).
 *   2. else => {@see UnavailableLlmClient} (synthesis degrades to facts-only, I6).
 *
 * Every caller (the reduce path and, via the SAME {@see LlmClientInterface}
 * instance, {@see \OpenEMR\Modules\ClinicalCopilot\Observability\Qa\FlashReviewer}'s
 * `gemini-2.5-flash` calls) goes through this one selection -- there is no
 * separate factory per model, since the model string is a
 * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest} field, not a
 * client-construction concern.
 *
 * The Vertex/ADC path was removed: this deployment uses the Gemini API key
 * exclusively, so carrying a second provider branch was dead weight.
 */
final class LlmClientFactory
{
    private function __construct()
    {
        // static-only
    }

    public static function create(): LlmClientInterface
    {
        $apiKey = LlmEnv::geminiApiKey();
        if ($apiKey === '') {
            return new UnavailableLlmClient();
        }

        $logger = new SystemLogger();
        $primary = new GeminiApiLlmClient($apiKey, null, $logger);
        $backupKey = LlmEnv::geminiApiKeyBackup();
        if ($backupKey !== '' && $backupKey !== $apiKey) {
            // Optional second key: on the primary's failure (bad key, quota,
            // transient provider/transport error) fall over to the backup before
            // degrading to facts-only. In-provider transient retries happen
            // first, inside each client (RetryingHttpRequester).
            return new FailoverLlmClient([$primary, new GeminiApiLlmClient($backupKey, null, $logger)], $logger);
        }

        return $primary;
    }
}
