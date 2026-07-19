<?php

/**
 * Bounded retry-with-backoff around one outbound HTTP POST — shared by every LLM/embedding client.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * The one retry policy for the module's five outbound HTTP clients (the two
 * generateContent clients, the two chat function-calling clients, and the
 * embeddings client), so none of them copy-paste its rules:
 *
 *   - Up to {@see self::MAX_RETRIES} retries (so at most 3 attempts total),
 *     with short exponential backoff ({@see self::BASE_DELAY_MS} doubling per
 *     retry) between attempts.
 *   - Retry ONLY on failures that are plausibly transient: a transport error
 *     that produced no HTTP response (DNS, connect/read timeout, TLS), an
 *     HTTP 429 (rate limit), or an HTTP 5xx. Every other 4xx (bad request,
 *     bad key, missing IAM role, rejected schema) is deterministic — retrying
 *     it only adds latency and cost — and is rethrown immediately.
 *
 * This deliberately sits BELOW the failover layer: {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\FailoverLlmClient}
 * / {@see \OpenEMR\Modules\ClinicalCopilot\Chat\Llm\FailoverChatLlmClient}
 * still fall over across providers/keys after a provider's call has exhausted
 * its in-provider retries here. Per-attempt timeouts are untouched — they stay
 * a caller concern, passed through in `$options`.
 *
 * Failures rethrow the original {@see GuzzleException} unchanged, so each
 * client's existing classification (`classifyTransportError`, the embedding
 * client's degrade-to-null) keeps working exactly as before. Retry attempts
 * are logged as PSR-3 structured context — operation name, attempt counter,
 * status/error class, delay — and NEVER any request or response payload.
 */
final class RetryingHttpRequester
{
    /** Retries after the initial attempt (bounded: at most 3 attempts total). */
    public const MAX_RETRIES = 2;

    /** First backoff delay; doubles on each subsequent retry (200ms, 400ms). */
    public const BASE_DELAY_MS = 200;

    /** @var \Closure(int): void sleeps the given number of milliseconds */
    private readonly \Closure $sleep;

    /**
     * @param ?\Closure(int): void $sleep test seam only — production callers
     *        omit it and get a real usleep-based backoff
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    /**
     * POST once, retrying per the class policy. On final failure the LAST
     * attempt's exception is rethrown unchanged.
     *
     * @param array<string, mixed> $options Guzzle request options (headers,
     *        json body, timeout) — passed through verbatim on every attempt
     * @param string $operation short non-PHI label for the log line
     *        (e.g. "gemini-api generateContent")
     *
     * @throws GuzzleException
     */
    public function post(string $url, array $options, string $operation): ResponseInterface
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->httpClient->request('POST', $url, $options);
            } catch (GuzzleException $e) {
                if ($attempt > self::MAX_RETRIES || !self::isRetryable($e)) {
                    throw $e;
                }

                $delayMs = self::BASE_DELAY_MS * (2 ** ($attempt - 1));
                // Metadata only — never the URL query, headers, or any
                // request/response body (no payload, no PHI, no secrets).
                $this->logger?->warning('Clinical Co-Pilot: retrying outbound HTTP call', [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'max_retries' => self::MAX_RETRIES,
                    'delay_ms' => $delayMs,
                    'http_status' => self::statusCode($e),
                    'error_class' => $e::class,
                ]);
                ($this->sleep)($delayMs);
            }
        }
    }

    /**
     * Transient-only: transport failures without a response, HTTP 429, and
     * HTTP 5xx retry; any other 4xx (deterministic rejection) never does.
     */
    private static function isRetryable(GuzzleException $e): bool
    {
        if ($e instanceof RequestException) {
            $response = $e->getResponse();
            if ($response === null) {
                return true; // failed before any HTTP response arrived: transport
            }
            $status = $response->getStatusCode();

            return $status === 429 || $status >= 500;
        }

        // ConnectException never carries a response — pure transport failure.
        return $e instanceof ConnectException;
    }

    private static function statusCode(GuzzleException $e): ?int
    {
        if ($e instanceof RequestException) {
            return $e->getResponse()?->getStatusCode();
        }

        return null;
    }
}
