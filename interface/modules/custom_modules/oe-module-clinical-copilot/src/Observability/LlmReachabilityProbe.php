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
use GuzzleHttp\Exception\GuzzleException;

/**
 * "LLM provider reachable via a Vertex countTokens call (exercises
 * service-account auth and endpoint reachability at zero generation cost --
 * the concrete answer to 'is every probe billable': no)" (ARCHITECTURE.md
 * §3.4). {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface}
 * has no `countTokens` method (it is scoped purely to
 * `generateStructured()`, the reduce/chat/QA-review call shape) -- rather
 * than widen that interface for a probe only `/ready` needs, this class talks
 * to the SAME Vertex REST surface {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\VertexLlmClient}
 * does, independently, using the identical env-var configuration
 * ({@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory}'s
 * `CLINICAL_COPILOT_GCP_PROJECT_ID`/`CLINICAL_COPILOT_GCP_LOCATION`) so "is
 * Vertex configured" is decided identically everywhere in the module.
 *
 * No credentials configured (the honest dev/test default, per build-notes.md)
 * reports `unreachable` -- never an exception, never a 5xx on `/ready` itself
 * (a missing GCP project must never take down readiness reporting; it is
 * degraded-but-serving information the endpoint reports, per I6).
 */
final class LlmReachabilityProbe
{
    private const ENV_PROJECT_ID = 'CLINICAL_COPILOT_GCP_PROJECT_ID';
    private const ENV_LOCATION = 'CLINICAL_COPILOT_GCP_LOCATION';
    private const DEFAULT_LOCATION = 'us-central1';
    private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';
    private const TIMEOUT_SECONDS = 5.0;
    private const PROBE_MODEL = 'gemini-2.5-flash';

    public function __construct(
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    /**
     * @return 'ok'|'unreachable'
     */
    public function probe(): string
    {
        $projectId = trim((string)getenv(self::ENV_PROJECT_ID));
        if ($projectId === '') {
            return 'unreachable';
        }

        $location = trim((string)getenv(self::ENV_LOCATION));
        if ($location === '') {
            $location = self::DEFAULT_LOCATION;
        }

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
        } catch (GuzzleException|\Throwable) {
            // Every failure mode here -- no ADC, unreachable endpoint,
            // provider error -- collapses to the same observable state a
            // physician-facing degradation already treats identically (I6):
            // the LLM cannot be used right now.
            return 'unreachable';
        }
    }
}
