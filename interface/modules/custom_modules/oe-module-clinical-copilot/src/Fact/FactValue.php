<?php

/**
 * The `value` object present on result/trend_point/vital-kind Facts.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;

/**
 * `parsed === null` means no numeric claim is permitted (C3): the caller may
 * still present `raw` verbatim, but nothing downstream may plot, threshold,
 * or trend it as a number. A non-`None` comparator means the value is
 * censored: `parsed` is a bound, not an exact reading.
 */
final readonly class FactValue
{
    public function __construct(
        public string $raw,
        public ?float $parsed,
        public Comparator $comparator,
        public string $unitOriginal,
        public ?string $unitCanonical,
        public ?string $conversionVersion,
    ) {
        if ($this->comparator->isCensored() && $this->parsed === null) {
            throw new \DomainException('A censored comparator requires a parsed bound value');
        }

        if ($this->conversionVersion !== null && $this->unitCanonical === null) {
            throw new \DomainException('conversion_version requires unit_canonical to be set');
        }
    }

    /**
     * @return array{raw: string, parsed: float|null, comparator: string, unit_original: string, unit_canonical: string|null, conversion_version: string|null}
     */
    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'parsed' => $this->parsed,
            'comparator' => $this->comparator->value,
            'unit_original' => $this->unitOriginal,
            'unit_canonical' => $this->unitCanonical,
            'conversion_version' => $this->conversionVersion,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['raw']) || !is_string($data['raw'])) {
            throw new \InvalidArgumentException('FactValue.raw must be a string');
        }

        $parsed = $data['parsed'] ?? null;
        if ($parsed !== null && !is_float($parsed) && !is_int($parsed)) {
            throw new \InvalidArgumentException('FactValue.parsed must be a number or null');
        }

        if (!isset($data['comparator']) || !is_string($data['comparator'])) {
            throw new \InvalidArgumentException('FactValue.comparator must be a string');
        }

        $comparator = Comparator::tryFrom($data['comparator']);
        if ($comparator === null) {
            throw new \InvalidArgumentException("Unrecognized FactValue.comparator: {$data['comparator']}");
        }

        if (!isset($data['unit_original']) || !is_string($data['unit_original'])) {
            throw new \InvalidArgumentException('FactValue.unit_original must be a string');
        }

        $unitCanonical = $data['unit_canonical'] ?? null;
        if ($unitCanonical !== null && !is_string($unitCanonical)) {
            throw new \InvalidArgumentException('FactValue.unit_canonical must be a string or null');
        }

        $conversionVersion = $data['conversion_version'] ?? null;
        if ($conversionVersion !== null && !is_string($conversionVersion)) {
            throw new \InvalidArgumentException('FactValue.conversion_version must be a string or null');
        }

        return new self(
            $data['raw'],
            $parsed !== null ? (float)$parsed : null,
            $comparator,
            $data['unit_original'],
            $unitCanonical,
            $conversionVersion,
        );
    }
}
