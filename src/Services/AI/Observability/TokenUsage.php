<?php

/**
 * Immutable token-usage counts for a single AI transaction.
 *
 * Mirrors the `usage` object returned by the OpenAI Chat Completions API
 * (prompt / completion / total tokens). Used by the observability layer to
 * record how many tokens each transaction consumed and, together with
 * TokenPricing, how much it cost.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI\Observability;

final readonly class TokenUsage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
    ) {
        if ($promptTokens < 0 || $completionTokens < 0 || $totalTokens < 0) {
            throw new \InvalidArgumentException('Token counts cannot be negative.');
        }
    }

    /**
     * Build usage from a decoded OpenAI response body.
     *
     * Tolerates a missing or partial `usage` block by defaulting counts to
     * zero, and derives the total when the provider omits it.
     *
     * @param array<array-key, mixed> $response
     */
    public static function fromResponse(array $response): self
    {
        $usage = $response['usage'] ?? null;
        if (!is_array($usage)) {
            return self::none();
        }

        $prompt = self::intValue($usage, 'prompt_tokens');
        $completion = self::intValue($usage, 'completion_tokens');
        $total = self::intValue($usage, 'total_tokens');
        if ($total === 0) {
            $total = $prompt + $completion;
        }

        return new self($prompt, $completion, $total);
    }

    public static function none(): self
    {
        return new self(0, 0, 0);
    }

    public function isEmpty(): bool
    {
        return $this->totalTokens === 0
            && $this->promptTokens === 0
            && $this->completionTokens === 0;
    }

    /**
     * @param array<array-key, mixed> $usage
     */
    private static function intValue(array $usage, string $key): int
    {
        $value = $usage[$key] ?? null;

        return is_int($value) ? $value : 0;
    }
}
