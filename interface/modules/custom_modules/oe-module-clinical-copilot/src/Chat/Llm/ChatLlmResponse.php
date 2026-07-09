<?php

/**
 * One round's response from a tool-calling ChatLlmClientInterface implementation.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Llm;

use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallRequest;

/**
 * Exactly one of {@see self::$toolCalls} / {@see self::$finalClaimsJson} is
 * meaningful per round, discriminated by {@see self::isToolCall()}: the
 * model either wants to navigate further (I13 -- it emits structured
 * *requests*, {@see \OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop} is what
 * actually executes them) or it is ready to answer, in which case
 * `finalClaimsJson` is the SAME §2.1 claim-list JSON shape the synthesis
 * path produces (constrained by {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\Claim::jsonSchema()}),
 * unparsed here -- U10's {@see \OpenEMR\Modules\ClinicalCopilot\Verify\Verifier}
 * is the only place that parses and gates it.
 */
final readonly class ChatLlmResponse
{
    /**
     * @param list<ToolCallRequest>|null $toolCalls
     */
    private function __construct(
        public ?array $toolCalls,
        public ?string $finalClaimsJson,
        public string $modelVersion,
        public int $tokensIn,
        public int $tokensOut,
        public int $latencyMs,
    ) {
    }

    /**
     * @param list<ToolCallRequest> $toolCalls
     */
    public static function toolCalls(array $toolCalls, string $modelVersion, int $tokensIn, int $tokensOut, int $latencyMs): self
    {
        return new self($toolCalls, null, $modelVersion, $tokensIn, $tokensOut, $latencyMs);
    }

    public static function finalAnswer(string $claimsJson, string $modelVersion, int $tokensIn, int $tokensOut, int $latencyMs): self
    {
        return new self(null, $claimsJson, $modelVersion, $tokensIn, $tokensOut, $latencyMs);
    }

    public function isToolCall(): bool
    {
        return $this->toolCalls !== null && $this->toolCalls !== [];
    }
}
