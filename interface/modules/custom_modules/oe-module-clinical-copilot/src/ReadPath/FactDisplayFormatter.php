<?php

/**
 * Human-readable labels for fact rows rendered in doc.html.twig.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;

/**
 * Keeps presentation strings out of Twig conditionals -- the template only
 * renders pre-formatted labels, never raw enum tokens with underscores.
 */
final class FactDisplayFormatter
{
    private function __construct()
    {
    }

    public static function capabilityLabel(string $capability): string
    {
        return match ($capability) {
            'control_proxy' => 'Diabetes control (A1c)',
            'med_response' => 'Medication response',
            'vitals_trend' => 'Vitals trend',
            'overdue_tests' => 'Overdue tests',
            'pending_results' => 'Pending results',
            default => self::titleCaseToken($capability),
        };
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'result' => 'Result',
            'trend_point' => 'Trend point',
            'med_event' => 'Medication event',
            'vital' => 'Vital',
            'overdue_item' => 'Overdue item',
            'pending_order' => 'Pending order',
            'preliminary_result' => 'Preliminary result',
            'exclusion' => 'Excluded',
            'conflict' => 'Conflict',
            'derived_delta' => 'Derived change',
            'derived_count' => 'Derived count',
            'derived_span' => 'Derived span',
            'expected_result_date' => 'Expected result date',
            default => self::titleCaseToken($kind),
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'final' => 'Final',
            'corrected' => 'Corrected',
            'unstated' => 'Unstated',
            'preliminary' => 'Preliminary',
            'excluded' => 'Excluded',
            default => self::titleCaseToken($status),
        };
    }

    public static function flagLabel(string $raw): string
    {
        return match ($raw) {
            'conflict' => 'Conflict',
            'censored' => 'Censored',
            'out_of_range_by_value' => 'Out of range',
            'out_of_range_by_lab_flag' => 'Out of range (lab flag)',
            default => self::parameterizedFlagLabel($raw),
        };
    }

    private static function parameterizedFlagLabel(string $raw): string
    {
        if (preg_match('/^superseded_(\d+)$/', $raw, $matches) === 1) {
            $count = (int)$matches[1];

            return $count === 1 ? 'Superseded (1 older)' : "Superseded ({$count} older)";
        }

        if (str_starts_with($raw, 'excluded_reason:')) {
            $reasonValue = substr($raw, strlen('excluded_reason:'));
            $reason = ExclusionReason::tryFrom($reasonValue);

            return $reason !== null
                ? self::exclusionReasonLabel($reason)
                : self::titleCaseToken($reasonValue);
        }

        return self::titleCaseToken($raw);
    }

    private static function exclusionReasonLabel(ExclusionReason $reason): string
    {
        return match ($reason) {
            ExclusionReason::Unitless => 'Missing units',
            ExclusionReason::UnresultedStatus => 'Unresulted',
            ExclusionReason::UnrecognizedStatus => 'Unrecognized status',
            ExclusionReason::UnparseableValue => 'Unparseable value',
        };
    }

    private static function titleCaseToken(string $token): string
    {
        $normalized = str_replace(['_', '-'], ' ', trim($token));
        if ($normalized === '') {
            return '';
        }

        return ucwords($normalized);
    }
}
