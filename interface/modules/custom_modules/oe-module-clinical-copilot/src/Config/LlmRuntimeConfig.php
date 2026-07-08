<?php

/**
 * Runtime LLM model + provider selection for reduce/chat surfaces.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Config;

/**
 * Centralizes which Gemini model string reduce/chat should request. Vertex
 * production keeps `gemini-2.5-pro`; the AI Studio API-key dev path defaults
 * to `gemini-2.5-flash` because free-tier keys commonly lack Pro quota.
 */
final class LlmRuntimeConfig
{
    private const ENV_GEMINI_API_MODEL = 'CLINICAL_COPILOT_GEMINI_API_MODEL';

    private const VERTEX_REDUCE_CHAT_MODEL = 'gemini-2.5-pro';
    private const API_KEY_DEFAULT_MODEL = 'gemini-2.5-flash';

    private function __construct()
    {
    }

    public static function reduceAndChatModel(): string
    {
        if (self::usesVertex()) {
            return self::VERTEX_REDUCE_CHAT_MODEL;
        }

        $override = LlmEnv::getString(self::ENV_GEMINI_API_MODEL);
        if ($override !== '') {
            return $override;
        }

        if (self::usesGeminiApiKey()) {
            return self::API_KEY_DEFAULT_MODEL;
        }

        return self::VERTEX_REDUCE_CHAT_MODEL;
    }

    public static function usesVertex(): bool
    {
        return LlmEnv::gcpProjectId() !== '';
    }

    public static function usesGeminiApiKey(): bool
    {
        return !self::usesVertex() && LlmEnv::geminiApiKey() !== '';
    }

    public static function llmConfigured(): bool
    {
        return self::usesVertex() || self::usesGeminiApiKey();
    }
}