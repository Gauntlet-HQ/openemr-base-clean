<?php

/**
 * Lab contract C4: per-analyte canonical units + conversion whitelist.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Lab\Config\LabContractConfig;

/**
 * Pure, DB-independent (config is injected). "No unit, no math -- strict"
 * (C4/T9): an empty unit, an unrecognized unit, or an analyte this reader
 * has no canonical-unit config for at all are all treated identically --
 * excluded from thresholds/trends, never guessed.
 */
final class UnitConverter
{
    private function __construct()
    {
        // static-only
    }

    public static function convert(
        string $analyte,
        string $unitOriginal,
        ?float $rawValue,
        LabContractConfig $config,
    ): UnitConversionResult {
        $canonical = $config->canonicalUnitFor($analyte);

        if ($unitOriginal === '' || $canonical === null) {
            return new UnitConversionResult($unitOriginal, null, null, null, true);
        }

        // Lab feeds/devices report units in inconsistent case ("MG/DL" vs the
        // seeded canonical "mg/dL"); a value already in canonical units modulo
        // case needs no conversion and must not be excluded as "unitless".
        // (OutOfRangeEvaluator already lower-cases the abnormal flag for the
        // same reason; units were the one lab-supplied string left unnormalised.)
        if (strcasecmp($unitOriginal, $canonical) === 0) {
            return new UnitConversionResult($unitOriginal, $canonical, $rawValue, null, false);
        }

        $rule = $config->conversionRuleFor($analyte, $unitOriginal);
        if ($rule === null) {
            return new UnitConversionResult($unitOriginal, null, null, null, true);
        }

        $converted = $rawValue !== null ? $rule->apply($rawValue) : null;

        return new UnitConversionResult($unitOriginal, $canonical, $converted, $config->conversionVersion, false);
    }
}
