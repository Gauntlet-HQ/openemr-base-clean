<?php

/**
 * The five deterministic Capabilities that produce Facts.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact\Enum;

/**
 * ARCHITECTURE_COMPLETE.md "Capabilities" table. A capability without a
 * traceable USERS.md use case is rejected by construction (T13); this enum
 * is intentionally closed to the five shipped in v1.
 */
enum Capability: string
{
    case ControlProxy = 'control_proxy';
    case MedResponse = 'med_response';
    case VitalsTrend = 'vitals_trend';
    case OverdueTests = 'overdue_tests';
    case PendingResults = 'pending_results';
}
