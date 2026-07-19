<?php

/**
 * Numeric comparator for censored lab values (lab contract C3).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact\Enum;

/**
 * `None` means an exact (uncensored) parsed value. Any other case means the
 * value is censored: support only claims the direction proves, never exact.
 */
enum Comparator: string
{
    case None = 'none';
    case Lt = 'lt';
    case Lte = 'lte';
    case Gt = 'gt';
    case Gte = 'gte';

    public function isCensored(): bool
    {
        return $this !== self::None;
    }

    /**
     * Maps the raw grammar token (C3) to a Comparator case.
     */
    public static function fromToken(string $token): self
    {
        return match ($token) {
            '' => self::None,
            '<' => self::Lt,
            '<=' => self::Lte,
            '>' => self::Gt,
            '>=' => self::Gte,
            default => throw new \InvalidArgumentException("Unrecognized comparator token: {$token}"),
        };
    }
}
