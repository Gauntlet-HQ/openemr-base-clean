<?php

/**
 * The critic-gated outcome of the supervisor's composed answer.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

/**
 * Purely runtime state (unit enum, repo standard) mirroring the chat path's
 * three terminal shapes: `Answered` (the critic passed the claims -- or the
 * gate was explicitly QA-relaxed via CLINICAL_COPILOT_VERIFY_ENFORCE=0),
 * `Refused` (the critic rejected an uncited/unsafe draft and the supervisor
 * degraded instead of emitting it), and `FrozenSev1` (a wrong-patient
 * citation -- discarded unconditionally, gate policy notwithstanding).
 */
enum AnswerStatus
{
    case Answered;
    case Refused;
    case FrozenSev1;
}
