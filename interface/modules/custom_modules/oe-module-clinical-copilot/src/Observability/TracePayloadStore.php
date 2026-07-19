<?php

/**
 * Out-of-row storage for full trace payloads (prompts, tool args/results, findings).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

use OpenEMR\Common\Database\QueryUtils;

/**
 * ARCHITECTURE.md §3.2: "`payload_ref` points at stored request/response
 * payloads ... they live in the module's tables inside the EMR's MySQL."
 * {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan::$payloadRef} is
 * an opaque string the span carries; this class is the ONE place that
 * defines what it points at (`mod_copilot_trace_payload`) and mints it.
 *
 * Existing call sites (U8's `SynthesisReadPath`, U11's `ChatController`)
 * currently never populate `payloadRef` when constructing a span -- capturing
 * the actual prompt/tool-args/tool-result bytes at those call sites is future
 * work for those units to adopt (see the U12 report). This class exists so
 * that adoption is a one-line `$ref = $payloadStore->store(...)` at each call
 * site, plus wiring it onto the `TraceSpan` constructor, with the storage and
 * retrieval contract already built, tested, and ACL-gated at the read side
 * (the dashboard).
 */
final class TracePayloadStore
{
    /**
     * @param array<string, mixed> $payload
     */
    public function store(string $correlationId, string $kind, array $payload): string
    {
        $payloadRef = bin2hex(random_bytes(16));

        QueryUtils::sqlInsert(
            'INSERT INTO `mod_copilot_trace_payload` (`payload_ref`, `correlation_id`, `kind`, `payload_json`)
             VALUES (?, ?, ?, ?)',
            [
                $payloadRef,
                $correlationId,
                $kind,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
        );

        return $payloadRef;
    }

    /**
     * @return array{kind: string, correlation_id: string, payload: array<string, mixed>, created_at: string}|null
     */
    public function fetch(string $payloadRef): ?array
    {
        $row = QueryUtils::querySingleRow(
            'SELECT `correlation_id`, `kind`, `payload_json`, `created_at` FROM `mod_copilot_trace_payload` WHERE `payload_ref` = ?',
            [$payloadRef],
        );

        if (!is_array($row)) {
            return null;
        }

        $decoded = json_decode((string)$row['payload_json'], true);

        return [
            'kind' => (string)$row['kind'],
            'correlation_id' => (string)$row['correlation_id'],
            'payload' => is_array($decoded) ? $decoded : [],
            'created_at' => (string)$row['created_at'],
        ];
    }

    /**
     * @return list<array{kind: string, payload_ref: string, created_at: string}>
     */
    public function forCorrelationId(string $correlationId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT `payload_ref`, `kind`, `created_at` FROM `mod_copilot_trace_payload` WHERE `correlation_id` = ? ORDER BY `id` ASC',
            [$correlationId],
        );

        return array_map(
            static fn (array $row): array => [
                'kind' => (string)$row['kind'],
                'payload_ref' => (string)$row['payload_ref'],
                'created_at' => (string)$row['created_at'],
            ],
            $rows,
        );
    }
}
