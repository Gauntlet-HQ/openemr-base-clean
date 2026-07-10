<?php

/**
 * One mod_copilot_chat_turn row's role.
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
 * One physician question produces exactly one {@see self::User} row, zero or
 * more {@see self::Tool} rows (one per tool call this turn made -- each
 * `content` holds that call's arguments and returned facts, so a later turn
 * can reconstruct the full "preloaded facts UNION every tool result this
 * session has ever fetched" set by replaying every {@see self::Tool} row,
 * ARCHITECTURE.md §1.1), and exactly one {@see self::Assistant} row (the
 * final, verified answer -- or a degraded/frozen outcome).
 */
enum ChatTurnRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
