<?php

/**
 * Raised when VLM output fails the strict extraction schema — it never persists.
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
 * The schema is the source of truth, not the model. When
 * {@see ExtractionSchema::validate()} rejects a payload, the extraction is
 * abandoned rather than persisted — the endpoint degrades to manual entry.
 * Carries the structured error list (field-level, PHI-free) for the trace and
 * the `schema_valid` eval rubric, never for user-facing display.
 */
final class SchemaValidationException extends \RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public readonly array $errors,
        public readonly DocType $docType,
    ) {
        $count = count($errors);
        parent::__construct("Extraction for {$docType->value} failed schema validation ({$count} error(s))");
    }
}
