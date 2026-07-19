<?php

/**
 * Zero-cost Vertex reachability probe for /copilot/ready (ARCHITECTURE.md §3.4).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

use Google\Auth\ApplicationDefaultCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmEnv;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;

/**
 * "LLM provider reachable via a Vertex countTokens call (exercises
 * service-account auth and endpoint reachability at zero generation cost --
 * the concrete answer to 'is every probe billable': no)" (ARCHITECTURE.md
 * §3.4). {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface}
 * has no `countTokens` method (it is scoped purely to
 * `generateStructured()`, the reduce/chat/QA-review call shape) -- rather
 * than widen that interface for a probe only `/ready` needs, this class talks
 * to the SAME Vertex REST surface {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\VertexLlmClient}
 * does, independently, resolving project/location through the SAME
 * {@see \OpenEMR\Modules\ClinicalCopilot\Config\LlmEnv} resolver the
 * {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory} uses -- so
 * "is Vertex configured" really is decided identically everywhere. (This
 * previously read raw `getenv()`, which under mod_php can miss a value that
 * only landed in `$_SERVER`/`$_ENV` or the local env file, making `/ready`
 * report `unreachable` while chat/synthesis were actually serving.)
 *
 * Scope note: this probe reports the reachability of the ACTIVE provider,
 * whichever one {@see \OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig}
 * would select — the Gemini API-key path (AI Studio,
 * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiApiLlmClient}) when a key
 * is set, else Vertex ({@see \OpenEMR\Modules\ClinicalCopilot\Reduce\VertexLlmClient})
 * when a project is set. It previously probed Vertex only, so an API-key-only
 * deployment (the common dev/self-host case) always read `unreachable` on
 * `/ready` and the dashboard even while it was generating fine — now the probe
 * hits the same provider surface the real client uses.
 *
 * No credentials configured (the honest dev/test default, per build-notes.md)
 * reports `unreachable` -- never an exception, never a 5xx on `/ready` itself
 * (a missing GCP project must never take down readiness reporting; it is
 * degraded-but-serving information the endpoint reports, per I6).
 */
final class LlmReachabilityProbe
{
    private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';
    private const TIMEOUT_SECONDS = 5.0;
    private const PROBE_MODEL = 'gemini-2.5-flash';

    public function __construct(
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    /**
     * Reachability of the ACTIVE provider, resolved the same way the real
     * client is ({@see LlmRuntimeConfig}): the Gemini API-key path
     * (AI Studio) when a key is set, else Vertex when a GCP project is set,
     * else 'unreachable' (nothing configured -> degraded-by-design). This
     * matters because `/ready` used to probe Vertex only, so an API-key-only
     * deployment always read 'unreachable' even while it was generating fine.
     *
     * @return 'ok'|'unreachable'
     */
    public function probe(): string
    {
        if (LlmRuntimeConfig::usesGeminiApiKey()) {
            return $this->probeGeminiApi();
        }
        if (LlmRuntimeConfig::usesVertex()) {
            return $this->probeVertex();
        }

        // Nothing configured: the honest dev/test default. Degraded-by-design
        // (facts-only), reported as 'unreachable', never an exception.
        return 'unreachable';
    }

    /**
     * Gemini API (AI Studio) reachability: a cheap authenticated GET of the
     * models list with the configured key. 200 => reachable + key valid.
     *
     * @return 'ok'|'unreachable'
     */
    private function probeGeminiApi(): string
    {
        $apiKey = LlmEnv::geminiApiKey();
        if ($apiKey === '') {
            return 'unreachable';
        }

        try {
            $client = $this->httpClient ?? new Client(['verify' => true]);
            $client->request('GET', 'https://generativelanguage.googleapis.com/v1beta/models', [
                'headers' => ['x-goog-api-key' => $apiKey],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            return 'ok';
        } catch (\Throwable) {
            // Bad key, unreachable endpoint, or provider error all collapse to
            // the same observable state (I6): the LLM cannot be used right now.
            return 'unreachable';
        }
    }

    /**
     * @return 'ok'|'unreachable'
     */
    private function probeVertex(): string
    {
        // Resolve through LlmEnv (getenv -> $_SERVER -> $_ENV -> local env
        // file), identically to LlmClientFactory, so /ready's verdict matches
        // what the real client would actually see. gcpLocation() already
        // defaults to us-central1 when unset.
        $projectId = LlmEnv::gcpProjectId();
        if ($projectId === '') {
            return 'unreachable';
        }

        $location = LlmEnv::gcpLocation();

        try {
            /** @var \Google\Auth\FetchAuthTokenInterface $credentials */
            $credentials = ApplicationDefaultCredentials::getCredentials(self::OAUTH_SCOPE);
            $token = $credentials->fetchAuthToken();
            $accessToken = $token['access_token'] ?? null;
            if (!is_string($accessToken) || $accessToken === '') {
                return 'unreachable';
            }

            $client = $this->httpClient ?? new Client(['verify' => true]);
            $url = sprintf(
                'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:countTokens',
                $location,
                $projectId,
                $location,
                self::PROBE_MODEL,
            );

            $client->request('POST', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Content-Type' => 'application/json'],
                'json' => ['contents' => [['role' => 'user', 'parts' => [['text' => 'ready-probe']]]]],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            return 'ok';
        } catch (\Throwable) {
            // Every failure mode here -- no ADC, unreachable endpoint,
            // provider error -- collapses to the same observable state a
            // physician-facing degradation already treats identically (I6):
            // the LLM cannot be used right now.
            return 'unreachable';
        }
    }
}
