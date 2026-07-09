<?php

/**
 * The full V1-V6 outcome of one Verifier::verify() call.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;

/**
 * `claims` is null only when V1 (schema gate) failed -- there is nothing
 * typed to hand back. `verdicts` always has exactly six entries, one per
 * {@see CheckId} case, in V1-V6 order, regardless of outcome (ARCHITECTURE_COMPLETE.md
 * U10 acceptance criterion).
 */
final readonly class VerificationResult
{
    /**
     * @param list<Verdict> $verdicts
     * @param list<Claim>|null $claims
     */
    public function __construct(
        public array $verdicts,
        public ?array $claims,
    ) {
    }

    public function allPassed(): bool
    {
        foreach ($this->verdicts as $verdict) {
            if (!$verdict->passed) {
                return false;
            }
        }

        return true;
    }

    public function find(CheckId $checkId): ?Verdict
    {
        foreach ($this->verdicts as $verdict) {
            if ($verdict->checkId === $checkId) {
                return $verdict;
            }
        }

        return null;
    }

    /**
     * V3 is sev-1, not a retryable failure (ARCHITECTURE.md §2.3): true only
     * when V3 actually FAILED (never when it was merely skipped because V1
     * failed first -- a schema-gate failure is an ordinary retryable
     * failure, not a patient-identity incident).
     */
    public function hasSev1(): bool
    {
        $v3 = $this->find(CheckId::PatientIdentity);

        return $v3 !== null && !$v3->passed && !$v3->skipped;
    }
}
