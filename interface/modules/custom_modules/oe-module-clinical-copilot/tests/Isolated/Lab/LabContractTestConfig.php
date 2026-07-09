<?php

/**
 * Shared in-memory LabContractConfig for the U4 isolated test suite.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Lab;

use OpenEMR\Modules\ClinicalCopilot\Lab\Config\ConversionRule;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\LabContractConfig;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\Threshold;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\ThresholdDirection;

/**
 * Mirrors the config `table.sql`'s `mod_copilot_cadence` seed rows would
 * produce via {@see \OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider},
 * but built directly in memory -- no database. This is the injection seam
 * that makes C1-C4 unit-testable: every test in this suite constructs its
 * own {@see \OpenEMR\Modules\ClinicalCopilot\Lab\RawLabRow} rows and passes
 * this config straight to {@see \OpenEMR\Modules\ClinicalCopilot\Lab\LabRowProcessor::process()}.
 */
final class LabContractTestConfig
{
    private function __construct()
    {
        // static-only
    }

    public static function default(): LabContractConfig
    {
        return new LabContractConfig(
            loincToAnalyte: [
                '4548-4' => 'a1c',
                '2345-7' => 'glucose',
                '2093-3' => 'cholesterol',
                '18262-6' => 'cholesterol',
                '2085-9' => 'cholesterol',
                '2571-8' => 'triglycerides',
            ],
            canonicalUnitByAnalyte: [
                'a1c' => '%',
                'glucose' => 'mg/dL',
                'cholesterol' => 'mg/dL',
                'triglycerides' => 'mg/dL',
            ],
            conversionRulesByAnalyte: [
                'a1c' => ['mmol/mol' => ConversionRule::ifccToNgsp()],
                'glucose' => ['mmol/L' => ConversionRule::multiplier(18.018)],
                'cholesterol' => ['mmol/L' => ConversionRule::multiplier(38.67)],
                'triglycerides' => ['mmol/L' => ConversionRule::multiplier(88.57)],
            ],
            conversionVersion: 'v1',
            cadenceIntervalByLoinc: [
                '4548-4' => 'P3M',
                '14957-5' => 'P1Y',
                '2093-3' => 'P1Y',
            ],
            cadenceVersionByLoinc: [
                '4548-4' => 'v1',
                '14957-5' => 'v1',
                '2093-3' => 'v1',
            ],
            thresholdByAnalyte: [],
        );
    }

    /**
     * A config carrying one A1c threshold, for OutOfRangeEvaluator tests.
     */
    public static function withA1cHighThreshold(float $value, string $version = 'v1'): LabContractConfig
    {
        $base = self::default();

        return new LabContractConfig(
            $base->loincToAnalyte,
            $base->canonicalUnitByAnalyte,
            $base->conversionRulesByAnalyte,
            $base->conversionVersion,
            $base->cadenceIntervalByLoinc,
            $base->cadenceVersionByLoinc,
            ['a1c' => new Threshold($value, ThresholdDirection::High, $version)],
        );
    }
}
