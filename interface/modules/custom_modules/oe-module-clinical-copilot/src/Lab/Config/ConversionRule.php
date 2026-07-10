<?php

/**
 * A single whitelisted unit-conversion rule (C4).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab\Config;

final readonly class ConversionRule
{
    private function __construct(
        public ConversionType $type,
        public ?float $multiplier,
    ) {
    }

    public static function multiplier(float $multiplier): self
    {
        return new self(ConversionType::Multiplier, $multiplier);
    }

    public static function ifccToNgsp(): self
    {
        return new self(ConversionType::IfccToNgsp, null);
    }

    /**
     * Applies the rule to a raw numeric value, returning the canonical value.
     */
    public function apply(float $rawValue): float
    {
        return match ($this->type) {
            ConversionType::Multiplier => $rawValue * ($this->multiplier ?? throw new \DomainException('Multiplier rule missing its factor')),
            // NGSP % = (IFCC mmol/mol / 10.929) + 2.15 (IFCC-NGSP master equation).
            ConversionType::IfccToNgsp => round(($rawValue / 10.929) + 2.15, 1),
        };
    }
}
