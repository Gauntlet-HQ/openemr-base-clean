<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Common\Http\oeHttp;
use OpenEMR\Services\AI\OpenAiChatClient;
use OpenEMR\Services\AI\OpenAiConfig;
use OpenEMR\Services\AI\OpenAiException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('isolated')]
final class OpenAiChatClientTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset the shared static Guzzle client seeded during each test.
        oeHttp::$client = null;
    }

    public function testReturnsAssistantContentOnSuccess(): void
    {
        $body = json_encode([
            'choices' => [['message' => ['content' => '  Summary text  ']]],
        ], JSON_THROW_ON_ERROR);
        $client = $this->clientReturning(new Response(200, [], $body));

        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Summary text', $result);
    }

    public function testChatWithUsageReturnsModelAndTokenCounts(): void
    {
        $body = json_encode([
            'model' => 'gpt-4o-mini-2024-07-18',
            'choices' => [['message' => ['content' => 'Summary text']]],
            'usage' => [
                'prompt_tokens' => 111,
                'completion_tokens' => 22,
                'total_tokens' => 133,
            ],
        ], JSON_THROW_ON_ERROR);
        $client = $this->clientReturning(new Response(200, [], $body));

        $result = $client->chatWithUsage([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Summary text', $result->content);
        $this->assertSame('gpt-4o-mini-2024-07-18', $result->model);
        $this->assertSame(111, $result->usage->promptTokens);
        $this->assertSame(22, $result->usage->completionTokens);
        $this->assertSame(133, $result->usage->totalTokens);
    }

    public function testChatWithUsageDefaultsUsageWhenAbsent(): void
    {
        $body = json_encode([
            'choices' => [['message' => ['content' => 'Summary text']]],
        ], JSON_THROW_ON_ERROR);
        $client = $this->clientReturning(new Response(200, [], $body));

        $result = $client->chatWithUsage([['role' => 'user', 'content' => 'hi']]);

        $this->assertTrue($result->usage->isEmpty());
        // Falls back to the configured model when the provider omits it.
        $this->assertSame(OpenAiConfig::DEFAULT_MODEL, $result->model);
    }

    public function testThrowsOnNonSuccessStatus(): void
    {
        $client = $this->clientReturning(new Response(500, [], 'server error'));

        $this->expectException(OpenAiException::class);

        $client->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testThrowsOnMalformedPayload(): void
    {
        $body = json_encode(['unexpected' => true], JSON_THROW_ON_ERROR);
        $client = $this->clientReturning(new Response(200, [], $body));

        $this->expectException(OpenAiException::class);

        $client->chat([['role' => 'user', 'content' => 'hi']]);
    }

    private function clientReturning(Response $response): OpenAiChatClient
    {
        oeHttp::$client = new Client([
            'handler' => HandlerStack::create(new MockHandler([$response])),
        ]);

        return new OpenAiChatClient(new OpenAiConfig('sk-test'), new NullLogger());
    }
}
