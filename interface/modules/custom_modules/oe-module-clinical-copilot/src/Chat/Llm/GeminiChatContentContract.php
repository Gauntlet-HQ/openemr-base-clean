<?php

/**
 * Shared Gemini function-calling `generateContent` request/response mapping (Vertex + AI Studio agree on this shape).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Llm;

use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallRequest;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;

/**
 * T18/T23: mirrors {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiGenerateContentContract}
 * one layer up, for the chat surface's native function-calling shape
 * (`tools: [{functionDeclarations: [...]}]` on the way in;
 * `functionCall` vs. plain `text` parts on the way out). Vertex AI and
 * Google AI Studio agree on this shape too -- {@see VertexChatLlmClient} and
 * {@see GeminiApiChatLlmClient} both use this trait so it is defined exactly
 * once; each provider-specific class owns only authentication and the
 * endpoint URL.
 */
trait GeminiChatContentContract
{
    /**
     * @return array<string, mixed> the `generateContent` request body for
     *         one chat round (tool-offering or final-answer, per
     *         {@see ChatLlmRequest::$tools})
     */
    private static function buildChatContentBody(ChatLlmRequest $req): array
    {
        $generationConfig = [
            'temperature' => $req->prompt->temperature,
            'maxOutputTokens' => $req->prompt->maxOutputTokens,
        ];

        $body = [
            'systemInstruction' => ['parts' => [['text' => $req->prompt->systemInstructions]]],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $req->prompt->userContent]]],
            ],
            'generationConfig' => $generationConfig,
        ];

        if ($req->tools !== []) {
            $body['tools'] = [[
                'functionDeclarations' => array_map(
                    static fn ($tool): array => $tool->toDeclaration(),
                    $req->tools,
                ),
            ]];

            return $body;
        }

        // No tools offered this round (the post-retry final-answer round) --
        // constrain the reply to the claim-list schema exactly like the
        // reduce path does.
        $generationConfig['responseMimeType'] = 'application/json';
        $generationConfig['responseSchema'] = $req->prompt->responseSchema;
        $body['generationConfig'] = $generationConfig;

        return $body;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private static function extractParts(array $decoded): array
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

        /** @var list<array<string, mixed>> $parts */
        return $parts;
    }

    /**
     * @param list<array<string, mixed>> $parts
     * @return list<ToolCallRequest>
     */
    private static function extractToolCalls(array $parts): array
    {
        $calls = [];
        foreach ($parts as $part) {
            $functionCall = $part['functionCall'] ?? null;
            if (!is_array($functionCall)) {
                continue;
            }
            $name = is_string($functionCall['name'] ?? null) ? $functionCall['name'] : '';
            $args = is_array($functionCall['args'] ?? null) ? $functionCall['args'] : [];
            if ($name === '') {
                continue;
            }
            /** @var array<string, mixed> $args */
            $calls[] = new ToolCallRequest($name, $args);
        }

        return $calls;
    }

    /**
     * @param list<array<string, mixed>> $parts
     */
    private static function extractText(array $parts): string
    {
        foreach ($parts as $part) {
            $text = $part['text'] ?? null;
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        throw LlmUnavailableException::providerError(new \RuntimeException('Gemini candidate contained neither a functionCall nor a text part'));
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
