<?php

/**
 * Versioned configuration the lab contract (C1-C4) needs to evaluate a slice.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab\Config;

/**
 * This is the injection seam that keeps {@see \OpenEMR\Modules\ClinicalCopilot\Lab\LabRowProcessor}
 * (the pure C1-C4 engine) unit-testable without a database: isolated tests
 * construct a LabContractConfig by hand with in-memory arrays, while
 * {@see DbLabContractConfigProvider} builds one from `mod_copilot_cadence`
 * for real reads.
 *
 * Deliberately a plain, immutable value object rather than an interface --
 * there is nothing to mock, only data to substitute.
 */
final readonly class LabContractConfig
{
    /**
     * @param array<string, string> $loincToAnalyte LOINC code => analyte key used for unit-conversion lookups (e.g. "a1c", "glucose", "cholesterol", "triglycerides"). Note: this is a finer-grained bucketing than mod_copilot_cadence's own monitoring buckets (its "lipids" cadence row covers four LOINC codes that map to two different conversion analytes here, "cholesterol" and "triglycerides") -- see DbLabContractConfigProvider's docblock.
     * @param array<string, string> $canonicalUnitByAnalyte analyte key => canonical unit string (e.g. "a1c" => "%")
     * @param array<string, array<string, ConversionRule>> $conversionRulesByAnalyte analyte key => (unit_original => ConversionRule)
     * @param string $conversionVersion C4 whitelist version; a digest input
     * @param array<string, string> $cadenceIntervalByLoinc LOINC code => ISO-8601 duration (e.g. "P1Y"), for OverdueTests (U5)
     * @param array<string, string> $cadenceVersionByLoinc LOINC code => cadence config version, a digest input
     * @param array<string, Threshold> $thresholdByAnalyte analyte key => Threshold (C3 proof a); empty until threshold config rows exist
     */
    public function __construct(
        public array $loincToAnalyte,
        public array $canonicalUnitByAnalyte,
        public array $conversionRulesByAnalyte,
        public string $conversionVersion,
        public array $cadenceIntervalByLoinc,
        public array $cadenceVersionByLoinc,
        public array $thresholdByAnalyte,
    ) {
    }

    public function analyteForLoinc(string $loincCode): ?string
    {
        return $this->loincToAnalyte[$loincCode] ?? null;
    }

    public function canonicalUnitFor(string $analyte): ?string
    {
        return $this->canonicalUnitByAnalyte[$analyte] ?? null;
    }

    public function conversionRuleFor(string $analyte, string $unitOriginal): ?ConversionRule
    {
        $rules = $this->conversionRulesByAnalyte[$analyte] ?? [];
        if (isset($rules[$unitOriginal])) {
            return $rules[$unitOriginal];
        }

        // Lab feeds/devices report units in inconsistent case (e.g. "MMOL/L"
        // vs the whitelist's "mmol/L"); fall back to a case-insensitive match
        // so a convertible value isn't wrongly excluded as unitless.
        foreach ($rules as $unit => $rule) {
            if (strcasecmp($unit, $unitOriginal) === 0) {
                return $rule;
            }
        }

        return null;
    }

    public function thresholdFor(string $analyte): ?Threshold
    {
        return $this->thresholdByAnalyte[$analyte] ?? null;
    }
}
