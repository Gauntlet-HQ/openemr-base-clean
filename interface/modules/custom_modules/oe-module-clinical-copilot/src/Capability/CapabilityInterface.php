<?php

/**
 * The common contract every U5 Capability implements.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;

/**
 * ARCHITECTURE_COMPLETE.md "Capabilities": each capability declares a code
 * set, a table slice, a threshold, an output schema, and an invariant, and
 * implements one deterministic entry point that turns a pid into typed,
 * cited Facts. Every implementing class documents its own code set / slice /
 * threshold / invariant in its class docblock (ARCHITECTURE_COMPLETE.md
 * "Capabilities" table + USERS.md traceability) rather than as separate
 * interface methods -- the one behavioral contract every caller (U7 reduce,
 * U8 read path, U11 chat tools) needs is {@see self::extract()}.
 *
 * I2 (facts never cached) holds by construction here: {@see self::extract()}
 * takes only a pid and always re-reads from the host tables (via U4's
 * LabSliceReader or a host service) -- there is no cache-shaped seam to
 * accidentally serve a stale fact from.
 */
interface CapabilityInterface
{
    /**
     * The capability's identity in the Fact schema's `capability` enum
     * (ARCHITECTURE_COMPLETE.md "Fact object"). Every Fact this capability
     * emits (presented or excluded) carries this value.
     */
    public function capability(): Capability;

    /**
     * The capability's `capability_version` (ARCHITECTURE_COMPLETE.md "Fact
     * object") -- a digest input (I1). Bump this string, never the parsing
     * semantics in place, whenever this capability's code set, re-kinding
     * rules, or derived-fact math change (T13 extension model).
     */
    public function capabilityVersion(): string;

    /**
     * Deterministically extracts this capability's Facts for one patient,
     * fresh, every call (I2). Never accepts anything but a pid (I10 -- pid
     * injection/pinning is the caller's job, not this method's).
     */
    public function extract(int $pid): CapabilityResult;
}
