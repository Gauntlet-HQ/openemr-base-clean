<?php

/**
 * Builds a LabContractConfig from `mod_copilot_cadence`.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab\Config;

use OpenEMR\Common\Database\QueryUtils;

/**
 * Reads the versioned config rows `table.sql` seeds (U1): `cadence:acr`,
 * `cadence:a1c`, `cadence:lipids` (monitoring intervals), `unit_conversion`
 * (C4 whitelist), and any `threshold:<analyte>` rows (C3 proof a -- none are
 * seeded yet; {@see LabContractConfig::thresholdByAnalyte} is simply empty
 * until a future config change adds them, which is exactly how a threshold
 * change is meant to ship: a versioned config row, never a code change).
 *
 * Ambiguity resolved here (documented per the U4 report): `cadence:lipids`
 * bundles four LOINC codes (total cholesterol, LDL, HDL, triglycerides)
 * under one monitoring bucket ("lipids"), but the `unit_conversion` config
 * is keyed by two finer-grained analytes ("cholesterol", "triglycerides")
 * because HDL/LDL/total cholesterol share one conversion factor while
 * triglycerides use another. Rather than mis-deriving the conversion
 * bucketing from the cadence rows' own "analyte" label (which would
 * incorrectly try to look up a "lipids" entry in `unit_conversion` that
 * does not exist), unit-conversion LOINC bucketing is a small, explicit,
 * documented map here -- itself just config, easy to move into a real
 * `mod_copilot_cadence` row if/when per-analyte granularity needs to be
 * DB-editable rather than code-fixed.
 */
final class DbLabContractConfigProvider implements LabContractConfigProviderInterface
{
    /**
     * LOINC => unit-conversion analyte bucket. Glucose is included even
     * though no `cadence:*` row monitors it yet -- unit conversion and
     * monitoring cadence are independent config concerns.
     */
    private const UNIT_CONVERSION_LOINC_TO_ANALYTE = [
        '4548-4' => 'a1c',
        '2345-7' => 'glucose',
        '2093-3' => 'cholesterol',
        '18262-6' => 'cholesterol',
        '2085-9' => 'cholesterol',
        '2571-8' => 'triglycerides',
    ];

    public function load(): LabContractConfig
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT `code_set`, `interval`, `config_json`, `version` FROM `mod_copilot_cadence`
             WHERE `code_set` LIKE 'cadence:%' OR `code_set` = 'unit_conversion' OR `code_set` LIKE 'threshold:%'"
        );

        $cadenceIntervalByLoinc = [];
        $cadenceVersionByLoinc = [];
        $canonicalUnitByAnalyte = [];
        $conversionRulesByAnalyte = [];
        $conversionVersion = '';
        $thresholdByAnalyte = [];

        foreach ($rows as $row) {
            $codeSet = (string)$row['code_set'];
            $configJson = $row['config_json'] !== null ? json_decode((string)$row['config_json'], true) : null;

            if (str_starts_with($codeSet, 'cadence:') && is_array($configJson)) {
                $interval = $row['interval'] !== null ? (string)$row['interval'] : null;
                $version = (string)$row['version'];
                $loincCodes = $configJson['loinc'] ?? [];
                if (is_array($loincCodes) && $interval !== null) {
                    foreach ($loincCodes as $loincCode) {
                        if (is_string($loincCode)) {
                            $cadenceIntervalByLoinc[$loincCode] = $interval;
                            $cadenceVersionByLoinc[$loincCode] = $version;
                        }
                    }
                }
                continue;
            }

            if ($codeSet === 'unit_conversion' && is_array($configJson)) {
                $conversionVersion = (string)$row['version'];
                foreach ($configJson as $analyte => $analyteConfig) {
                    if (!is_string($analyte) || !is_array($analyteConfig)) {
                        continue;
                    }
                    $canonical = $analyteConfig['canonical'] ?? null;
                    if (is_string($canonical)) {
                        $canonicalUnitByAnalyte[$analyte] = $canonical;
                    }
                    $from = $analyteConfig['from'] ?? [];
                    if (!is_array($from)) {
                        continue;
                    }
                    foreach ($from as $unit => $rule) {
                        if (!is_string($unit) || !is_array($rule)) {
                            continue;
                        }
                        $conversionRulesByAnalyte[$analyte][$unit] = $this->buildConversionRule($rule);
                    }
                }
                continue;
            }

            if (str_starts_with($codeSet, 'threshold:') && is_array($configJson)) {
                $analyte = substr($codeSet, strlen('threshold:'));
                $value = $configJson['value'] ?? null;
                $direction = $configJson['direction'] ?? null;
                if (is_numeric($value) && is_string($direction)) {
                    $thresholdDirection = ThresholdDirection::tryFrom($direction);
                    if ($thresholdDirection !== null) {
                        $thresholdByAnalyte[$analyte] = new Threshold((float)$value, $thresholdDirection, (string)$row['version']);
                    }
                }
            }
        }

        $loincToAnalyte = self::UNIT_CONVERSION_LOINC_TO_ANALYTE;

        return new LabContractConfig(
            $loincToAnalyte,
            $canonicalUnitByAnalyte,
            $conversionRulesByAnalyte,
            $conversionVersion,
            $cadenceIntervalByLoinc,
            $cadenceVersionByLoinc,
            $thresholdByAnalyte,
        );
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function buildConversionRule(array $rule): ConversionRule
    {
        if (isset($rule['multiplier']) && is_numeric($rule['multiplier'])) {
            return ConversionRule::multiplier((float)$rule['multiplier']);
        }

        if (isset($rule['formula']) && is_string($rule['formula']) && str_starts_with($rule['formula'], 'ngsp_percent')) {
            return ConversionRule::ifccToNgsp();
        }

        throw new \DomainException('Unrecognized unit_conversion rule shape in mod_copilot_cadence config_json');
    }
}
