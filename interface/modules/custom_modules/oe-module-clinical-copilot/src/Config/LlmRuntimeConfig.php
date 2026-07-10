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
 * Centralizes which Gemini model string each surface should request. The two
 * surfaces are deliberately split by stakes and economics:
 *
 * - **Synthesis** ({@see self::synthesisModel()}, `gemini-2.5-pro`) -- the
 *   pre-visit summary. Higher-stakes, generated once per (pid, fact-digest),
 *   and its claims face the full deterministic V1-V6 verifier; Pro produces
 *   the most reliably-verifiable output, so it stays on Pro. Its model string
 *   also folds into the fact digest, so {@see \OpenEMR\Modules\ClinicalCopilot\Chat\ChatFreshnessChecker}
 *   MUST call THIS method (not {@see self::chatModel()}) to keep drift
 *   detection in lockstep with {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath}.
 *
 * - **Real-time chat** ({@see self::chatModel()}, `gemini-2.5-flash`) -- high
 *   volume, interactive, latency-sensitive, and runs over already-extracted
 *   facts, so Flash's lower cost and faster turns win. Flash was previously
 *   reserved to the advisory QA reviewer after it caused "every chat degrades
 *   / couldn't produce a verifiable answer"; that was traced to the verifier
 *   being over-strict (it flagged ordinary dates and numbers -- e.g. "type 2"
 *   -- as ungrounded), which has since been fixed. With the verifier
 *   corrected, Flash on the chat surface is viable again.
 *
 * A cost-conscious dev on a free-tier key that lacks Pro quota can override
 * BOTH surfaces to a single model via `CLINICAL_COPILOT_GEMINI_API_MODEL`
 * (dev/API-key path only).
 */
final class LlmRuntimeConfig
{
    private const ENV_GEMINI_API_MODEL = 'CLINICAL_COPILOT_GEMINI_API_MODEL';

    private const VERTEX_SYNTHESIS_MODEL = 'gemini-2.5-pro';
    private const API_KEY_SYNTHESIS_MODEL = 'gemini-2.5-pro';

    private const VERTEX_CHAT_MODEL = 'gemini-2.5-flash';
    private const API_KEY_CHAT_MODEL = 'gemini-2.5-flash';

    private function __construct()
    {
    }

    /**
     * The pre-visit synthesis summary's model (Pro). Also the model string the
     * fact digest folds in -- {@see \OpenEMR\Modules\ClinicalCopilot\Chat\ChatFreshnessChecker}
     * calls this too so its recomputed digest matches the stored doc.
     */
    public static function synthesisModel(): string
    {
        if (self::usesGeminiApiKey()) {
            $override = LlmEnv::getString(self::ENV_GEMINI_API_MODEL);
            return $override !== '' ? $override : self::API_KEY_SYNTHESIS_MODEL;
        }

        return self::VERTEX_SYNTHESIS_MODEL;
    }

    /**
     * The real-time chat surface's model (Flash) -- cost/latency-optimized for
     * interactive turns over already-extracted facts.
     */
    public static function chatModel(): string
    {
        if (self::usesGeminiApiKey()) {
            $override = LlmEnv::getString(self::ENV_GEMINI_API_MODEL);
            return $override !== '' ? $override : self::API_KEY_CHAT_MODEL;
        }

        return self::VERTEX_CHAT_MODEL;
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