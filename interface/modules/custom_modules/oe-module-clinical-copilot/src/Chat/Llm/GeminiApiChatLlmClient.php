<?php

/**
 * Gemini-via-AI-Studio-API-key implementation of ChatLlmClientInterface (dev/test fast-path).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Llm;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiApiSchemaTranslator;

/**
 * T23 (docs/build-notes.md "dev/test Gemini API-key fast-path"): the chat
 * surface's counterpart to {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiApiLlmClient}
 * -- native function calling against the **Google AI Studio** consumer REST
 * API, authenticated with a bare API key instead of
 * {@see VertexChatLlmClient}'s GCP service-account ADC. Dev/test-only
 * convenience so the chat agent loop can be exercised end to end with
 * synthetic data before the Vertex service account + BAA is provisioned --
 * does NOT change T18's production decision. {@see ChatLlmClientFactory}
 * only ever constructs this class when `CLINICAL_COPILOT_GEMINI_API_KEY` is
 * set AND no Vertex project is configured -- Vertex always wins when both
 * are present.
 *
 * **OPEN-1, restated for this class specifically:** synthetic-data-only, no
 * BAA -- see `docs/configuration.md`. MUST NEVER be pointed at a deployment
 * carrying real PHI.
 *
 * Shares the whole function-calling request/response mapping with
 * {@see VertexChatLlmClient} via {@see GeminiChatContentContract} -- this
 * class owns only authentication (the API key) and the AI Studio endpoint
 * URL. Same conventions as {@see VertexChatLlmClient}: Guzzle with
 * certificate verification ON, {@see LlmUnavailableException} on any
 * failure, no partial/empty {@see ChatLlmResponse} ever returned. The API
 * key is read ONLY from `getenv()` by {@see ChatLlmClientFactory} and passed
 * in here as a constructor argument -- never hardcoded, never logged by
 * this class.
 */
final class GeminiApiChatLlmClient implements ChatLlmClientInterface
{
    use GeminiChatContentContract;

    private const API_VERSION = 'v1beta';
    private const TIMEOUT_SECONDS = 20.0;

    private readonly ClientInterface $httpClient;

    public function __construct(
        private readonly string $apiKey,
        ?ClientInterface $httpClient = null,
    ) {
        if ($this->apiKey === '') {
            throw new \DomainException('GeminiApiChatLlmClient.apiKey must not be empty');
        }

        $this->httpClient = $httpClient ?? new Client(['verify' => true]);
    }

    public function converse(ChatLlmRequest $req): ChatLlmResponse
    {
        $url = $this->endpointUrl($req->prompt->model);
        $body = self::buildChatContentBody($req);
        if (isset($body['tools'][0]['functionDeclarations']) && is_array($body['tools'][0]['functionDeclarations'])) {
            /** @var list<array<string, mixed>> $declarations */
            $declarations = $body['tools'][0]['functionDeclarations'];
            foreach ($declarations as $index => $declaration) {
                if (!isset($declaration['parameters']) || !is_array($declaration['parameters'])) {
                    continue;
                }
                /** @var array<string, mixed> $parameters */
                $parameters = $declaration['parameters'];
                $declarations[$index]['parameters'] = GeminiApiSchemaTranslator::translate($parameters);
            }
            $body['tools'][0]['functionDeclarations'] = $declarations;
        }
        if (isset($body['generationConfig']['responseSchema']) && is_array($body['generationConfig']['responseSchema'])) {
            /** @var array<string, mixed> $schema */
            $schema = $body['generationConfig']['responseSchema'];
            $body['generationConfig']['responseSchema'] = GeminiApiSchemaTranslator::translate($schema);
        }

        $startedAt = microtime(true);

        try {
            $httpResponse = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
        } catch (GuzzleException $e) {
            if ($e instanceof RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $bodySnippet = substr((string)$response->getBody(), 0, 500);
                throw LlmUnavailableException::providerError(
                    new \RuntimeException(
                        'Gemini API HTTP ' . $statusCode . ($bodySnippet !== '' ? ': ' . $bodySnippet : ''),
                        0,
                        $e,
                    ),
                );
            }
            throw LlmUnavailableException::unreachable($e);
        }

        $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);

        try {
            $decoded = json_decode((string)$httpResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw LlmUnavailableException::providerError($e);
        }

        if (!is_array($decoded)) {
            throw LlmUnavailableException::providerError(new \RuntimeException('Gemini API response was not a JSON object'));
        }

        $tokensIn = self::extractTokenCount($decoded, 'promptTokenCount');
        $tokensOut = self::extractTokenCount($decoded, 'candidatesTokenCount');
        $parts = self::extractParts($decoded);

        $toolCalls = self::extractToolCalls($parts);
        if ($toolCalls !== []) {
            return ChatLlmResponse::toolCalls($toolCalls, $req->prompt->model, $tokensIn, $tokensOut, $latencyMs);
        }

        $text = self::extractText($parts);

        return ChatLlmResponse::finalAnswer($text, $req->prompt->model, $tokensIn, $tokensOut, $latencyMs);
    }

    private function endpointUrl(string $model): string
    {
        return sprintf(
            'https://generativelanguage.googleapis.com/%s/models/%s:generateContent?key=%s',
            self::API_VERSION,
            $model,
            rawurlencode($this->apiKey),
        );
    }
}
