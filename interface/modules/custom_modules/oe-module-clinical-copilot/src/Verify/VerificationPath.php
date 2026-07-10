<?php

/**
 * Which surface produced the claims being verified.
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
 * V6(ii) (every conflict-flagged fact in the input must be cited by >= 1
 * claim) applies ONLY on {@see self::Synthesis} -- the doc enumerates the
 * full fact set, so "every conflict fact was addressed" is checkable there.
 * A chat answer is scoped to the physician's question, so the same
 * completeness check would be a false positive (ARCHITECTURE.md §2.2, V6
 * row) -- {@see self::Chat} runs only V6(i).
 */
enum VerificationPath
{
    case Synthesis;
    case Chat;
}
