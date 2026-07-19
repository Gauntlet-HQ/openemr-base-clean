<?php

/**
 * DocumentTextExtractor — format routing (text/HTML free; PDF via the model).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Knowledge\DocumentTextExtractor;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\DocumentTranscriber;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\UnsupportedDocumentException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;
use PHPUnit\Framework\TestCase;

final class DocumentTextExtractorTest extends TestCase
{
    public function testPlainTextIsUsedDirectlyWithoutTheModel(): void
    {
        $extractor = new DocumentTextExtractor(new DocumentTranscriber(new ThrowingLlmClient(), 'unused'));

        self::assertSame('A1c below 7%.', $extractor->extract('A1c below 7%.', 'text/plain'));
    }

    public function testHtmlIsStrippedToTextWithoutTheModel(): void
    {
        $extractor = new DocumentTextExtractor(new DocumentTranscriber(new ThrowingLlmClient(), 'unused'));
        $html = '<html><head><style>.x{}</style></head><body><h1>Targets</h1><p>A1c &lt; 7%</p>'
            . '<script>evil()</script></body></html>';

        $text = $extractor->extract($html, 'text/html');

        self::assertStringContainsString('Targets', $text);
        self::assertStringContainsString('A1c < 7%', $text); // &lt; decodes to a literal <, which is correct content
        self::assertStringNotContainsString('evil', $text);
        // No tag markup survives (the decoded "<" in "A1c < 7%" is content, not a tag).
        self::assertStringNotContainsString('<h1>', $text);
        self::assertStringNotContainsString('<script', $text);
        self::assertStringNotContainsString('<p>', $text);
    }

    public function testPdfIsRoutedToTheTranscriber(): void
    {
        $extractor = new DocumentTextExtractor(new DocumentTranscriber(new CannedLlmClient('transcribed body'), 'model'));

        self::assertSame('transcribed body', $extractor->extract('%PDF-1.4 ...', 'application/pdf'));
    }

    public function testUnsupportedTypeThrows(): void
    {
        $extractor = new DocumentTextExtractor(new DocumentTranscriber(new ThrowingLlmClient(), 'unused'));

        $this->expectException(UnsupportedDocumentException::class);
        $extractor->extract('...', 'application/zip');
    }

    public function testIsTextTypeClassifiesFreePaths(): void
    {
        self::assertTrue(DocumentTextExtractor::isTextType('text/plain'));
        self::assertTrue(DocumentTextExtractor::isTextType('text/html; charset=utf-8'));
        self::assertFalse(DocumentTextExtractor::isTextType('application/pdf'));
    }
}

/** A client that must never be called (proves the text/HTML paths skip the model). */
final class ThrowingLlmClient implements LlmClientInterface
{
    public function generateStructured(PromptRequest $request): LlmResponse
    {
        throw new \LogicException('the model must not be called for text/HTML input');
    }
}

/** A client returning a fixed transcription in the {text: ...} envelope. */
final class CannedLlmClient implements LlmClientInterface
{
    public function __construct(private readonly string $text)
    {
    }

    public function generateStructured(PromptRequest $request): LlmResponse
    {
        return new LlmResponse(
            json_encode(['text' => $this->text], JSON_THROW_ON_ERROR),
            'model',
            1,
            1,
            1,
        );
    }
}
