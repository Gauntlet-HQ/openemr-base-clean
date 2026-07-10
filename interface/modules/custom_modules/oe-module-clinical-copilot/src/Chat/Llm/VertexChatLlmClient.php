<?php

/**
 * Gemini-via-Vertex-AI implementation of ChatLlmClientInterface (native function calling).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Llm;

use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\FetchAuthTokenInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;

/**
 * T18 (ARCHITECTURE.md "LLM platform"): the SAME Vertex REST contract
 * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\VertexLlmClient} uses for the
 * synthesis pass (ADC via `google/auth`, Guzzle with certificate
 * verification ON, no API keys) -- this class adds exactly one thing:
 * `tools: [{functionDeclarations: [...]}]` in the request body, and parses
 * the response for `functionCall` parts (tool-call requests, I13 -- the
 * model requests, this class never executes one) versus a plain `text` part
 * (the final claims JSON, when the model has no more functionCall parts to
 * emit). Deliberately the only class in the module (besides
 * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\VertexLlmClient} itself)
 * that imports `Google\Auth` for ADC/service-account auth, mirroring
 * `VertexLlmClient`'s own "one file owns the whole REST contract"
 * discipline (T18).
 *
 * Degradation (I6): throws {@see LlmUnavailableException} -- never a
 * partial/empty {@see ChatLlmResponse} -- on missing ADC or an unreachable
 * endpoint, exactly {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\VertexLlmClient}'s
 * contract.
 *
 * T23 (docs/build-notes.md "dev/test Gemini API-key fast-path"): this class
 * remains the ONLY production path. {@see GeminiApiChatLlmClient} is a
 * sibling implementation of the same interface for a dev/test fast-path (a
 * Google AI Studio API key, synthetic data only, no BAA) -- the two share
 * the identical function-calling request/response mapping via
 * {@see GeminiChatContentContract}, differing only in authentication and
 * endpoint host. {@see ChatLlmClientFactory} is the only place that decides
 * which one to construct.
 */
final class VertexChatLlmClient implements ChatLlmClientInterface
{
    use GeminiChatContentContract;

    private const API_VERSION = 'v1';
    private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';
    // Matches the AI-Studio client's 90s: a chart-grounded reasoning call over
    // a real fact set routinely runs longer than 20s, and a 20s cut surfaced to
    // the physician as a mid-turn network error / an ungenerated narrative.
    private const TIMEOUT_SECONDS = 90.0;

    private readonly ClientInterface $httpClient;

    public function __construct(
        private readonly string $projectId,
        private readonly string $location,
        ?ClientInterface $httpClient = null,
    ) {
        if ($this->projectId === '') {
            throw new \DomainException('VertexChatLlmClient.projectId must not be empty');
        }

        if ($this->location === '') {
            throw new \DomainException('VertexChatLlmClient.location must not be empty');
        }

        $this->httpClient = $httpClient ?? new Client(['verify' => true]);
    }

    public function converse(ChatLlmRequest $req): ChatLlmResponse
    {
        $accessToken = $this->fetchAccessToken();
        $url = $this->endpointUrl($req->prompt->model);
        $body = self::buildChatContentBody($req);

        $startedAt = microtime(true);

        try {
            $httpResponse = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
        } catch (GuzzleException $e) {
            // A 4xx/5xx from Vertex (rejected tool schema, missing IAM role,
            // quota exhaustion) arrives WITH a response and is a provider
            // error, not an "unreachable" transport failure -- see
            // GeminiChatContentContract::classifyTransportError().
            throw self::classifyTransportError($e);
        }

        $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);

        try {
            $decoded = json_decode((string)$httpResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw LlmUnavailableException::providerError($e);
        }

        if (!is_array($decoded)) {
            throw LlmUnavailableException::providerError(new \RuntimeException('Vertex response was not a JSON object'));
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

    private function fetchAccessToken(): string
    {
        $credentials = $this->resolveCredentials();

        try {
            $token = $credentials->fetchAuthToken();
        } catch (\Throwable $e) {
            throw LlmUnavailableException::noCredentials($e);
        }

        $accessToken = $token['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw LlmUnavailableException::noCredentials(new \RuntimeException('ADC did not return an access_token'));
        }

        return $accessToken;
    }

    private function resolveCredentials(): FetchAuthTokenInterface
    {
        try {
            /** @var FetchAuthTokenInterface $credentials */
            $credentials = ApplicationDefaultCredentials::getCredentials(self::OAUTH_SCOPE);

            return $credentials;
        } catch (\Throwable $e) {
            throw LlmUnavailableException::noCredentials($e);
        }
    }

    private function endpointUrl(string $model): string
    {
        return sprintf(
            'https://%s-aiplatform.googleapis.com/%s/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
            $this->location,
            self::API_VERSION,
            $this->projectId,
            $this->location,
            $model,
        );
    }
}
