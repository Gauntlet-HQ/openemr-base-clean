<?php

/**
 * RetryingHttpRequester — bounded, transient-only retry shared by every outbound client.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClinicalCopilot\Http\RetryingHttpRequester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/** A PSR-3 logger that records every entry for assertion. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['message' => (string)$message, 'context' => $context];
    }
}

/**
 * Failure modes guarded: a transient provider blip (connect timeout, 429
 * rate-limit, 5xx) surfacing to the physician as "LLM unavailable" when a
 * short retry would have landed the call — and, conversely, a deterministic
 * 4xx (bad key, rejected schema) being retried pointlessly, tripling latency
 * and cost. Also pins that retry logging is metadata-only: never a payload.
 */
final class RetryingHttpRequesterTest extends TestCase
{
    private RecordingLogger $logger;

    /** @var list<int> the backoff delays the requester asked for, in ms */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->sleeps = [];
    }

    /**
     * @param list<Response|\Throwable> $queue
     */
    private function requester(array $queue, ?MockHandler &$mock = null): RetryingHttpRequester
    {
        $mock = new MockHandler($queue);

        return new RetryingHttpRequester(
            new Client(['handler' => HandlerStack::create($mock)]),
            $this->logger,
            function (int $ms): void {
                $this->sleeps[] = $ms;
            },
        );
    }

    public function testSuccessOnFirstAttemptNeverRetriesOrSleeps(): void
    {
        $requester = $this->requester([new Response(200, [], '{"ok":true}')], $mock);

        $response = $requester->post('https://example.invalid/x', [], 'test op');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->sleeps);
        self::assertSame([], $this->logger->records);
        self::assertCount(0, $mock, 'exactly one request was sent');
    }

    #[DataProvider('transientFailureProvider')]
    public function testRetriesTransientFailureThenSucceeds(Response|\Throwable $failure): void
    {
        $requester = $this->requester([$failure, new Response(200, [], 'ok')]);

        $response = $requester->post('https://example.invalid/x', [], 'test op');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([RetryingHttpRequester::BASE_DELAY_MS], $this->sleeps);
        self::assertCount(1, $this->logger->records);
    }

    /**
     * @return array<string, array{Response|\Throwable}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function transientFailureProvider(): array
    {
        $request = new Request('POST', 'https://example.invalid/x');

        return [
            'HTTP 429 rate limit' => [new Response(429, [], '{"error":{"status":"RESOURCE_EXHAUSTED"}}')],
            'HTTP 500' => [new Response(500, [], 'oops')],
            'HTTP 503' => [new Response(503, [], '')],
            'connect failure (no response)' => [new ConnectException('Could not resolve host', $request)],
        ];
    }

    public function testDoesNotRetryDeterministicClientErrors(): void
    {
        // A 400/401/403 is a deterministic rejection — retrying only adds
        // latency and cost. The queue holds a second response that must never
        // be consumed.
        $requester = $this->requester([
            new Response(403, [], '{"error":"PERMISSION_DENIED"}'),
            new Response(200, [], 'must never be reached'),
        ], $mock);

        try {
            $requester->post('https://example.invalid/x', [], 'test op');
            self::fail('Expected ClientException');
        } catch (ClientException $e) {
            self::assertSame(403, $e->getResponse()->getStatusCode());
        }

        self::assertSame([], $this->sleeps);
        self::assertSame([], $this->logger->records);
        self::assertCount(1, $mock, 'the second queued response was not consumed — no retry happened');
    }

    public function testGivesUpAfterMaxRetriesAndRethrowsTheLastFailure(): void
    {
        $requester = $this->requester([
            new Response(500, [], 'first'),
            new Response(503, [], 'second'),
            new Response(500, [], 'third'),
        ]);

        $this->expectException(BadResponseException::class);

        try {
            $requester->post('https://example.invalid/x', [], 'test op');
        } finally {
            // Exponential backoff between the bounded attempts: 200ms, 400ms.
            self::assertSame(
                [RetryingHttpRequester::BASE_DELAY_MS, RetryingHttpRequester::BASE_DELAY_MS * 2],
                $this->sleeps,
            );
            self::assertCount(RetryingHttpRequester::MAX_RETRIES, $this->logger->records);
        }
    }

    public function testRetryLogIsMetadataOnlyNeverPayload(): void
    {
        $requester = $this->requester([
            new Response(500, [], '{"leak":"PATIENT Jane Doe A1c 9.4"}'),
            new Response(200, [], 'ok'),
        ]);

        $requester->post('https://example.invalid/x', [
            'json' => ['secret_payload' => 'PHI would live here'],
            'headers' => ['x-goog-api-key' => 'sk-secret'],
        ], 'gemini-api generateContent');

        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        $flattened = json_encode($record, JSON_THROW_ON_ERROR);

        // Operationally useful metadata is present...
        self::assertSame('gemini-api generateContent', $record['context']['operation'] ?? null);
        self::assertSame(500, $record['context']['http_status'] ?? null);
        self::assertSame(1, $record['context']['attempt'] ?? null);
        // ...and neither the request payload, credentials, nor the response
        // body ever appear in the record.
        self::assertStringNotContainsString('PHI would live here', $flattened);
        self::assertStringNotContainsString('sk-secret', $flattened);
        self::assertStringNotContainsString('Jane', $flattened);
    }
}
