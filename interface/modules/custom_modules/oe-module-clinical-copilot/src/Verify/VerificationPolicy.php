<?php

/**
 * Runtime toggle for the deterministic V1-V6 verifier GATE (enforced by default).
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
 * ENFORCED BY DEFAULT: the deterministic V1-V6 verifier gate blocks, retries
 * (once), and degrades any answer that fails verification -- uncited or unsafe
 * output is never rendered. {@see \OpenEMR\Modules\ClinicalCopilot\Chat\ChatAgent},
 * {@see VerifiedGeneration}, and the supervisor critic stage
 * ({@see \OpenEMR\Modules\ClinicalCopilot\Agent\CriticWorker} via
 * {@see \OpenEMR\Modules\ClinicalCopilot\Agent\Supervisor}) all consult this
 * one policy.
 *
 * For QA/retuning ONLY, the gate can be temporarily relaxed by setting
 * `CLINICAL_COPILOT_VERIFY_ENFORCE=0` (any non-truthy value; truthy values are
 * 1/true/yes/on). Two things intentionally stay ON even when the gate is
 * relaxed:
 *   1. The verifier still RUNS and records its verdicts (observability -- so
 *      the ledger shows what WOULD have failed).
 *   2. The V3 patient-identity (sev-1) freeze -- a cited fact whose pid does
 *      not match the pinned pid still freezes the turn. That is a cross-patient
 *      PHI guard, categorically different from ordinary content strictness, and
 *      must never be silently off in a chart tool.
 *
 * This is a deliberately small, greppable switch; see docs/SECURITY.md
 * finding #1 for the history (the gate was temporarily off by default during
 * retuning and re-enabled for the Week-2 submission).
 */
final class VerificationPolicy
{
    private const ENV_ENFORCE = 'CLINICAL_COPILOT_VERIFY_ENFORCE';

    // Fail-closed gating out of the box. Set CLINICAL_COPILOT_VERIFY_ENFORCE=0
    // to relax for QA/retuning only -- never in an environment serving real
    // traffic.
    private const GATE_ENFORCED_DEFAULT = true;

    private function __construct()
    {
        // static-only
    }

    /**
     * True (the default) when the V1-V6 content gate should block/retry/degrade
     * a failing answer; false only when explicitly relaxed via
     * `CLINICAL_COPILOT_VERIFY_ENFORCE=0` (QA). The env override works in BOTH
     * directions: any non-empty value that is not 1/true/yes/on disables, a
     * truthy value (re-)enables. The sev-1 patient-identity freeze is NOT
     * governed by this -- callers enforce it unconditionally.
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
