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

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

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

        // Text first, then any inline document parts (Week 2 vision). The text
        // instruction precedes the document so the model reads "extract per this
        // schema" before it sees the page. Week 1 calls pass no parts, so this
        // is exactly the original single text part for them.
        $userParts = [['text' => $req->userContent]];
        foreach ($req->parts as $part) {
            $userParts[] = ['inlineData' => ['mimeType' => $part->mimeType, 'data' => $part->base64Data]];
        }

        return [
            'systemInstruction' => ['parts' => [['text' => $req->systemInstructions]]],
            'contents' => [
                ['role' => 'user', 'parts' => $userParts],
            ],
            'generationConfig' => $generationConfig,
        ];
    }

    /**
     * Maps a Guzzle transport failure onto the right degradation category.
     *
     * Guzzle runs with `http_errors => true`, so a 4xx/5xx provider response
     * (Vertex `400 INVALID_ARGUMENT` for a rejected schema, `403
     * PERMISSION_DENIED`, `429 RESOURCE_EXHAUSTED` quota, `5xx`) arrives here
     * as a {@see RequestException} that DOES carry a response -- that is a
     * `provider_error` (with the provider's own error body preserved for the
     * degrade detail / `degradedMessage()`'s rate-limit branch), NOT an
     * `unreachable` transport failure. Only a genuine connect/DNS/timeout
     * error (a {@see GuzzleException} with no response) is `unreachable`.
     * Shared so BOTH the Vertex and AI-Studio clients classify identically --
     * previously only the AI-Studio client did this and every Vertex HTTP
     * error collapsed to `unreachable`, hiding quota/permission/schema faults
     * on the one production path and never triggering the rate-limit banner.
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
     */
    private static function extractText(array $decoded): string
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

        foreach ($parts as $part) {
            $text = is_array($part) ? ($part['text'] ?? null) : null;
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        throw LlmUnavailableException::providerError(new \RuntimeException('Gemini candidate contained no non-empty text part'));
    }

    /**
     * A non-streaming `generateContent` candidate is only safe to read when
     * it finished with `STOP` (the model emitted a complete answer). Any other
     * terminal reason means the visible text is either TRUNCATED
     * (`MAX_TOKENS` -- Gemini 2.5 "thinking" can consume the output budget and
     * cut the JSON mid-structure) or WITHHELD (`SAFETY`/`RECITATION`/
     * `BLOCKLIST`/`PROHIBITED_CONTENT`/`SPII`/`OTHER`). Returning that text
     * verbatim ships invalid JSON downstream, where the verifier misreads it
     * as "the model produced nothing citable" (a normal, gated generation)
     * instead of the degradation it actually is -- two outcomes {@see LlmClientInterface}
     * requires be kept distinct. Reject early with the real reason instead.
     *
     * @param mixed $candidate the first `candidates[]` entry as decoded
     */
    private static function assertCleanFinish(mixed $candidate): void
    {
        $finishReason = is_array($candidate) ? ($candidate['finishReason'] ?? null) : null;
        if (!is_string($finishReason) || $finishReason === '') {
            // Absent finishReason: nothing to assert (older/other surfaces).
            return;
        }

        // STOP is the only clean completion; the unspecified sentinel is
        // treated as clean rather than over-rejecting a benign response.
        if ($finishReason === 'STOP' || $finishReason === 'FINISH_REASON_UNSPECIFIED') {
            return;
        }

        throw LlmUnavailableException::providerError(new \RuntimeException(
            'Gemini generateContent stopped early (finishReason=' . $finishReason
            . '); the response is truncated or content-blocked, not a usable answer',
        ));
    }

    /**
     * No `candidates[]` at all usually means the PROMPT was blocked before any
     * generation ran; Gemini reports that in `promptFeedback.blockReason`.
     * Surface it so an operator sees "prompt blocked (SAFETY)" rather than a
     * bare "no candidates".
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
     * The true output-side token burn for one call. Gemini 2.5 bills "thinking"
     * at the output rate but reports it in a SEPARATE `usageMetadata` field
     * (`thoughtsTokenCount`) that `candidatesTokenCount` EXCLUDES. Summing both
     * is what lets `tokens_out` (persisted to mod_copilot_doc/trace) reflect
     * what actually happened -- otherwise a thinking-heavy runaway hides behind
     * a normal-looking candidate count and never shows up in the trace, which
     * is exactly the anomaly the metering exists to catch.
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
