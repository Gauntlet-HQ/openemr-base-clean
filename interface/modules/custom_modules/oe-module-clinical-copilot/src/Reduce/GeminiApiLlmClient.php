<?php

/**
 * Gemini-via-AI-Studio-API-key implementation of LlmClientInterface (dev/test fast-path).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * T23 (docs/build-notes.md "dev/test Gemini API-key fast-path"): Gemini via
 * the **Google AI Studio** consumer REST API
 * (`generativelanguage.googleapis.com`), authenticated with a bare API key
 * (`?key=...`) instead of {@see VertexLlmClient}'s GCP service-account ADC.
 * This is a **dev/test-only convenience** so the narrated experience can be
 * exercised end to end with synthetic data before the Vertex service
 * account + BAA is provisioned -- it does NOT change T18's production
 * decision (Vertex remains the only HIPAA-eligible path) and
 * {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory} only ever
 * constructs this class when `CLINICAL_COPILOT_GEMINI_API_KEY` is set AND
 * no Vertex project is configured -- Vertex always wins when both are
 * present.
 *
 * **OPEN-1, restated for this class specifically:** the API key path is
 * synthetic-data-only. There is no BAA covering AI Studio traffic, so this
 * class MUST NEVER be pointed at a deployment carrying real PHI -- see
 * `docs/configuration.md`.
 *
 * Shares the whole `generateContent` request/response mapping with
 * {@see VertexLlmClient} via {@see GeminiGenerateContentContract} -- this
 * class owns only authentication (the API key) and the AI Studio endpoint
 * URL. Same conventions as {@see VertexLlmClient}: Guzzle with certificate
 * verification ON, {@see LlmUnavailableException} on any failure, no
 * partial/empty {@see LlmResponse} ever returned. The API key is read ONLY
 * from `getenv()` by {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory}
 * and passed in here as a constructor argument -- never hardcoded, never
 * logged by this class.
 */
final class GeminiApiLlmClient implements LlmClientInterface
{
    use GeminiGenerateContentContract;

    private const API_VERSION = 'v1beta';
    private const TIMEOUT_SECONDS = 20.0;

    private readonly ClientInterface $httpClient;

    public function __construct(
        private readonly string $apiKey,
        ?ClientInterface $httpClient = null,
    ) {
        if ($this->apiKey === '') {
            throw new \DomainException('GeminiApiLlmClient.apiKey must not be empty');
        }

        // Certificate verification ON, same default as VertexLlmClient
        // (ARCHITECTURE.md §4) -- never overridden by an injected client.
        $this->httpClient = $httpClient ?? new Client(['verify' => true]);
    }

    public function generateStructured(PromptRequest $req): LlmResponse
    {
        $url = $this->endpointUrl($req->model);
        $body = self::buildGenerateContentBody($req);
        $body['generationConfig']['responseSchema'] = GeminiApiSchemaTranslator::translate(
            $body['generationConfig']['responseSchema'],
        );

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

        return new LlmResponse(
            self::extractText($decoded),
            $req->model,
            self::extractTokenCount($decoded, 'promptTokenCount'),
            self::extractTokenCount($decoded, 'candidatesTokenCount'),
            $latencyMs,
        );
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
