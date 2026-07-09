<?php

/**
 * The closed set of Fact "kind" values (ARCHITECTURE_COMPLETE.md Fact object).
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
 * derived_* kinds are computed deterministically by capabilities (never by
 * the LLM, never by the verifier) and cite the raw facts they derive from.
 */
enum FactKind: string
{
    case Result = 'result';
    case TrendPoint = 'trend_point';
    case MedEvent = 'med_event';
    case Vital = 'vital';
    case OverdueItem = 'overdue_item';
    case PendingOrder = 'pending_order';
    case PreliminaryResult = 'preliminary_result';
    case Exclusion = 'exclusion';
    case Conflict = 'conflict';
    case DerivedDelta = 'derived_delta';
    case DerivedCount = 'derived_count';
    case DerivedSpan = 'derived_span';
    case ExpectedResultDate = 'expected_result_date';
}
