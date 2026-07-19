<?php

/**
 * UploadedDocument::classifyRejection — the upload boundary names WHY it refused.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Ingest;

use OpenEMR\Modules\ClinicalCopilot\Ingest\UploadedDocument;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: an oversized scanned document (which a Gemini inline
 * request would reject at the provider) silently degrading to a blank draft the
 * physician cannot tell apart from "the model found nothing." The boundary must
 * refuse it up front AND name the reason, so the staff member gets an actionable
 * message rather than the generic "choose a file." classifyRejection() carries
 * that logic purely from $_FILES metadata (no file read), so it is exercised
 * here without a real HTTP upload.
 */
final class UploadedDocumentRejectionTest extends TestCase
{
    public function testNullOrNonArrayEntryReadsAsNoFile(): void
    {
        self::assertSame('no_file', UploadedDocument::classifyRejection(null));
        self::assertSame('no_file', UploadedDocument::classifyRejection('not-an-array'));
    }

    public function testNoFileErrorReadsAsNoFile(): void
    {
        self::assertSame('no_file', UploadedDocument::classifyRejection([
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ]));
    }

    public function testPhpIniAndFormSizeLimitsReadAsTooLarge(): void
    {
        self::assertSame('too_large', UploadedDocument::classifyRejection([
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 0,
        ]));
        self::assertSame('too_large', UploadedDocument::classifyRejection([
            'error' => UPLOAD_ERR_FORM_SIZE,
            'size' => 0,
        ]));
    }

    public function testOversizedButOtherwiseOkReadsAsTooLarge(): void
    {
        self::assertSame('too_large', UploadedDocument::classifyRejection([
            'error' => UPLOAD_ERR_OK,
            'size' => UploadedDocument::MAX_DOCUMENT_BYTES + 1,
        ]));
    }

    public function testPartialUploadReadsAsUploadFailed(): void
    {
        self::assertSame('upload_failed', UploadedDocument::classifyRejection([
            'error' => UPLOAD_ERR_PARTIAL,
            'size' => 1024,
        ]));
    }

    public function testWithinSizeAndOkFallsThroughToUnsupportedType(): void
    {
        // Metadata alone cannot see the content type (the authoritative sniff is
        // server-side in fromFilesEntry), so an in-bounds OK upload that still
        // reached the null path must be an unsupported type.
        self::assertSame('unsupported_type', UploadedDocument::classifyRejection([
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
        ]));
    }

    public function testTheSizeGuardSitsJustUnderTheGeminiInlineCeiling(): void
    {
        // Raw cap + ~33% base64 inflation must stay under the ~20 MB inline
        // request ceiling, with headroom for the prompt + response schema.
        $inflated = UploadedDocument::MAX_DOCUMENT_BYTES * 4 / 3;
        self::assertLessThan(20 * 1024 * 1024, $inflated);
    }
}
