<?php

/**
 * mod_copilot_qa.target_type.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Qa;

/**
 * Which ledger a QA verdict scores: a synthesis doc row
 * ({@see \OpenEMR\Modules\ClinicalCopilot\Doc\DocRow}) or a chat turn row
 * ({@see \OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurn}, `assistant` role
 * only -- that is where a narrative's claims live).
 */
enum QaTargetType: string
{
    case Doc = 'doc';
    case ChatTurn = 'chat_turn';
}
