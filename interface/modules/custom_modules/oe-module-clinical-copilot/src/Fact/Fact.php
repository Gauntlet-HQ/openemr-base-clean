<?php

/**
 * The one canonical Fact shape (ARCHITECTURE_COMPLETE.md "Fact object").
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;

/**
 * Every capability output, tool result, digest input, LLM prompt fact, and
 * verifier check operates on this one shape. `fact_id` is computed by
 * {@see FactId::compute()} from (capability, kind, citations, canonical
 * value) -- NOT from pid, capability_version, status, flags, or dates -- so a
 * preloaded fact and a later re-fetch of the same datum with a corrected
 * value never collide (T19), while an irrelevant metadata change never
 * mints a new id.
 *
 * `flags` is a set: constructed here as a de-duplicated list ordered by
 * insertion, but two Facts with the same flags in different order are
 * treated as equal by the canonical serializer (which sorts them).
 */
final readonly class Fact
{
    /** @var list<Flag> */
    public array $flags;

    /** @var list<Citation> */
    public array $citations;

    /**
     * @param list<Flag> $flags
     * @param list<Citation> $citations
     */
    public function __construct(
        public string $factId,
        public Capability $capability,
        public string $capabilityVersion,
        public FactKind $kind,
        public int $pid,
        public ?\DateTimeImmutable $clinicalDate,
        public DateSource $dateSource,
        public ?FactValue $value,
        public FactStatus $status,
        array $flags,
        array $citations,
    ) {
        if ($this->pid <= 0) {
            throw new \DomainException("Fact.pid must be positive, got {$this->pid}");
        }

        if ($citations === []) {
            throw new \DomainException('Fact.citations must contain at least one citation');
        }

        $this->citations = array_values($citations);

        $seen = [];
        $deduped = [];
        foreach ($flags as $flag) {
            if (!isset($seen[$flag->value])) {
                $seen[$flag->value] = true;
                $deduped[] = $flag;
            }
        }
        $this->flags = $deduped;
    }

    public function hasFlag(Flag $flag): bool
    {
        foreach ($this->flags as $candidate) {
            if ($candidate->equals($flag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     fact_id: string,
     *     capability: string,
     *     capability_version: string,
     *     kind: string,
     *     pid: int,
     *     clinical_date: string|null,
     *     date_source: string,
     *     value: array{raw: string, parsed: float|null, comparator: string, unit_original: string, unit_canonical: string|null, conversion_version: string|null}|null,
     *     status: string,
     *     flags: list<string>,
     *     citations: list<array{table: string, pk: int, field: string|null, date_source: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'fact_id' => $this->factId,
            'capability' => $this->capability->value,
            'capability_version' => $this->capabilityVersion,
            'kind' => $this->kind->value,
            'pid' => $this->pid,
            'clinical_date' => $this->clinicalDate?->format('Y-m-d'),
            'date_source' => $this->dateSource->value,
            'value' => $this->value?->toArray(),
            'status' => $this->status->value,
            'flags' => array_map(static fn (Flag $f): string => $f->value, $this->flags),
            'citations' => array_map(static fn (Citation $c): array => $c->toArray(), $this->citations),
        ];
    }

    /**
     * Parses a raw array (e.g. decoded JSON) into a Fact, validating every
     * field against the schema shape. Parse, don't validate: callers get a
     * Fact whose invariants already hold, or an exception.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['fact_id', 'capability', 'capability_version', 'kind', 'pid', 'date_source', 'status', 'flags', 'citations'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new \InvalidArgumentException("Fact.{$required} is required");
            }
        }

        if (!is_string($data['fact_id']) || $data['fact_id'] === '') {
            throw new \InvalidArgumentException('Fact.fact_id must be a non-empty string');
        }

        if (!is_string($data['capability'])) {
            throw new \InvalidArgumentException('Fact.capability must be a string');
        }
        $capability = Capability::tryFrom($data['capability']);
        if ($capability === null) {
            throw new \InvalidArgumentException("Unrecognized Fact.capability: {$data['capability']}");
        }

        if (!is_string($data['capability_version'])) {
            throw new \InvalidArgumentException('Fact.capability_version must be a string');
        }

        if (!is_string($data['kind'])) {
            throw new \InvalidArgumentException('Fact.kind must be a string');
        }
        $kind = FactKind::tryFrom($data['kind']);
        if ($kind === null) {
            throw new \InvalidArgumentException("Unrecognized Fact.kind: {$data['kind']}");
        }

        if (!is_int($data['pid'])) {
            throw new \InvalidArgumentException('Fact.pid must be an int');
        }

        $clinicalDateRaw = $data['clinical_date'] ?? null;
        $clinicalDate = null;
        if ($clinicalDateRaw !== null) {
            if (!is_string($clinicalDateRaw)) {
                throw new \InvalidArgumentException('Fact.clinical_date must be a string or null');
            }
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $clinicalDateRaw);
            if ($parsedDate === false) {
                throw new \InvalidArgumentException("Fact.clinical_date is not ISO-8601 date: {$clinicalDateRaw}");
            }
            $clinicalDate = $parsedDate;
        }

        if (!is_string($data['date_source'])) {
            throw new \InvalidArgumentException('Fact.date_source must be a string');
        }
        $dateSource = DateSource::tryFrom($data['date_source']);
        if ($dateSource === null) {
            throw new \InvalidArgumentException("Unrecognized Fact.date_source: {$data['date_source']}");
        }

        $valueRaw = $data['value'] ?? null;
        $value = null;
        if ($valueRaw !== null) {
            if (!is_array($valueRaw)) {
                throw new \InvalidArgumentException('Fact.value must be an array or null');
            }
            /** @var array<string, mixed> $valueRaw */
            $value = FactValue::fromArray($valueRaw);
        }

        if (!is_string($data['status'])) {
            throw new \InvalidArgumentException('Fact.status must be a string');
        }
        $status = FactStatus::tryFrom($data['status']);
        if ($status === null) {
            throw new \InvalidArgumentException("Unrecognized Fact.status: {$data['status']}");
        }

        if (!is_array($data['flags'])) {
            throw new \InvalidArgumentException('Fact.flags must be an array');
        }
        $flags = [];
        foreach ($data['flags'] as $flagValue) {
            if (!is_string($flagValue)) {
                throw new \InvalidArgumentException('Fact.flags entries must be strings');
            }
            $flags[] = Flag::fromString($flagValue);
        }

        if (!is_array($data['citations']) || $data['citations'] === []) {
            throw new \InvalidArgumentException('Fact.citations must be a non-empty array');
        }
        $citations = [];
        foreach ($data['citations'] as $citationData) {
            if (!is_array($citationData)) {
                throw new \InvalidArgumentException('Fact.citations entries must be arrays');
            }
            /** @var array<string, mixed> $citationData */
            $citations[] = Citation::fromArray($citationData);
        }

        return new self(
            $data['fact_id'],
            $capability,
            $data['capability_version'],
            $kind,
            $data['pid'],
            $clinicalDate,
            $dateSource,
            $value,
            $status,
            $flags,
            $citations,
        );
    }
}
