<?php

/**
 * Gemini-via-Vertex-AI implementation of LlmClientInterface.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\FetchAuthTokenInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * T18 (docs/clinical-copilot-tradeoffs.md, ARCHITECTURE.md "LLM platform"):
 * Gemini via **Vertex AI** REST (deliberately not the AI-Studio consumer
 * API -- no BAA path there), reached over Guzzle with **certificate
 * verification ON**, authenticated with a GCP service account via
 * `google/auth` ADC -- no API keys anywhere in this class. The REST contract
 * (endpoint shape, request/response bodies) is hand-pinned rather than built
 * on the thin/lagging Vertex PHP SDK generative surface -- an accepted risk
 * (T18), mitigated by keeping the entire contract in this one file and the
 * eval suite as a canary on any drift.
 *
 * `$model` is pinned by the caller (T18: `gemini-2.5-pro` for reduce/chat,
 * `gemini-2.5-flash` for the U12 advisory reviewer) via
 * {@see PromptContext}/{@see PromptRequest} -- this class never hardcodes a
 * version string, so a model bump is a config change, not a code change,
 * and still folds into `prompt_version` (a digest input) at the call site.
 *
 * Degradation (I6): {@see self::generateStructured()} throws
 * {@see LlmUnavailableException} -- never returns a partial/empty
 * LlmResponse -- whenever ADC cannot resolve credentials (the default state
 * of this dev/test environment: there is no GCP project configured here) or
 * the endpoint cannot be reached or errors out. This is deliberately the
 * ONLY class in the module that ever imports `Google\Auth` (ADC/service-
 * account auth is Vertex-only -- {@see GeminiApiLlmClient}'s API key needs
 * none of it) -- every other class depends on {@see LlmClientInterface}
 * instead.
 *
 * T23 (docs/build-notes.md "dev/test Gemini API-key fast-path"): this
 * class remains the ONLY production path. {@see GeminiApiLlmClient} is a
 * sibling implementation of the same interface for a dev/test fast-path
 * (a Google AI Studio API key, synthetic data only, no BAA) -- the two
 * share the identical `generateContent` request/response mapping via
 * {@see GeminiGenerateContentContract} (both use `GuzzleHttp` transport),
 * differing only in how each authenticates and which host it calls.
 * {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory}
 * is the only place that decides which one to construct.
 */
final class VertexLlmClient implements LlmClientInterface
{
    use GeminiGenerateContentContract;

    private const API_VERSION = 'v1';
    private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';
    private const TIMEOUT_SECONDS = 20.0;

    private readonly ClientInterface $httpClient;

    public function __construct(
        private readonly string $projectId,
        private readonly string $location,
        ?ClientInterface $httpClient = null,
    ) {
        if ($this->projectId === '') {
            throw new \DomainException('VertexLlmClient.projectId must not be empty');
        }

        if ($this->location === '') {
            throw new \DomainException('VertexLlmClient.location must not be empty');
        }

        // Certificate verification ON (ARCHITECTURE.md §4: "certificate
        // verification enforced in the HTTP client") -- never overridden by
        // a caller-supplied client either, since Guzzle's `verify` option
        // here is the module's own default, not something an injected
        // client is required to set.
        $this->httpClient = $httpClient ?? new Client(['verify' => true]);
    }

    public function generateStructured(PromptRequest $req): LlmResponse
    {
        $accessToken = $this->fetchAccessToken();
        $url = $this->endpointUrl($req->model);
        $body = self::buildGenerateContentBody($req);

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
            throw LlmUnavailableException::unreachable($e);
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

        return new LlmResponse(
            self::extractText($decoded),
            $req->model,
            self::extractTokenCount($decoded, 'promptTokenCount'),
            self::extractTokenCount($decoded, 'candidatesTokenCount'),
            $latencyMs,
        );
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
            // The expected path in dev/test: no ADC configured anywhere in
            // this environment. google/auth throws a variety of exception
            // types here (DomainException for "no credentials found",
            // others for malformed key files) -- caught broadly and
            // deliberately (CLAUDE.md: catch \Throwable) because every one
            // of them means the same thing to a caller: unavailable.
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
