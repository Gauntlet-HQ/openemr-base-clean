<?php

/**
 * Runtime toggle for the deterministic V1-V6 verifier GATE (temporary QA switch).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

use OpenEMR\Modules\ClinicalCopilot\Config\LlmEnv;

/**
 * TEMPORARILY DISABLED for QA: the deterministic V1-V6 verifier gate was
 * rejecting otherwise-usable model answers ("couldn't produce a verifiable
 * answer") while it is being retuned. When the gate is NOT enforced,
 * {@see \OpenEMR\Modules\ClinicalCopilot\Chat\ChatAgent} and
 * {@see VerifiedGeneration} return the produced answer as-is instead of
 * gating, running the fail-closed LLM retry, and degrading.
 *
 * Two things intentionally stay ON even when the gate is off:
 *   1. The verifier still RUNS and records its verdicts (observability -- so
 *      the ledger shows what WOULD have failed).
 *   2. The V3 patient-identity (sev-1) freeze -- a cited fact whose pid does
 *      not match the pinned pid still freezes the turn. That is a cross-patient
 *      PHI guard, categorically different from ordinary content strictness, and
 *      must never be silently off in a chart tool.
 *
 * Re-enable the full gate by setting `CLINICAL_COPILOT_VERIFY_ENFORCE=1`
 * (truthy: 1/true/yes/on) or by flipping {@see self::GATE_ENFORCED_DEFAULT}
 * back to true. This is a deliberately small, greppable switch so the gate can
 * be restored in one edit.
 */
final class VerificationPolicy
{
    private const ENV_ENFORCE = 'CLINICAL_COPILOT_VERIFY_ENFORCE';

    // TEMP: default OFF while the verifier is retuned. Flip to true (or delete
    // this class and its two call sites) to restore fail-closed gating.
    private const GATE_ENFORCED_DEFAULT = false;

    private function __construct()
    {
        // static-only
    }

    /**
     * True when the V1-V6 content gate should block/retry/degrade a failing
     * answer; false (the current default) when a produced answer flows through
     * unblocked. The sev-1 patient-identity freeze is NOT governed by this --
     * callers enforce it unconditionally.
     */
    public static function gateEnforced(): bool
    {
        $override = LlmEnv::getString(self::ENV_ENFORCE);
        if ($override !== '') {
            return in_array(strtolower($override), ['1', 'true', 'yes', 'on'], true);
        }

        return self::GATE_ENFORCED_DEFAULT;
    }
}
