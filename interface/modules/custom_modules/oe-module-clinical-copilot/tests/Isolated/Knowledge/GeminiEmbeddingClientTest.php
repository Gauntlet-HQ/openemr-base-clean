<?php

/**
 * GeminiEmbeddingClient — parses batchEmbedContents responses, degrades to null.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Knowledge;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\GeminiEmbeddingClient;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\UnavailableEmbeddingClient;
use PHPUnit\Framework\TestCase;

/**
 * Bound to a Guzzle MockHandler so no network is touched. Verifies the response
 * mapping (embeddings[].values -> index-aligned float vectors) and the
 * degrade-to-null contract on a malformed body — so a flaky embeddings hop leaves
 * rows unembedded (full-text still works) rather than throwing into
 * ingestion/retrieval.
 */
final class GeminiEmbeddingClientTest extends TestCase
{
    private function client(MockHandler $mock, int $dim = 3): GeminiEmbeddingClient
    {
        return new GeminiEmbeddingClient('key', 'gemini-embedding-001', $dim, new Client(['handler' => HandlerStack::create($mock)]));
    }

    public function testUnavailableClientReturnsNoVectors(): void
    {
        $client = new UnavailableEmbeddingClient(768);
        self::assertFalse($client->isAvailable());
        self::assertNull($client->embed('anything'));
        self::assertSame([null, null], $client->embedBatch(['a', 'b']));
    }

    public function testBatchResponseMapsToIndexAlignedVectors(): void
    {
        $body = json_encode(['embeddings' => [
            ['values' => [0.1, 0.2, 0.3]],
            ['values' => [0.4, 0.5, 0.6]],
        ]], JSON_THROW_ON_ERROR);

        $vectors = $this->client(new MockHandler([new Response(200, [], $body)]))->embedBatch(['first', 'second']);

        self::assertCount(2, $vectors);
        self::assertEqualsWithDelta([0.1, 0.2, 0.3], $vectors[0], 1e-9);
        self::assertEqualsWithDelta([0.4, 0.5, 0.6], $vectors[1], 1e-9);
    }

    public function testMalformedBodyDegradesToNullPerInput(): void
    {
        $vectors = $this->client(new MockHandler([new Response(200, [], '{"nope":true}')]))->embedBatch(['a', 'b']);

        self::assertSame([null, null], $vectors);
    }

    public function testEmptyInputSkipsTheCall(): void
    {
        $mock = new MockHandler([new Response(200, [], '{}')]);
        self::assertSame([], $this->client($mock)->embedBatch([]));
        self::assertCount(1, $mock, 'the queued response was not consumed — no HTTP call for an empty batch');
    }

    public function testRequestPinsOutputDimensionalityToTheColumnWidth(): void
    {
        // gemini-embedding-001 is Matryoshka: without this the API returns its
        // native 3072, overflowing a vector(1536) column. Every request must ask
        // for exactly the configured width.
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['embeddings' => [['values' => array_fill(0, 4, 0.5)]]], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $client = new GeminiEmbeddingClient('key', 'gemini-embedding-001', 4, new Client(['handler' => $stack]));

        $client->embedBatch(['hello']);

        self::assertCount(1, $history);
        $body = json_decode((string)$history[0]['request']->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(4, $body['requests'][0]['outputDimensionality'] ?? null);
        self::assertSame('models/gemini-embedding-001', $body['requests'][0]['model'] ?? null);
    }

    public function testEmptyApiKeyIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        new GeminiEmbeddingClient('');
    }
}
