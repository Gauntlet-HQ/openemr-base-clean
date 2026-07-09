<?php

/**
 * The five chat tools, schema'd and pinned (ARCHITECTURE.md §1.2).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Tool;

/**
 * ARCHITECTURE.md §1.2's tool table: the chat agent's tools ARE the five U5
 * capabilities, wrapped one-to-one -- no sixth tool, no free-form query
 * surface (T14's "rejected" list). String values are the exact tool names
 * the model is given in its function-calling declarations and the exact
 * names {@see ToolCatalog} keys definitions by.
 */
enum ToolName: string
{
    case GetControlTrend = 'get_control_trend';
    case GetMedHistory = 'get_med_history';
    case GetVitalsTrend = 'get_vitals_trend';
    case GetOverdue = 'get_overdue';
    case GetPending = 'get_pending';
}
