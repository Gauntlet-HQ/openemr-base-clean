<?php

/**
 * The closed `claim_type` enum from the §2.1 output contract.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

/**
 * ARCHITECTURE.md §2.1/§2.2 (V2): a claim's declared type governs whether
 * zero citations is legal, but only for the four conversational types below
 * -- {@see self::isZeroCitationEligible()} is a necessary, not sufficient,
 * condition; V2's re-check additionally inspects claim text/numeric_values
 * for clinical content regardless of the declared type (a "greeting" claim
 * that smuggles in a lab value must still cite).
 */
enum ClaimType: string
{
    case Greeting = 'greeting';
    case Refusal = 'refusal';
    case RetrievalStatus = 'retrieval_status';
    case UncertaintyStatement = 'uncertainty_statement';
    case LabValue = 'lab_value';
    case Trend = 'trend';
    case MedEvent = 'med_event';
    case Vital = 'vital';
    case Overdue = 'overdue';
    case Pending = 'pending';
    case Exclusion = 'exclusion';
    case Conflict = 'conflict';

    /**
     * The declared-type half of V2's zero-citation allowance
     * (ARCHITECTURE.md §2.2, V2 row). Does NOT by itself mean a claim of this
     * type is exempt from citing -- see this enum's docblock.
     */
    public function isZeroCitationEligible(): bool
    {
        // Enumerated without a `default` (CLAUDE.md) so a future claim_type
        // forces an explicit true/false decision here rather than silently
        // defaulting to "must cite" -- this is V2-critical citation logic.
        return match ($this) {
            self::Greeting, self::Refusal, self::RetrievalStatus, self::UncertaintyStatement => true,
            self::LabValue, self::Trend, self::MedEvent, self::Vital,
            self::Overdue, self::Pending, self::Exclusion, self::Conflict => false,
        };
    }
}
