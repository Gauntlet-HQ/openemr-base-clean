<?php

/**
 * Resolves a Fact Citation to a clickable deep link, where a route is confidently known.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;

/**
 * ARCHITECTURE.md §2.5: "every citation in prose click-throughs to its row
 * ... a deep link into OpenEMR where sensible, else a tooltip with table +
 * pk." Every citation always carries a hover label ({@see self::label()});
 * a subset also resolves to a real, verified host route
 * ({@see self::url()}).
 *
 * Honest scope: only `procedure_order` resolves to a verified deep link
 * here (`interface/orders/single_order_results.php?orderid=`, confirmed
 * against that file's own `$_GET['orderid']` read) -- its citation pk IS a
 * `procedure_order_id`, the exact parameter that page expects. Citations on
 * `procedure_report`/`procedure_result`/`prescriptions`/`lists`/`form_vitals`
 * fall back to the tooltip: this fork has no single verified
 * record-level deep-link route for those tables in the time this build unit
 * had to confirm one (a wrong link in a clinical UI is worse than a
 * tooltip). Extending this map is additive -- add a case, keep the
 * fallback for everything unmapped.
 */
final class ChartLinkResolver
{
    /**
     * Human-readable "what/where" label shown on every citation regardless
     * of whether a deep link exists -- also the full content of the tooltip
     * fallback.
     */
    public static function label(Citation $citation): string
    {
        $suffix = $citation->field !== null ? ".{$citation->field}" : '';

        return self::tableLabel($citation->table) . " #{$citation->pk}{$suffix}";
    }

    public static function visitLabel(?ScheduledPatientRow $visit): ?string
    {
        if ($visit === null) {
            return null;
        }

        return "{$visit->appointmentTitle} · {$visit->appointmentTime}";
    }

    private static function tableLabel(string $table): string
    {
        return match ($table) {
            'procedure_result' => 'Lab result',
            'procedure_order' => 'Lab order',
            'procedure_report' => 'Lab report',
            'prescriptions' => 'Prescription',
            'form_vitals' => 'Vitals',
            'lists' => 'Problem/med list',
            default => $table,
        };
    }

    /**
     * A verified, working deep link for this citation's table, or null when
     * none is confidently known (the tooltip-only fallback applies).
     */
    public static function url(Citation $citation, string $webRoot): ?string
    {
        return match ($citation->table) {
            'procedure_order' => $webRoot . '/interface/orders/single_order_results.php?orderid=' . $citation->pk,
            default => null,
        };
    }
}
