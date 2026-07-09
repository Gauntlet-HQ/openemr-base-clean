<?php

/**
 * One claim in the §2.1 output contract -- shared between U7 (produced raw
 * by the reduce pass) and U10 (validated and checked by the verifier).
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
 * ARCHITECTURE.md §2.1: `{text, claim_type, citation_ids[], numeric_values[],
 * flags[]}` plus ordering/emphasis metadata. This is the ONE place both the
 * reduce pass's schema (the Vertex `responseSchema` built from
 * {@see self::jsonSchema()}) and the verifier's parser
 * ({@see \OpenEMR\Modules\ClinicalCopilot\Verify\ClaimSchema}, V1) agree on
 * the shape -- neither side hand-rolls it twice.
 *
 * `order` is the claim's position in the model's own output list (0-based) --
 * the "ordering" half of the contract, letting rendering preserve the
 * narrative's intended sequence even after verification may drop late claims
 * on the discard path. `emphasis` is the free-form "highlight this" signal
 * the model may attach (e.g. `high`/`normal`); it is NOT a closed enum here
 * because verification never gates on it (§2.4: misleading emphasis is a
 * NAMED, un-caught residual, not a V-check) -- it is carried through to
 * rendering and to the §2.5 advisory second-pass reviewer (U12) as-is.
 */
final readonly class Claim
{
    /**
     * @param list<string> $citationIds
     * @param list<float> $numericValues
     * @param list<string> $flags
     */
    public function __construct(
        public string $text,
        public ClaimType $claimType,
        public array $citationIds,
        public array $numericValues,
        public array $flags,
        public int $order,
        public ?string $emphasis = null,
    ) {
        if (trim($this->text) === '') {
            throw new \DomainException('Claim.text must not be empty');
        }

        if ($this->order < 0) {
            throw new \DomainException("Claim.order must be >= 0, got {$this->order}");
        }
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /**
     * @return array{text: string, claim_type: string, citation_ids: list<string>, numeric_values: list<float>, flags: list<string>, order: int, emphasis: string|null}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'claim_type' => $this->claimType->value,
            'citation_ids' => $this->citationIds,
            'numeric_values' => $this->numericValues,
            'flags' => $this->flags,
            'order' => $this->order,
            'emphasis' => $this->emphasis,
        ];
    }

    /**
     * Parses one already-schema-valid claim array (see
     * {@see \OpenEMR\Modules\ClinicalCopilot\Verify\ClaimSchema}, which
     * validates the raw JSON structurally before this is ever called) into a
     * typed Claim. Parse, don't validate: a caller holding a Claim never
     * needs to re-check its shape.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['text', 'claim_type', 'citation_ids', 'numeric_values', 'flags'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new \InvalidArgumentException("Claim.{$required} is required");
            }
        }

        if (!is_string($data['text'])) {
            throw new \InvalidArgumentException('Claim.text must be a string');
        }

        if (!is_string($data['claim_type'])) {
            throw new \InvalidArgumentException('Claim.claim_type must be a string');
        }
        $claimType = ClaimType::tryFrom($data['claim_type']);
        if ($claimType === null) {
            throw new \InvalidArgumentException("Unrecognized Claim.claim_type: {$data['claim_type']}");
        }

        $citationIds = self::stringList($data['citation_ids'], 'Claim.citation_ids');
        $flags = self::stringList($data['flags'], 'Claim.flags');

        if (!is_array($data['numeric_values'])) {
            throw new \InvalidArgumentException('Claim.numeric_values must be an array');
        }
        $numericValues = [];
        foreach ($data['numeric_values'] as $numericValue) {
            if (!is_float($numericValue) && !is_int($numericValue)) {
                throw new \InvalidArgumentException('Claim.numeric_values entries must be numbers');
            }
            $numericValues[] = (float)$numericValue;
        }

        $order = $data['order'] ?? 0;
        if (!is_int($order)) {
            throw new \InvalidArgumentException('Claim.order must be an int');
        }

        $emphasis = $data['emphasis'] ?? null;
        if ($emphasis !== null && !is_string($emphasis)) {
            throw new \InvalidArgumentException('Claim.emphasis must be a string or null');
        }

        return new self($data['text'], $claimType, $citationIds, $numericValues, $flags, $order, $emphasis);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, string $fieldName): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$fieldName} must be an array");
        }

        $list = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException("{$fieldName} entries must be strings");
            }
            $list[] = $item;
        }

        return $list;
    }

    /**
     * The JSON Schema handed to the provider as `responseSchema` (Vertex
     * structured output, T18) AND used by
     * {@see \OpenEMR\Modules\ClinicalCopilot\Verify\ClaimSchema} (V1) to
     * reject malformed output before any semantic check runs. A single
     * source for both sides of the contract.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Clinical Co-Pilot Claim List',
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['text', 'claim_type', 'citation_ids', 'numeric_values', 'flags'],
                'properties' => [
                    'text' => ['type' => 'string', 'minLength' => 1],
                    'claim_type' => [
                        'type' => 'string',
                        'enum' => array_map(static fn (ClaimType $c): string => $c->value, ClaimType::cases()),
                    ],
                    'citation_ids' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]],
                    'numeric_values' => ['type' => 'array', 'items' => ['type' => 'number']],
                    'flags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'order' => ['type' => 'integer', 'minimum' => 0],
                    'emphasis' => ['type' => ['string', 'null']],
                ],
            ],
        ];
    }
}
