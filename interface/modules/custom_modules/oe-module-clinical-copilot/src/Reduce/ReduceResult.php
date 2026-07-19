<?php

/**
 * The outcome of one Reducer::reduce() call.
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
 * Exactly two shapes, both explicit -- never a silent empty result (I6):
 * {@see self::generated()} carries the model's RAW, unvalidated claims JSON
 * (U10's {@see \OpenEMR\Modules\ClinicalCopilot\Verify\ClaimSchema} parses and
 * gates it -- this class does not); {@see self::isAvailable()} being false
 * IS the degradation signal callers (U8/U10/U11) must check and act on by
 * rendering facts-only -- Reducer surfaces it, it does not swallow it.
 */
final readonly class ReduceResult
{
    private function __construct(
        private bool $available,
        public ?string $rawClaimsJson,
        public ?string $modelVersion,
        public ?int $tokensIn,
        public ?int $tokensOut,
        public ?int $latencyMs,
        public ?RedactionMap $redactionMap,
        public ?string $unavailableReason,
        // Rich, developer-facing description of an unavailable failure
        // (reason category + provider/transport detail) -- see
        // {@see LlmUnavailableException::detail()}. Null on the available path.
        public ?string $unavailableDetail = null,
    ) {
    }

    public static function generated(
        string $rawClaimsJson,
        string $modelVersion,
        int $tokensIn,
        int $tokensOut,
        int $latencyMs,
        RedactionMap $redactionMap,
    ): self {
        return new self(true, $rawClaimsJson, $modelVersion, $tokensIn, $tokensOut, $latencyMs, $redactionMap, null);
    }

    /**
     * @param string $reason one of {@see LlmUnavailableException}'s REASON_* constants
     * @param string|null $detail rich developer-facing cause, for logs/traces/debug return value
     */
    public static function unavailable(string $reason, ?string $detail = null): self
    {
        return new self(false, null, null, null, null, null, null, $reason, $detail);
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }
}
