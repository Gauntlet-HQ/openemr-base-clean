<?php

/**
 * Embeds text via the Google AI Studio (Gemini) embeddings API.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use OpenEMR\Modules\ClinicalCopilot\Http\RetryingHttpRequester;
use Psr\Log\LoggerInterface;

/**
 * The vectors behind the "vector db." Calls `models/{model}:batchEmbedContents`
 * on `generativelanguage.googleapis.com` with the same API key the rest of the
 * dev/test LLM path uses, and returns one float vector per input. Only PHI-free
 * knowledge text (guideline chunks) and PHI-scrubbed queries are ever embedded —
 * the same non-BAA boundary the knowledge store as a whole observes.
 *
 * Every failure degrades to null (never throws to the caller): the writer then
 * stores the chunk with a NULL embedding and retrieval falls back to full-text,
 * so a flaky embeddings hop never breaks ingestion or search.
 *
 * The request pins `outputDimensionality` to the configured width, so a
 * Matryoshka model (gemini-embedding-001) returns a truncated slice that fits the
 * pgvector column exactly. No client-side re-normalization is needed: retrieval
 * ranks by COSINE distance (`vector_cosine_ops` / the `<=>` operator), which is
 * magnitude-invariant — only the vector's direction matters, and truncation
 * preserves direction.
 */
final class GeminiEmbeddingClient implements EmbeddingClientInterface
{
    private const API_VERSION = 'v1beta';
    private const TIMEOUT_SECONDS = 30.0;

    private readonly ClientInterface $httpClient;

    private readonly RetryingHttpRequester $requester;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-embedding-001',
        private readonly int $dimension = 1536,
        ?ClientInterface $httpClient = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if ($this->apiKey === '') {
            throw new \DomainException('GeminiEmbeddingClient.apiKey must not be empty');
        }
        // Certificate verification ON, same posture as the generation clients.
        $this->httpClient = $httpClient ?? new Client(['verify' => true]);
        // Bounded retry (transport / 429 / 5xx only) BEFORE the degrade-to-null
        // catch below -- see RetryingHttpRequester for the shared policy.
        $this->requester = new RetryingHttpRequester($this->httpClient, $logger);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function dimension(): int
    {
        return $this->dimension;
    }

    public function embed(string $text): ?array
    {
        return $this->embedBatch([$text])[0] ?? null;
    }

    /**
     * @param list<string> $texts
     *
     * @return list<list<float>|null>
     */
    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $modelPath = 'models/' . $this->model;
        $requests = array_map(
            fn (string $text): array => [
                'model' => $modelPath,
                'content' => ['parts' => [['text' => $text]]],
                // Ask the model for exactly the column width. gemini-embedding-001
                // is Matryoshka-trained: it returns the first N of its native 3072
                // as a valid shorter embedding, so 1536 keeps pgvector's standard
                // HNSW index (2000-dim cap) while staying finer-grained than 768.
                'outputDimensionality' => $this->dimension,
            ],
            $texts,
        );

        try {
            $response = $this->requester->post($this->endpointUrl(), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => ['requests' => $requests],
                'timeout' => self::TIMEOUT_SECONDS,
            ], 'gemini-api batchEmbedContents');
        } catch (GuzzleException $e) {
            $this->logger?->warning('Clinical Co-Pilot: embedding request failed', ['exception' => $e]);

            return array_fill(0, count($texts), null);
        }

        return $this->parseEmbeddings((string)$response->getBody(), count($texts));
    }

    /**
     * @return list<list<float>|null>
     */
    private function parseEmbeddings(string $body, int $expected): array
    {
        $decoded = json_decode($body, true);
        $rows = is_array($decoded) ? ($decoded['embeddings'] ?? null) : null;
        if (!is_array($rows)) {
            $this->logger?->warning('Clinical Co-Pilot: embedding response had no embeddings array');

            return array_fill(0, $expected, null);
        }

        $out = [];
        foreach ($rows as $row) {
            $values = is_array($row) ? ($row['values'] ?? null) : null;
            if (!is_array($values)) {
                $out[] = null;
                continue;
            }
            $vector = [];
            foreach ($values as $v) {
                if (is_int($v) || is_float($v)) {
                    $vector[] = (float)$v;
                }
            }
            // Guard the configured dimension: a model whose output width does not
            // match the pgvector column would throw an opaque "different vector
            // dimensions" on write and silently disable vector reads. Treat a
            // mismatch as "not embedded" so the store degrades to full-text with a
            // clear signal rather than corrupting.
            if ($vector !== [] && count($vector) !== $this->dimension) {
                $this->logger?->warning('Clinical Co-Pilot: embedding dimension mismatch', [
                    'expected' => $this->dimension,
                    'got' => count($vector),
                    'model' => $this->model,
                ]);
                $vector = [];
            }
            $out[] = $vector === [] ? null : $vector;
        }

        // Pad/truncate to the expected count so the result stays index-aligned.
        while (count($out) < $expected) {
            $out[] = null;
        }

        return array_slice($out, 0, $expected);
    }

    private function endpointUrl(): string
    {
        return sprintf(
            'https://generativelanguage.googleapis.com/%s/models/%s:batchEmbedContents',
            self::API_VERSION,
            $this->model,
        );
    }
}
