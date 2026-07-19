<?php

/**
 * GeminiGenerateContentContract::extractText(): regression coverage for the
 * shared reduce-path Gemini response parser.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiApiLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Failure mode guarded: Gemini 2.5 can return a candidate whose first content
 * part is empty (e.g. internal reasoning emitted before the visible text).
 * extractText() must scan every part for the first non-empty text, not just
 * index 0 -- reading only parts[0] misclassifies a normal response as
 * LlmUnavailableException, which skips the V1-V6 verifier entirely instead
 * of failing it.
 */
final class GeminiGenerateContentContractTest extends TestCase
{
    private function extractText(array $decoded): string
    {
        $method = new ReflectionMethod(GeminiApiLlmClient::class, 'extractText');

        return $method->invoke(null, $decoded);
    }

    private function classifyTransportError(\GuzzleHttp\Exception\GuzzleException $e): LlmUnavailableException
    {
        $method = new ReflectionMethod(GeminiApiLlmClient::class, 'classifyTransportError');

        return $method->invoke(null, $e);
    }

    public function testReturnsTextFromFirstPartWhenPresent(): void
    {
        $decoded = ['candidates' => [['content' => ['parts' => [['text' => '{"claims":[]}']]]]]];

        self::assertSame('{"claims":[]}', $this->extractText($decoded));
    }

    public function testSkipsLeadingEmptyPartAndReturnsFirstNonEmptyText(): void
    {
        $decoded = [
            'candidates' => [[
                'content' => ['parts' => [
                    ['text' => ''],
                    ['text' => '{"claims":[]}'],
                ]],
            ]],
        ];

        self::assertSame('{"claims":[]}', $this->extractText($decoded));
    }

    public function testSkipsPartWithNoTextKeyAndReturnsLaterText(): void
    {
        $decoded = [
            'candidates' => [[
                'content' => ['parts' => [
                    ['functionCall' => ['name' => 'noop']],
                    ['text' => '{"claims":[]}'],
                ]],
            ]],
        ];

        self::assertSame('{"claims":[]}', $this->extractText($decoded));
    }

    public function testThrowsWhenEveryPartIsEmpty(): void
    {
        $decoded = [
            'candidates' => [[
                'content' => ['parts' => [['text' => ''], ['text' => '']]],
            ]],
        ];

        $this->expectException(LlmUnavailableException::class);
        $this->extractText($decoded);
    }

    public function testThrowsWhenNoCandidates(): void
    {
        $this->expectException(LlmUnavailableException::class);
        $this->extractText(['candidates' => []]);
    }

    public function testThrowsWhenNoParts(): void
    {
        $this->expectException(LlmUnavailableException::class);
        $this->extractText(['candidates' => [['content' => ['parts' => []]]]]);
    }

    public function testReturnsTextWhenFinishReasonIsStop(): void
    {
        $decoded = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => '{"claims":[]}']]],
            ]],
        ];

        self::assertSame('{"claims":[]}', $this->extractText($decoded));
    }

    /**
     * The core of #2: a MAX_TOKENS finish leaves the JSON truncated, but the
     * text part is still present. Reading it verbatim ships invalid JSON
     * downstream where the verifier misreads it as "nothing citable". The
     * parser must reject it as a provider error instead.
     */
    public function testThrowsWhenFinishReasonIsMaxTokensEvenWithText(): void
    {
        $decoded = [
            'candidates' => [[
                'finishReason' => 'MAX_TOKENS',
                'content' => ['parts' => [['text' => '[{"text":"truncated mid-']]],
            ]],
        ];

        $this->expectException(LlmUnavailableException::class);
        $this->expectExceptionMessageMatches('/rejected the request/');
        $this->extractText($decoded);
    }

    public function testThrowsWhenFinishReasonIsSafety(): void
    {
        $decoded = [
            'candidates' => [[
                'finishReason' => 'SAFETY',
                'content' => ['parts' => [['text' => 'partial']]],
            ]],
        ];

        $this->expectException(LlmUnavailableException::class);
        $this->extractText($decoded);
    }

    public function testSurfacesPromptBlockReasonWhenNoCandidates(): void
    {
        try {
            $this->extractText(['promptFeedback' => ['blockReason' => 'SAFETY']]);
            self::fail('Expected LlmUnavailableException');
        } catch (LlmUnavailableException $e) {
            self::assertSame(LlmUnavailableException::REASON_PROVIDER_ERROR, $e->reason());
            self::assertStringContainsString('SAFETY', (string)$e->getPrevious()?->getMessage());
        }
    }

    /**
     * The core of #1: an HTTP 4xx/5xx from the provider (here a 429 quota
     * error) must classify as provider_error with the body preserved -- NOT
     * as "unreachable" -- so LlmUnavailableException::degradedMessage()'s
     * rate-limit branch can fire and operators see the real cause.
     */
    public function testClassifiesHttpErrorResponseAsProviderErrorWithBody(): void
    {
        $request = new Request('POST', 'https://example.invalid/generateContent');
        $response = new Response(429, [], '{"error":{"status":"RESOURCE_EXHAUSTED"}}');
        $guzzleError = new RequestException('429 Too Many Requests', $request, $response);

        $classified = $this->classifyTransportError($guzzleError);

        self::assertSame(LlmUnavailableException::REASON_PROVIDER_ERROR, $classified->reason());
        self::assertStringContainsString('429', (string)$classified->getPrevious()?->getMessage());
        self::assertStringContainsString('RESOURCE_EXHAUSTED', (string)$classified->getPrevious()?->getMessage());
        // The rate-limit-aware physician banner now fires on this (production) path.
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
        $method = new ReflectionMethod(GeminiApiLlmClient::class, 'extractOutputTokenCount');

        return $method->invoke(null, $decoded);
    }

    /**
     * #7: `candidatesTokenCount` excludes Gemini 2.5 thinking; tokens_out must
     * fold in `thoughtsTokenCount` so a thinking-heavy runaway shows up in the
     * trace instead of hiding behind a normal-looking candidate count.
     */
    public function testOutputTokenCountFoldsInThinkingTokens(): void
    {
        $decoded = ['usageMetadata' => ['candidatesTokenCount' => 100, 'thoughtsTokenCount' => 900]];

        self::assertSame(1000, $this->extractOutputTokenCount($decoded));
    }

    public function testOutputTokenCountWithoutThinkingIsJustCandidates(): void
    {
        self::assertSame(42, $this->extractOutputTokenCount(['usageMetadata' => ['candidatesTokenCount' => 42]]));
    }

    public function testOutputTokenCountIsZeroWhenUsageAbsent(): void
    {
        self::assertSame(0, $this->extractOutputTokenCount([]));
    }
}
