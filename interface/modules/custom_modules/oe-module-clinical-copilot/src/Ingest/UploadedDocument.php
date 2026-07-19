<?php

/**
 * A safely-parsed uploaded file: bytes + filename + mime, from a $_FILES entry.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

/**
 * Parse, don't validate, at the HTTP boundary: the raw `$_FILES` superglobal is
 * turned into this typed object once (in the endpoint), and the rest of the
 * ingestion works with bytes it can trust. Returns null for a missing/failed
 * upload or a disallowed type, so the endpoint can re-render the form with an
 * error instead of the domain layer having to reason about upload plumbing.
 */
final readonly class UploadedDocument
{
    /** Accepted source document mime types (lab reports / intake forms). */
    private const ALLOWED_MIME = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];

    /**
     * Upper bound on the raw document size. A Gemini `generateContent` request
     * carrying inline data caps the whole payload near 20 MB; base64 inflates
     * the bytes by ~33% and the prompt + strict response schema also ride in the
     * same request. Holding the raw document to 12 MB (~16 MB once encoded)
     * keeps a scan under that ceiling with headroom, so an oversized upload is
     * refused HERE with a clear message instead of being sent, rejected by the
     * provider, and silently degrading to a blank draft the physician cannot
     * tell apart from "the model found nothing." Callers surface the reason via
     * {@see describeRejection()}.
     */
    public const MAX_DOCUMENT_BYTES = 12 * 1024 * 1024;

    public function __construct(
        public string $bytes,
        public string $filename,
        public string $mimeType,
    ) {
    }

    /**
     * @param mixed $entry the `$_FILES['document']` array, or null
     */
    public static function fromFilesEntry(mixed $entry): ?self
    {
        if (!is_array($entry)) {
            return null;
        }

        $tmpName = $entry['tmp_name'] ?? null;
        $error = $entry['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_string($tmpName) || $tmpName === '' || $error !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
            return null;
        }

        $bytes = @file_get_contents($tmpName);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        if (strlen($bytes) > self::MAX_DOCUMENT_BYTES) {
            return null;
        }

        $mime = self::resolveMime($tmpName, is_string($entry['type'] ?? null) ? $entry['type'] : '');
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            return null;
        }

        $filename = is_string($entry['name'] ?? null) && $entry['name'] !== ''
            ? basename($entry['name'])
            : 'upload';

        return new self($bytes, $filename, $mime);
    }

    /**
     * Classify why a `$_FILES` entry was (or would be) rejected by
     * {@see fromFilesEntry()}, as a stable machine code — pure, reads only the
     * entry metadata PHP already populated (never re-reads the temp file), so it
     * is unit-testable and safe to call after fromFilesEntry() has run. The
     * `unsupported_type` code is the catch-all for an otherwise-OK upload, since
     * the authoritative type check is a server-side sniff done in
     * fromFilesEntry() (not repeated here). Returns `ok` if nothing metadata can
     * see would reject it.
     *
     * @param mixed $entry the `$_FILES['document']` array, or null
     *
     * @return 'no_file'|'too_large'|'upload_failed'|'unsupported_type'|'ok'
     */
    public static function classifyRejection(mixed $entry): string
    {
        if (!is_array($entry)) {
            return 'no_file';
        }

        $error = $entry['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return 'no_file';
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return 'too_large';
        }
        if ($error !== UPLOAD_ERR_OK) {
            return 'upload_failed';
        }

        $size = is_numeric($entry['size'] ?? null) ? (int)$entry['size'] : 0;
        if ($size > self::MAX_DOCUMENT_BYTES) {
            return 'too_large';
        }

        return 'unsupported_type';
    }

    /**
     * The translated, user-facing sentence for a rejected upload — called by the
     * endpoints only on the null path, so a staff member sees "the scan is too
     * large" rather than a generic "choose a file" for every failure. Thin
     * xl()-mapping over {@see classifyRejection()} (which carries the logic).
     *
     * @param mixed $entry the `$_FILES['document']` array, or null
     */
    public static function describeRejection(mixed $entry): string
    {
        return match (self::classifyRejection($entry)) {
            'too_large' => sprintf(
                xl('That document is too large (max %d MB). For a multi-page scan, upload one page at a time or reduce the scan resolution.'),
                (int)floor(self::MAX_DOCUMENT_BYTES / (1024 * 1024)),
            ),
            'upload_failed' => xl('The upload did not complete. Please try again.'),
            'unsupported_type' => xl('That file type is not supported. Upload a PDF, PNG, JPEG, or WEBP.'),
            default => xl('Please choose a PDF or image to upload.'),
        };
    }

    private static function resolveMime(string $tmpName, string $declared): string
    {
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($tmpName);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return $declared;
    }
}
