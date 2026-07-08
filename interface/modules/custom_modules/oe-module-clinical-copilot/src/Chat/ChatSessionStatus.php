<?php

/**
 * A chat session's lifecycle status.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

/**
 * `mod_copilot_chat_session.status` (ARCHITECTURE_COMPLETE.md "Module-owned
 * tables"). {@see self::Frozen} is the verifier's V3 sev-1 trip
 * (ARCHITECTURE.md §2.3): "the session is frozen ... preserved as evidence,
 * never resumed." There is no path back from Frozen to Active in this
 * module's code -- a frozen session is a dead end by design.
 */
enum ChatSessionStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
}
