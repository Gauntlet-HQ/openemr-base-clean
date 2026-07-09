<?php

/**
 * Immutable result of a diagnosis-suggestion request.
 *
 * Either the patient has too little clinical data on record to reason about
 * (hasSufficientData === false, with the suggestions set to the
 * "insufficient data" label) or an AI-generated set of differential-diagnosis
 * suggestions is provided.
 *
 * The suggestions are advisory decision support only; they never constitute a
 * diagnosis and must be reviewed by a qualified clinician.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI;

final readonly class DiagnosisSuggestionResult
{
    public const INSUFFICIENT_DATA_LABEL = 'insufficient data';

    private function __construct(
        public bool $hasSufficientData,
        public string $suggestions,
    ) {
    }

    public static function insufficientData(): self
    {
        return new self(false, self::INSUFFICIENT_DATA_LABEL);
    }

    public static function fromSuggestions(string $suggestions): self
    {
        return new self(true, $suggestions);
    }

    /**
     * @return array{hasSufficientData: bool, suggestions: string}
     */
    public function toArray(): array
    {
        return [
            'hasSufficientData' => $this->hasSufficientData,
            'suggestions' => $this->suggestions,
        ];
    }
}
