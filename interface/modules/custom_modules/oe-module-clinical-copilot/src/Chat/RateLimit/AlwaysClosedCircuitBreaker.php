<?php

/**
 * Default no-op CircuitBreakerInterface (always closed = calls allowed) until U12 wires the real breaker.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\RateLimit;

final class AlwaysClosedCircuitBreaker implements CircuitBreakerInterface
{
    public function isOpen(): bool
    {
        return false;
    }
}
