<?php

/**
 * Pure, deterministic serialization of Facts (and arbitrary config/version
 * payloads) into stable bytes.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

/**
 * The single canonicalization used everywhere bytes must be stable: the
 * digest (U3), the LLM reduce prompt (U7), and prompt-assembly tests all
 * call {@see self::serializeFacts()} over the same fact set and get
 * byte-identical output. Contract (ARCHITECTURE_COMPLETE.md, "Canonical
 * serialization"): stable sort keys, ISO-8601 dates, normalized decimals
 * (whole-number floats stay floats -- JSON_PRESERVE_ZERO_FRACTION guards the
 * U2-noted bug where `8.0` silently becomes `8`), no map-order leakage.
 *
 * Pure function: no I/O, no randomness, no clock reads. Same input always
 * produces the same bytes (E6).
 */
final class CanonicalSerializer
{
    private const JSON_FLAGS = JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    private function __construct()
    {
        // static-only
    }

    /**
     * Deep-canonicalizes an arbitrary value: associative arrays get their
     * keys sorted (recursively); list arrays keep their existing order
     * (callers that need order-independence, e.g. facts or flags, must
     * impose their own canonical order before calling this -- see
     * {@see self::canonicalizeFacts()}); scalars and null pass through.
     */
    public static function canonicalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalizeValue(...), $value);
        }

        ksort($value, SORT_STRING);
        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = self::canonicalizeValue($item);
        }

        return $canonical;
    }

    /**
     * Encodes an already-canonicalized value tree to stable JSON bytes.
     */
    public static function serializeValue(mixed $value): string
    {
        return json_encode(self::canonicalizeValue($value), self::JSON_FLAGS);
    }

    /**
     * Canonicalizes a list of Facts into a deterministic list of arrays:
     * each Fact's `flags` set is sorted (sets have no order), each Fact's
     * `citations` list is sorted (a Fact's identity does not depend on the
     * order citations were collected in), and the outer list is sorted by
     * `fact_id` -- the one field guaranteed to be a stable, content-derived
     * identity regardless of extraction order. This is what makes the
     * serializer's determinism hold "regardless of input order" (E6/U3
     * isolated test).
     *
     * @param list<Fact> $facts
     * @return list<array<string, mixed>>
     */
    public static function canonicalizeFacts(array $facts): array
    {
        $arrays = [];
        foreach ($facts as $fact) {
            $arr = $fact->toArray();
            sort($arr['flags'], SORT_STRING);
            usort(
                $arr['citations'],
                static function (array $a, array $b): int {
                    return [$a['table'], $a['pk'], $a['field'] ?? '', $a['date_source']]
                        <=> [$b['table'], $b['pk'], $b['field'] ?? '', $b['date_source']];
                }
            );
            $arrays[] = $arr;
        }

        usort($arrays, static fn (array $a, array $b): int => $a['fact_id'] <=> $b['fact_id']);

        return array_map(
            /** @return array<string, mixed> */
            static fn (array $arr): array => self::canonicalizeValue($arr),
            $arrays
        );
    }

    /**
     * Serializes a list of Facts to the exact bytes shared by the digest
     * (U3) and the LLM prompt assembly (U7). Order-independent: passing the
     * same facts in a different order yields identical output.
     *
     * @param list<Fact> $facts
     */
    public static function serializeFacts(array $facts): string
    {
        return json_encode(self::canonicalizeFacts($facts), self::JSON_FLAGS);
    }
}
