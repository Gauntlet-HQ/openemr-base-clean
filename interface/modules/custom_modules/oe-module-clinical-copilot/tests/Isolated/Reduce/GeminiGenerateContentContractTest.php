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
        $method->setAccessible(true);

        return $method->invoke(null, $decoded);
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
}
