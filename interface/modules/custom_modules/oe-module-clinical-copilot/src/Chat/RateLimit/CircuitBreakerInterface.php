<?php

/**
 * The seam U12's LLM spend circuit breaker plugs into (ARCHITECTURE.md §3.7).
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
 * "Breaker state lives in the module config table and is checked before
 * every LLM call. Open breaker => chat degrades to the facts browser with a
 * banner" (ARCHITECTURE.md §3.7). {@see \OpenEMR\Modules\ClinicalCopilot\Controller\ChatController}
 * checks {@see self::isOpen()} BEFORE ever constructing an agent loop for a
 * turn -- an open breaker skips the LLM entirely (never spends a call just
 * to immediately discard it) and renders the same facts-browser response
 * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException}
 * degradation produces, by design (from chat's perspective "the model can't
 * be reached" and "we have deliberately stopped calling it" are the same
 * observable outcome). {@see AlwaysClosedCircuitBreaker} (closed = calls
 * allowed) is the default no-op until U12 wires the real
 * `mod_copilot_cadence` `rate_limit_breaker` config-backed implementation.
 */
interface CircuitBreakerInterface
{
    public function isOpen(): bool;
}
