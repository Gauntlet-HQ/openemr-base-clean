<?php

/**
 * The four ways one reduce+verify attempt can end.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

/**
 * Internal to {@see VerifiedGeneration}'s attempt loop -- not part of
 * U8/U11's public contract (they consume {@see VerifiedGenerationResult}
 * instead, which resolves two attempts down to one final outcome).
 */
enum AttemptOutcomeKind
{
    /** Reducer reported the LLM unavailable (I6) -- no verification ran. */
    case LlmUnavailable;

    /** V3 failed -- sev-1, never retried. */
    case Sev1;

    /** All six checks passed. */
    case Passed;

    /** Some check other than V3 failed -- eligible for the one retry (I11). */
    case Failed;
}
