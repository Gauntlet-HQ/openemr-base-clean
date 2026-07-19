<?php

/**
 * What the supervisor assembled: the routing decision, extracted facts, evidence, and the critic-gated answer.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

use OpenEMR\Modules\ClinicalCopilot\Ingest\ParsedExtraction;
use OpenEMR\Modules\ClinicalCopilot\Rag\EvidenceSnippet;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verdict;

/**
 * `routed` is the inspectable routing decision — which workers ran, in order —
 * mirroring the trace spans. `extraction` (document/patient facts) and
 * `evidence` (guideline snippets) are deliberately separate fields: the two
 * evidence classes never merge into one bag, so a downstream renderer keeps
 * patient-record facts and guideline evidence in distinct sections by
 * construction (the doc's separation rule).
 *
 * The answer fields are the critic-gated composition outcome. `answerStatus`
 * is null when no answer was composed (no composer wired, or nothing to
 * answer); otherwise `answer` carries the verified claims ONLY on
 * {@see AnswerStatus::Answered} — a {@see AnswerStatus::Refused} or
 * {@see AnswerStatus::FrozenSev1} result always has a null `answer` and a
 * `refusalMessage`, so a rejected draft's uncited/unsafe text is
 * structurally unreachable by any renderer. `verdicts` is the critic's full
 * V1-V6 ledger for the attempt (recorded on every outcome, including pass).
 */
final readonly class SupervisorResult
{
    /**
     * @param list<WorkerName> $routed
     * @param list<EvidenceSnippet> $evidence
     * @param list<Claim>|null $answer
     * @param list<Verdict> $verdicts
     */
    public function __construct(
        public array $routed,
        public ?ParsedExtraction $extraction,
        public array $evidence,
        public ?AnswerStatus $answerStatus = null,
        public ?array $answer = null,
        public array $verdicts = [],
        public ?string $refusalMessage = null,
    ) {
    }

    public function routedTo(WorkerName $worker): bool
    {
        return in_array($worker, $this->routed, true);
    }

    public function answerBlocked(): bool
    {
        return $this->answerStatus === AnswerStatus::Refused
            || $this->answerStatus === AnswerStatus::FrozenSev1;
    }
}
