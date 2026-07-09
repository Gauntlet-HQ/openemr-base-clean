<?php

/**
 * One structured-generation request handed to an LlmClientInterface.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

/**
 * Provider-agnostic on purpose: {@see VertexLlmClient} maps this onto the
 * Vertex `generateContent` REST body (`systemInstruction`, `contents`,
 * `generationConfig.responseMimeType`/`responseSchema`); a stub test client
 * never has to know Vertex's shape at all.
 */
final readonly class PromptRequest
{
    /**
     * @param array<string, mixed> $responseSchema the claim-list JSON Schema
     *        (mirrors {@see ClaimType} and the §2.1 output contract), passed
     *        to the provider as `responseSchema` for constrained decoding
     * @param non-empty-string $model the pinned model version string (e.g.
     *        `gemini-2.5-pro`) -- folds into `prompt_version`, a digest input
     */
    public function __construct(
        public string $systemInstructions,
        public string $userContent,
        public array $responseSchema,
        public string $model,
        public string $promptVersion,
        public float $temperature = 0.0,
        public int $maxOutputTokens = 24576,
        // Gemini 2.5 "thinking" budget (tokens the model may spend reasoning
        // before it emits the answer). generateContent is non-streaming, so the
        // caller gets ZERO bytes until thinking finishes -- left dynamic, the
        // model can burn 20-30s, which reads as a stall/timeout. null = leave
        // the provider default (dynamic); a positive int caps it. See
        // {@see PromptContext::$thinkingBudget}.
        public ?int $thinkingBudget = null,
    ) {
        if ($this->systemInstructions === '') {
            throw new \DomainException('PromptRequest.systemInstructions must not be empty');
        }

        if ($this->userContent === '') {
            throw new \DomainException('PromptRequest.userContent must not be empty');
        }

        if ($this->model === '') {
            throw new \DomainException('PromptRequest.model must not be empty');
        }
    }
}
