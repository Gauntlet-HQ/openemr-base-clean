<?php

/**
 * A chat turn's confidence: a deterministic proxy derived from the verifier outcome.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;

/**
 * Confidence here is NOT the model's self-reported certainty (which is
 * unreliable and un-auditable) -- it is a deterministic function of how the
 * turn cleared the deterministic V1-V6 gate ({@see ChatAgent}): a turn that
 * verified on the first attempt is high-confidence; one that only verified
 * after the single fail-closed regeneration is reduced; a degraded turn (the
 * verifier could not clear an answer, or the LLM was unreachable) carries no
 * meaningful confidence; a frozen turn (V3 patient-identity sev-1) is blocked.
 *
 * Pure over the {@see ChatAnswer} -- same answer always yields the same
 * confidence -- so it is safe to persist on the turn, surface to the UI, and
 * emit to the audit/observability log for oversight.
 */
final readonly class ChatTurnConfidence
{
    private function __construct(
        public float $score,
        public string $label,
    ) {
    }

    public static function fromAnswer(ChatAnswer $answer): self
    {
        // A V3 sev-1 freeze is a patient-identity incident, not a low-quality
        // answer -- it is blocked outright, distinct from an ordinary degrade.
        if ($answer->frozen) {
            return new self(0.0, 'blocked');
        }

        if ($answer->verifyStatus === VerifyStatus::Degraded) {
            $unreachable = in_array($answer->degradedReason, ['llm_unavailable', 'circuit_breaker_open'], true);

            return $unreachable ? new self(0.0, 'unavailable') : new self(0.2, 'low');
        }

        // Passed: full confidence when it verified on the first attempt;
        // reduced when it took the one regeneration to clear the checks.
        return $answer->attempts >= 2 ? new self(0.7, 'medium') : new self(1.0, 'high');
    }

    /**
     * @return array{score: float, label: string}
     */
    public function toArray(): array
    {
        return ['score' => $this->score, 'label' => $this->label];
    }
}
