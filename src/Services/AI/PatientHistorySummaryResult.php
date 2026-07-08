<?php

/**
 * Immutable result of a patient-history summarization request.
 *
 * Either the patient has no history on record (isNewPatient === true, with
 * the summary set to the "new patient" label) or an AI-generated summary of
 * the most recent history is provided.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI;

final readonly class PatientHistorySummaryResult
{
    public const NEW_PATIENT_LABEL = 'new patient';

    private function __construct(
        public bool $isNewPatient,
        public string $summary,
    ) {
    }

    public static function newPatient(): self
    {
        return new self(true, self::NEW_PATIENT_LABEL);
    }

    public static function fromSummary(string $summary): self
    {
        return new self(false, $summary);
    }

    /**
     * @return array{isNewPatient: bool, summary: string}
     */
    public function toArray(): array
    {
        return [
            'isNewPatient' => $this->isNewPatient,
            'summary' => $this->summary,
        ];
    }
}
