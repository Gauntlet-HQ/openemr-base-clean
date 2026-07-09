<?php

/**
 * One span emitted by the read path, shaped 1:1 onto mod_copilot_trace's columns.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

/**
 * I12: every invocation leaves a trace, correlation ID on every span. This
 * DTO is deliberately shaped field-for-field like `mod_copilot_trace`
 * (ARCHITECTURE_COMPLETE.md "Module-owned tables") so U12's real writer can
 * implement {@see TraceRecorderInterface::record()} as a single INSERT with
 * no translation layer -- the seam U12 plugs into without retrofitting any
 * of this read path's call sites.
 *
 * `kind` is one of the table's documented values: extract | digest |
 * cache_lookup | llm_reduce | verify | render (this read path never emits
 * chat_turn/tool_call/warm/alert_eval -- those belong to U11/U9/U12).
 * `status` is one of ok | error | retried | degraded.
 */
final readonly class TraceSpan
{
    public function __construct(
        public string $correlationId,
        public string $spanId,
        public ?string $parentSpanId,
        public string $kind,
        public \DateTimeImmutable $startedAt,
        public ?int $durationMs,
        public string $status,
        public int $pid,
        public ?int $userId = null,
        public ?string $errorClass = null,
        public ?string $errorDetail = null,
        public ?string $model = null,
        public ?int $tokensIn = null,
        public ?int $tokensOut = null,
        public ?float $costUsd = null,
        public ?string $payloadRef = null,
    ) {
    }

    /**
     * Mints a fresh, unique span id (distinct from the request-scoped
     * correlation id) -- a lightweight random token, not a UUID, since
     * span/parent-span linkage within one correlation id never needs
     * global uniqueness beyond this one request.
     */
    public static function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
