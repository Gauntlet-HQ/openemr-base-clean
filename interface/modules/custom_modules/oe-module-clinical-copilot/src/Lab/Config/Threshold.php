<?php

/**
 * A versioned numeric out-of-range threshold for one analyte (C3 proof a).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab\Config;

final readonly class Threshold
{
    public function __construct(
        public float $value,
        public ThresholdDirection $direction,
        public string $version,
    ) {
    }

    public function isOutOfRange(float $parsed): bool
    {
        return match ($this->direction) {
            ThresholdDirection::High => $parsed > $this->value,
            ThresholdDirection::Low => $parsed < $this->value,
        };
    }
}
