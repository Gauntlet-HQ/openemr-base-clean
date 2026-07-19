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
use OpenEMR\Modules\ClinicalCopilot\Http\RetryingHttpRequester;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiApiSchemaTranslator;
use Psr\Log\LoggerInterface;

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
    // Matches VertexChatLlmClient's 90s: a chart-grounded chat turn, or a
    // slow/flaky egress hop to generativelanguage.googleapis.com, routinely
    // runs longer than 20s; a 20s cut surfaced as a spurious mid-turn network
    // error on calls that would otherwise complete.
    private const TIMEOUT_SECONDS = 90.0;

    private readonly ClientInterface $httpClient;

    private readonly RetryingHttpRequester $requester;

    public function __construct(
        private readonly string $apiKey,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        if ($this->apiKey === '') {
            throw new \DomainException('GeminiApiChatLlmClient.apiKey must not be empty');
        }

        $this->httpClient = $httpClient ?? new Client(['verify' => true]);
        // Bounded in-provider retry (transport / 429 / 5xx only) BELOW the
        // FailoverChatLlmClient layer -- see RetryingHttpRequester for the policy.
        $this->requester = new RetryingHttpRequester($this->httpClient, $logger);
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
            $httpResponse = $this->requester->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    // Key travels in a header, NOT the URL query string, so it
                    // can never leak into a cURL error message, an exception
                    // chain, a proxy/access log, or the surfaced degrade detail.
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
            ], 'gemini-api chat generateContent');
        } catch (GuzzleException $e) {
            // Shared with VertexChatLlmClient so both providers classify HTTP
            // errors (provider_error, body preserved) vs. transport failures
            // (unreachable) identically -- see the trait.
            throw self::classifyTransportError($e);
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
        $tokensOut = self::extractOutputTokenCount($decoded);
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
        // No `?key=` here on purpose -- the key is sent via the x-goog-api-key
        // request header (see converse) so it never appears in a URL.
        return sprintf(
            'https://generativelanguage.googleapis.com/%s/models/%s:generateContent',
            self::API_VERSION,
            $model,
        );
    }
}
