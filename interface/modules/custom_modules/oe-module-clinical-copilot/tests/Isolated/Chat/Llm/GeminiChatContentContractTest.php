<?php

/**
 * GeminiChatContentContract: response-parse guards (finishReason, prompt block,
 * transport-error classification) for the shared chat-path Gemini parser.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat\Llm;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\GeminiApiChatLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Failure modes guarded on the chat surface, mirroring
 * GeminiGenerateContentContractTest one layer up: a truncated/blocked turn
 * ({@see self::testThrowsWhenFinishReasonIsMaxTokens()}) must degrade rather
 * than parse partial JSON, and a provider HTTP error must classify as
 * provider_error (not "unreachable") so the production Vertex chat path gets
 * the same diagnostics and rate-limit banner the dev path always had.
 */
final class GeminiChatContentContractTest extends TestCase
{
    /**
     * @param array<string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private function extractParts(array $decoded): array
    {
        $method = new ReflectionMethod(GeminiApiChatLlmClient::class, 'extractParts');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $parts */
        $parts = $method->invoke(null, $decoded);

        return $parts;
    }

    private function classifyTransportError(GuzzleException $e): LlmUnavailableException
    {
        $method = new ReflectionMethod(GeminiApiChatLlmClient::class, 'classifyTransportError');
        $method->setAccessible(true);

        return $method->invoke(null, $e);
    }

    public function testReturnsPartsWhenFinishReasonIsStop(): void
    {
        $decoded = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => '[]']]],
            ]],
        ];

        self::assertSame([['text' => '[]']], $this->extractParts($decoded));
    }

    /**
     * A tool-offering round finishes with STOP even when it emits a
     * functionCall -- the finishReason guard must NOT interfere with that.
     */
    public function testAllowsFunctionCallPartOnStopFinish(): void
    {
        $decoded = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['functionCall' => ['name' => 'get_vitals_trend', 'args' => []]]]],
            ]],
        ];

        self::assertSame(
            [['functionCall' => ['name' => 'get_vitals_trend', 'args' => []]]],
            $this->extractParts($decoded),
        );
    }

    public function testThrowsWhenFinishReasonIsMaxTokens(): void
    {
        $decoded = [
            'candidates' => [[
                'finishReason' => 'MAX_TOKENS',
                'content' => ['parts' => [['text' => '[{"text":"truncated']]],
            ]],
        ];

        $this->expectException(LlmUnavailableException::class);
        $this->extractParts($decoded);
    }

    public function testThrowsWhenFinishReasonIsMalformedFunctionCall(): void
    {
        $decoded = [
            'candidates' => [[
                'finishReason' => 'MALFORMED_FUNCTION_CALL',
                'content' => ['parts' => [['text' => '']]],
            ]],
        ];

        $this->expectException(LlmUnavailableException::class);
        $this->extractParts($decoded);
    }

    public function testSurfacesPromptBlockReasonWhenNoCandidates(): void
    {
        try {
            $this->extractParts(['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']]);
            self::fail('Expected LlmUnavailableException');
        } catch (LlmUnavailableException $e) {
            self::assertSame(LlmUnavailableException::REASON_PROVIDER_ERROR, $e->reason());
            self::assertStringContainsString('PROHIBITED_CONTENT', (string)$e->getPrevious()?->getMessage());
        }
    }

    public function testClassifiesHttpErrorResponseAsProviderError(): void
    {
        $request = new Request('POST', 'https://example.invalid/generateContent');
        $response = new Response(429, [], '{"error":{"status":"RESOURCE_EXHAUSTED"}}');
        $guzzleError = new RequestException('429 Too Many Requests', $request, $response);

        $classified = $this->classifyTransportError($guzzleError);

        self::assertSame(LlmUnavailableException::REASON_PROVIDER_ERROR, $classified->reason());
        self::assertStringContainsString('rate limit', $classified->degradedMessage());
    }

    public function testClassifiesConnectFailureAsUnreachable(): void
    {
        $request = new Request('POST', 'https://example.invalid/generateContent');
        $guzzleError = new ConnectException('Could not resolve host', $request);

        $classified = $this->classifyTransportError($guzzleError);

        self::assertSame(LlmUnavailableException::REASON_UNREACHABLE, $classified->reason());
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractOutputTokenCount(array $decoded): int
    {
        $method = new ReflectionMethod(GeminiApiChatLlmClient::class, 'extractOutputTokenCount');
        $method->setAccessible(true);

        return $method->invoke(null, $decoded);
    }

    public function testOutputTokenCountFoldsInThinkingTokens(): void
    {
        $decoded = ['usageMetadata' => ['candidatesTokenCount' => 50, 'thoughtsTokenCount' => 1200]];

        self::assertSame(1250, $this->extractOutputTokenCount($decoded));
    }
}
