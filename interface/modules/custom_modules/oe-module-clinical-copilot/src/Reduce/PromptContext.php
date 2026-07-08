<?php

/**
 * Per-request metadata that shapes a reduce prompt without being a Fact itself.
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
 * `promptVersion` is a digest input (ARCHITECTURE_COMPLETE.md "Compute
 * model"): bump it on any change to the system instructions or
 * {@see Claim::jsonSchema()} so every affected doc regenerates (E5).
 * `model` is the pinned Gemini version string (T18) -- also folded into
 * `promptVersion` by the caller building it, not by this class.
 */
final readonly class PromptContext
{
    public function __construct(
        public string $docType,
        public string $promptVersion,
        public string $model = 'gemini-2.5-pro',
        public float $temperature = 0.0,
        public int $maxOutputTokens = 8192,
    ) {
        if ($this->docType === '') {
            throw new \DomainException('PromptContext.docType must not be empty');
        }

        if ($this->promptVersion === '') {
            throw new \DomainException('PromptContext.promptVersion must not be empty');
        }
    }
}
