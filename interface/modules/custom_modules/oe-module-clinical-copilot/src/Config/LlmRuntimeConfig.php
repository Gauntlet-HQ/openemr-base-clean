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
 * Centralizes which Gemini model string reduce/chat should request. Both the
 * Vertex production path and the AI Studio API-key dev path default to
 * `gemini-2.5-pro`: reduce/chat output must pass the deterministic V1-V6
 * verifier (exact citations, grounded numbers, clean claim-schema JSON), and
 * only the Pro tier reliably produces verifiable claims -- Flash was the
 * source of the "every chat degrades / couldn't produce a verifiable answer"
 * failure (docs/build-notes.md reserves Flash for the advisory QA reviewer,
 * not the serving path). A cost-conscious dev with a free-tier key that lacks
 * Pro quota can still opt down via `CLINICAL_COPILOT_GEMINI_API_MODEL`, at the
 * cost of a higher verification-degrade rate.
 */
final class LlmRuntimeConfig
{
    private const ENV_GEMINI_API_MODEL = 'CLINICAL_COPILOT_GEMINI_API_MODEL';

    private const VERTEX_REDUCE_CHAT_MODEL = 'gemini-2.5-pro';
    private const API_KEY_DEFAULT_MODEL = 'gemini-2.5-pro';

    private function __construct()
    {
    }

    public static function reduceAndChatModel(): string
    {
        if (self::usesVertex()) {
            return self::VERTEX_REDUCE_CHAT_MODEL;
        }

        if (self::usesGeminiApiKey()) {
            $override = LlmEnv::getString(self::ENV_GEMINI_API_MODEL);
            return $override !== '' ? $override : self::API_KEY_DEFAULT_MODEL;
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