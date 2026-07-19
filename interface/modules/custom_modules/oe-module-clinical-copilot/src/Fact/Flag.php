<?php

/**
 * A single member of a Fact's `flags` set.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;

/**
 * `flags` in the Fact schema is a set of strings, not a closed enum: most
 * members are fixed tokens (`conflict`, `censored`, `out_of_range_by_value`,
 * `out_of_range_by_lab_flag`), but `superseded_n` and `excluded_reason:<enum>`
 * carry a parameter. This value object is the single place that knows how to
 * build and parse every shape, so no caller hand-rolls the string format.
 */
final readonly class Flag
{
    private const CONFLICT = 'conflict';
    private const CENSORED = 'censored';
    private const OUT_OF_RANGE_BY_VALUE = 'out_of_range_by_value';
    private const OUT_OF_RANGE_BY_LAB_FLAG = 'out_of_range_by_lab_flag';
    private const SUPERSEDED_PATTERN = '/^superseded_(\d+)$/';
    private const EXCLUDED_REASON_PREFIX = 'excluded_reason:';

    private function __construct(public string $value)
    {
    }

    public static function conflict(): self
    {
        return new self(self::CONFLICT);
    }

    public static function censored(): self
    {
        return new self(self::CENSORED);
    }

    public static function outOfRangeByValue(): self
    {
        return new self(self::OUT_OF_RANGE_BY_VALUE);
    }

    public static function outOfRangeByLabFlag(): self
    {
        return new self(self::OUT_OF_RANGE_BY_LAB_FLAG);
    }

    public static function supersededCount(int $count): self
    {
        if ($count < 1) {
            throw new \DomainException("Superseded count must be >= 1, got {$count}");
        }

        return new self("superseded_{$count}");
    }

    public static function excludedReason(ExclusionReason $reason): self
    {
        return new self(self::EXCLUDED_REASON_PREFIX . $reason->value);
    }

    /**
     * Parses a raw flag string emitted (or read back) from a Fact. Throws on
     * anything that does not match a known shape -- parse, don't validate.
     */
    public static function fromString(string $raw): self
    {
        if (in_array($raw, [self::CONFLICT, self::CENSORED, self::OUT_OF_RANGE_BY_VALUE, self::OUT_OF_RANGE_BY_LAB_FLAG], true)) {
            return new self($raw);
        }

        if (preg_match(self::SUPERSEDED_PATTERN, $raw) === 1) {
            return new self($raw);
        }

        if (str_starts_with($raw, self::EXCLUDED_REASON_PREFIX)) {
            $reasonValue = substr($raw, strlen(self::EXCLUDED_REASON_PREFIX));
            if (ExclusionReason::tryFrom($reasonValue) === null) {
                throw new \InvalidArgumentException("Unrecognized excluded_reason flag value: {$raw}");
            }

            return new self($raw);
        }

        throw new \InvalidArgumentException("Unrecognized flag: {$raw}");
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
