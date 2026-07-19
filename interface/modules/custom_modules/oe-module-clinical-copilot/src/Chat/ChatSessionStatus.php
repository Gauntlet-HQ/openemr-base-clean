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
 *
 * {@see self::Expired} is the ordinary end of life: a session auto-closes
 * once it has been idle past {@see ChatSessionStore::IDLE_TIMEOUT_MINUTES}
 * (see {@see ChatSessionStore::expireIdleForUser()}). Like Frozen it is
 * terminal -- resuming a conversation always mints a fresh session -- but it
 * carries no incident semantics. It exists so abandoned sessions stop
 * counting against the per-user active-session cap instead of pinning a slot
 * forever and eventually blocking every new turn before the LLM is reached.
 */
enum ChatSessionStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
    case Expired = 'expired';
}
