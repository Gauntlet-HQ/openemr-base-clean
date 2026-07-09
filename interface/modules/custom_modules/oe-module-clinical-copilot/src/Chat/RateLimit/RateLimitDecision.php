<?php

/**
 * The outcome of one RateLimiterInterface check.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\RateLimit;

/**
 * `reason` is a short, user-safe string ("max active sessions reached") --
 * {@see \OpenEMR\Modules\ClinicalCopilot\Controller\ChatController} surfaces
 * it verbatim on a rejected turn, never a stack trace or internal detail.
 */
final readonly class RateLimitDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $reason,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
