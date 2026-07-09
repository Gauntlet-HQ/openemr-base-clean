<?php

/**
 * Shared Gemini `generateContent` request/response mapping (Vertex + AI Studio agree on this shape).
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
 * T18/T23: Vertex AI's `generateContent` REST endpoint and Google AI
 * Studio's `generateContent` REST endpoint (used by {@see GeminiApiLlmClient})
 * accept and return the SAME body shape (`systemInstruction`, `contents`,
 * `generationConfig.responseMimeType`/`responseSchema` on the way in;
 * `candidates[].content.parts[].text` and `usageMetadata` on the way out) --
 * only transport (auth header vs. API key, host, path) differs between the
 * two providers. {@see VertexLlmClient} and {@see GeminiApiLlmClient} both
 * use this trait so that shape is defined exactly once; a provider-specific
 * class owns only auth and the endpoint URL.
 */
trait GeminiGenerateContentContract
{
    /**
     * @return array<string, mixed> the `generateContent` request body for one
     *         structured-output call
     */
    private static function buildGenerateContentBody(PromptRequest $req): array
    {
        $generationConfig = [
            'temperature' => $req->temperature,
            'maxOutputTokens' => $req->maxOutputTokens,
            'responseMimeType' => 'application/json',
            'responseSchema' => $req->responseSchema,
        ];

        // Cap Gemini 2.5 "thinking" so a non-streaming generateContent does not
        // stall 20-30s before returning anything. Omitted entirely when null so
        // the provider default (dynamic) still applies.
        if ($req->thinkingBudget !== null) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => $req->thinkingBudget];
        }

        return [
            'systemInstruction' => ['parts' => [['text' => $req->systemInstructions]]],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $req->userContent]]],
            ],
            'generationConfig' => $generationConfig,
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function extractText(array $decoded): string
    {
        $candidates = $decoded['candidates'] ?? null;
        if (!is_array($candidates) || $candidates === []) {
            throw LlmUnavailableException::providerError(new \RuntimeException('Gemini response contained no candidates'));
        }

        $firstCandidate = $candidates[0];
        $parts = is_array($firstCandidate) ? ($firstCandidate['content']['parts'] ?? null) : null;
        if (!is_array($parts) || $parts === []) {
            throw LlmUnavailableException::providerError(new \RuntimeException('Gemini candidate contained no content parts'));
        }

        foreach ($parts as $part) {
            $text = is_array($part) ? ($part['text'] ?? null) : null;
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        throw LlmUnavailableException::providerError(new \RuntimeException('Gemini candidate contained no non-empty text part'));
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function extractTokenCount(array $decoded, string $field): int
    {
        $usage = $decoded['usageMetadata'] ?? null;
        $count = is_array($usage) ? ($usage[$field] ?? null) : null;

        return is_int($count) ? $count : 0;
    }
}
