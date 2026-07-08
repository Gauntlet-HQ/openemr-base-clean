<?php

/**
 * The direct identifiers ARCHITECTURE.md §4 requires be tokenized before egress.
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
 * Deliberately NOT part of the {@see \OpenEMR\Modules\ClinicalCopilot\Fact\Fact}
 * schema -- Facts never carry name/MRN/DOB/address (the schema's only patient
 * reference is the integer `pid`). This DTO exists solely so
 * {@see PromptAssembler} can render a natural-language patient header (e.g. a
 * greeting claim addressing the physician) and so {@see Redactor} has
 * something concrete to scan for and tokenize before any of it reaches the
 * provider. Honest scope (§4): quasi-identifiers -- clinical dates, rare lab
 * values -- are NOT covered here and remain in the fact payload; this is
 * minimization of the four direct identifiers, not full de-identification.
 */
final readonly class PatientIdentifiers
{
    public function __construct(
        public string $name,
        public string $mrn,
        public string $dob,
        public string $address,
    ) {
    }

    /**
     * @return array<string, string> field name => raw value, empty values omitted
     */
    public function nonEmptyFields(): array
    {
        $fields = ['name' => $this->name, 'mrn' => $this->mrn, 'dob' => $this->dob, 'address' => $this->address];

        return array_filter($fields, static fn (string $value): bool => $value !== '');
    }
}
