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

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
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

        // Cap Gemini 2.5 "thinking" so a non-streaming generateContent does not
        // stall 20-30s before returning anything. Set here so BOTH the
        // tool-offering round and the final-answer round inherit it; omitted
        // when null so the provider default (dynamic) still applies. Chat runs
        // a deliberately small budget (interactive, over already-extracted
        // facts) -- the richer budget is reserved for the pre-generated
        // narrative.
        if ($req->prompt->thinkingBudget !== null) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => $req->prompt->thinkingBudget];
        }

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
     * Maps a Guzzle transport failure onto the right degradation category --
     * the chat-surface twin of
     * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiGenerateContentContract::classifyTransportError()}.
     * An HTTP 4xx/5xx from the provider (rejected tool schema, missing IAM
     * role, quota) arrives with a response and is a `provider_error` with the
     * body preserved; only a connect/DNS/timeout failure is `unreachable`.
     * Shared so the Vertex and AI-Studio chat clients classify identically.
     */
    private static function classifyTransportError(GuzzleException $e): LlmUnavailableException
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $bodySnippet = substr((string)$response->getBody(), 0, 500);

            return LlmUnavailableException::providerError(
                new \RuntimeException(
                    'Gemini generateContent HTTP ' . $statusCode . ($bodySnippet !== '' ? ': ' . $bodySnippet : ''),
                    0,
                    $e,
                ),
            );
        }

        return LlmUnavailableException::unreachable($e);
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private static function extractParts(array $decoded): array
    {
        $candidates = $decoded['candidates'] ?? null;
        if (!is_array($candidates) || $candidates === []) {
            throw self::noCandidatesError($decoded);
        }

        $firstCandidate = $candidates[0];
        self::assertCleanFinish($firstCandidate);
        $parts = is_array($firstCandidate) ? ($firstCandidate['content']['parts'] ?? null) : null;
        if (!is_array($parts) || $parts === []) {
            throw LlmUnavailableException::providerError(new \RuntimeException('Gemini candidate contained no content parts'));
        }

        /** @var list<array<string, mixed>> $parts */
        return $parts;
    }

    /**
     * A chat round's candidate is only safe to read on a `STOP` finish. On a
     * tool-offering round the model finishes with `STOP` even when it emits a
     * `functionCall`, so this does not interfere with tool calls. `MAX_TOKENS`
     * here truncates either the final claims JSON or a `functionCall`'s args
     * (`MALFORMED_FUNCTION_CALL`); `SAFETY`/`RECITATION`/`BLOCKLIST`/etc.
     * withhold content. Any of those is a degradation, not a usable turn --
     * mirrors {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiGenerateContentContract::assertCleanFinish()}.
     *
     * @param mixed $candidate the first `candidates[]` entry as decoded
     */
    private static function assertCleanFinish(mixed $candidate): void
    {
        $finishReason = is_array($candidate) ? ($candidate['finishReason'] ?? null) : null;
        if (!is_string($finishReason) || $finishReason === '') {
            return;
        }

        if ($finishReason === 'STOP' || $finishReason === 'FINISH_REASON_UNSPECIFIED') {
            return;
        }

        throw LlmUnavailableException::providerError(new \RuntimeException(
            'Gemini chat turn stopped early (finishReason=' . $finishReason
            . '); the response is truncated or content-blocked, not a usable answer',
        ));
    }

    /**
     * Surfaces `promptFeedback.blockReason` when the prompt itself was blocked
     * before any candidate was generated.
     *
     * @param array<string, mixed> $decoded
     */
    private static function noCandidatesError(array $decoded): LlmUnavailableException
    {
        $feedback = $decoded['promptFeedback'] ?? null;
        $blockReason = is_array($feedback) ? ($feedback['blockReason'] ?? null) : null;
        if (is_string($blockReason) && $blockReason !== '') {
            return LlmUnavailableException::providerError(new \RuntimeException(
                'Gemini blocked the prompt before generating (blockReason=' . $blockReason . ')',
            ));
        }

        return LlmUnavailableException::providerError(new \RuntimeException('Gemini response contained no candidates'));
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
                return self::stripJsonCodeFence($text);
            }
        }

        throw LlmUnavailableException::providerError(new \RuntimeException('Gemini candidate contained neither a functionCall nor a text part'));
    }

    /**
     * Provider-response normalization, NOT a verifier change: on a
     * tool-offering round Gemini cannot be given a `responseSchema` (the API
     * forbids `responseSchema` alongside `tools`), so when the model answers
     * directly in such a round it is only prompt-instructed to emit "ONLY a
     * JSON array ... no markdown fencing" -- an instruction it commonly
     * violates by wrapping the array in a ```json ... ``` fence. That wrapper
     * is a rendering artifact, not claim content, so it is stripped here
     * before the text ever becomes {@see ChatLlmResponse::$finalClaimsJson};
     * V1 ({@see \OpenEMR\Modules\ClinicalCopilot\Verify\ClaimSchema}) stays
     * unchanged and still rejects genuine free prose. Only a fence enclosing
     * the ENTIRE trimmed payload is removed; text without a wrapping fence is
     * returned verbatim.
     */
    private static function stripJsonCodeFence(string $text): string
    {
        $trimmed = trim($text);
        if (!str_starts_with($trimmed, '```') || !str_ends_with($trimmed, '```')) {
            return $text;
        }

        // Drop the opening fence line (``` optionally followed by a language
        // tag such as `json`) and the closing fence line.
        $inner = preg_replace('/^```[A-Za-z0-9_-]*[ \t]*\r?\n?/', '', $trimmed);
        if ($inner === null) {
            return $text;
        }
        $inner = preg_replace('/\r?\n?```$/', '', $inner);
        if ($inner === null) {
            return $text;
        }

        return trim($inner);
    }

    /**
     * The true output-side token burn for one chat round -- sums
     * `candidatesTokenCount` with Gemini 2.5's separately-reported
     * `thoughtsTokenCount` (which the former excludes) so a thinking-heavy
     * turn is visible in the trace rather than hidden. Mirrors
     * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiGenerateContentContract::extractOutputTokenCount()}.
     *
     * @param array<string, mixed> $decoded
     */
    private static function extractOutputTokenCount(array $decoded): int
    {
        return self::extractTokenCount($decoded, 'candidatesTokenCount')
            + self::extractTokenCount($decoded, 'thoughtsTokenCount');
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
