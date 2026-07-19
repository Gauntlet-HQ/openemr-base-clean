<?php

/**
 * Turns an uploaded knowledge document (text/markdown/HTML/PDF/image) into plain text.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

/**
 * The front of the ingestion pipeline: whatever the operator uploads, get plain
 * text out of it, then hand it to {@see DocumentChunker}. Routing by type keeps
 * the cost where it belongs:
 *
 *   - text / markdown  → used directly (no model call).
 *   - HTML             → tags stripped in PHP (no model call).
 *   - PDF / image      → transcribed by {@see DocumentTranscriber} (the reused
 *                        vision seam) — the only path that spends an LLM call.
 *
 * An unsupported type throws {@see UnsupportedDocumentException}; a PDF/image with
 * no model configured surfaces the transcriber's LlmUnavailableException, so the
 * endpoint can fall back to "paste text / upload .txt or .md."
 */
final class DocumentTextExtractor
{
    private const TEXT_TYPES = ['text/plain', 'text/markdown', 'text/x-markdown'];
    private const HTML_TYPES = ['text/html', 'application/xhtml+xml'];
    private const BINARY_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];

    public function __construct(private readonly DocumentTranscriber $transcriber)
    {
    }

    public function extract(string $bytes, string $mimeType): string
    {
        $type = strtolower(trim(explode(';', $mimeType)[0]));

        if (in_array($type, self::TEXT_TYPES, true)) {
            return $this->normalizeUtf8($bytes);
        }
        if (in_array($type, self::HTML_TYPES, true)) {
            return $this->htmlToText($this->normalizeUtf8($bytes));
        }
        if (in_array($type, self::BINARY_TYPES, true)) {
            return $this->transcriber->transcribe($bytes, $type);
        }

        throw new UnsupportedDocumentException(
            "Unsupported document type '{$type}'. Upload text, markdown, HTML, PDF, or an image."
        );
    }

    /** Whether a type is handled without any model call (used by the endpoint UI). */
    public static function isTextType(string $mimeType): bool
    {
        $type = strtolower(trim(explode(';', $mimeType)[0]));

        return in_array($type, self::TEXT_TYPES, true) || in_array($type, self::HTML_TYPES, true);
    }

    private function htmlToText(string $html): string
    {
        // Drop script/style contents entirely, then strip remaining tags. Block
        // elements become paragraph breaks so the chunker's boundary logic still
        // sees structure.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#</(p|div|section|article|h[1-6]|li|tr|br)\s*>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $text = strip_tags($html);

        return $this->normalizeUtf8(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function normalizeUtf8(string $bytes): string
    {
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            $converted = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            $bytes = is_string($converted) ? $converted : $bytes;
        }

        return trim($bytes);
    }
}
