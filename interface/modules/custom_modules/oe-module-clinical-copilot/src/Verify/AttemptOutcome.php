<?php

/**
 * The result of one reduce+verify attempt inside VerifiedGeneration's loop.
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
use OpenEMR\Modules\ClinicalCopilot\Reduce\RedactionMap;

/**
 * Internal to {@see VerifiedGeneration} -- see {@see AttemptOutcomeKind} for
 * why this is not itself U8/U11's contract type.
 */
final readonly class AttemptOutcome
{
    /**
     * @param list<Verdict> $verdicts
     * @param list<Claim>|null $claims
     */
    private function __construct(
        public AttemptOutcomeKind $kind,
        public array $verdicts,
        public ?array $claims,
        public ?Sev1Signal $sev1Signal,
        public ?RedactionMap $redactionMap,
        public ReduceUsage $usage,
    ) {
    }

    public static function llmUnavailable(): self
    {
        return new self(AttemptOutcomeKind::LlmUnavailable, [], null, null, null, ReduceUsage::none());
    }

    /**
     * @param list<Verdict> $verdicts
     */
    public static function sev1(array $verdicts, Sev1Signal $signal, ReduceUsage $usage): self
    {
        return new self(AttemptOutcomeKind::Sev1, $verdicts, null, $signal, null, $usage);
    }

    /**
     * @param list<Verdict> $verdicts
     * @param list<Claim> $claims
     */
    public static function passed(array $verdicts, array $claims, RedactionMap $redactionMap, ReduceUsage $usage): self
    {
        return new self(AttemptOutcomeKind::Passed, $verdicts, $claims, null, $redactionMap, $usage);
    }

    /**
     * @param list<Verdict> $verdicts
     */
    public static function failed(array $verdicts, ReduceUsage $usage): self
    {
        return new self(AttemptOutcomeKind::Failed, $verdicts, null, null, null, $usage);
    }
}
