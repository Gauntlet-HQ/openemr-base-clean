<?php

/**
 * The result of C4 unit canonicalization/conversion for one value.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

final readonly class UnitConversionResult
{
    public function __construct(
        public string $unitOriginal,
        public ?string $unitCanonical,
        public ?float $convertedValue,
        public ?string $conversionVersion,
        /**
         * True when C4's "no unit, no math" applies: empty or unrecognized
         * unit (or an analyte with no canonical-unit config at all). The
         * value must be excluded from thresholds/trends and counted in the
         * per-analyte unitless-exclusion rate.
         */
        public bool $excluded,
    ) {
    }
}
