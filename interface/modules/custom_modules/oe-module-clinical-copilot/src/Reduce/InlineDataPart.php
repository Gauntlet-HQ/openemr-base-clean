<?php

/**
 * One inline binary document part (a PDF page image / scanned form) for a multimodal generation.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

/**
 * Provider-agnostic on purpose (like {@see PromptRequest} itself): the Gemini
 * mapping turns this into a `{inlineData: {mimeType, data}}` content part, but
 * a stub test client never has to know that. This is the seam Week 2 vision
 * extraction rides — a lab PDF or intake form is base64-encoded into one of
 * these and attached to a {@see PromptRequest} alongside the extraction
 * instructions and the strict response schema.
 */
final readonly class InlineDataPart
{
    /**
     * @param non-empty-string $mimeType e.g. `application/pdf`, `image/png`
     * @param non-empty-string $base64Data the document bytes, base64-encoded
     */
    public function __construct(
        public string $mimeType,
        public string $base64Data,
    ) {
        if ($mimeType === '') {
            throw new \DomainException('InlineDataPart.mimeType must not be empty');
        }

        if ($base64Data === '') {
            throw new \DomainException('InlineDataPart.base64Data must not be empty');
        }
    }

    /**
     * Convenience factory from raw bytes.
     */
    public static function fromBytes(string $mimeType, string $bytes): self
    {
        if ($bytes === '') {
            throw new \DomainException('InlineDataPart.fromBytes requires non-empty bytes');
        }

        return new self($mimeType, base64_encode($bytes));
    }
}
