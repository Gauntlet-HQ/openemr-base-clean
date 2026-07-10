<?php

/**
 * A single provenance pointer on a Fact -- one physical row/field it was
 * read from.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;

/**
 * Every Fact carries at least one Citation (schema `minItems: 1`); the
 * verifier (U10, V2) resolves each one back to a live row before any prose
 * citing it is allowed to render.
 */
final readonly class Citation
{
    public function __construct(
        public string $table,
        public int $pk,
        public ?string $field,
        public DateSource $dateSource,
    ) {
        if ($table === '') {
            throw new \DomainException('Citation table must not be empty');
        }

        if ($pk <= 0) {
            throw new \DomainException("Citation pk must be positive, got {$pk}");
        }
    }

    /**
     * @return array{table: string, pk: int, field: string|null, date_source: string}
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'pk' => $this->pk,
            'field' => $this->field,
            'date_source' => $this->dateSource->value,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['table']) || !is_string($data['table'])) {
            throw new \InvalidArgumentException('Citation.table must be a string');
        }

        if (!isset($data['pk']) || !is_int($data['pk'])) {
            throw new \InvalidArgumentException('Citation.pk must be an int');
        }

        $field = $data['field'] ?? null;
        if ($field !== null && !is_string($field)) {
            throw new \InvalidArgumentException('Citation.field must be a string or null');
        }

        if (!isset($data['date_source']) || !is_string($data['date_source'])) {
            throw new \InvalidArgumentException('Citation.date_source must be a string');
        }

        $dateSource = DateSource::tryFrom($data['date_source']);
        if ($dateSource === null) {
            throw new \InvalidArgumentException("Unrecognized Citation.date_source: {$data['date_source']}");
        }

        return new self($data['table'], $data['pk'], $field, $dateSource);
    }
}
