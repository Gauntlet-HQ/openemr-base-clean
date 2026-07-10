<?php

/**
 * Pure, deterministic (LLM-free) QA metrics: narrative density and fact utilization.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Qa;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;

/**
 * docs/build-notes.md "U12 additions": "Deterministic wherever possible:
 * density/utilization/drilldown are pure trace math (no LLM). Only
 * concurrence/salience use the Flash verdict." This class is that pure math
 * -- no I/O, no randomness, testable with plain fixtures, called both by
 * {@see QaReviewer} (to persist one number per target) and by the dashboard
 * (to recompute/aggregate across many targets without re-reading claims).
 */
final class QaMetrics
{
    private function __construct()
    {
        // static-only
    }

    /**
     * `narrative_density_ratio` (build-notes.md): unique cited clinical
     * entities (distinct `fact_id`s any claim cites) divided by narrative
     * length (word count across every claim's text -- including
     * zero-citation conversational claims, since they are still part of what
     * the physician reads). Zero-length narrative (no claims at all, e.g. a
     * fully degraded doc) is defined as a density of 0.0, not a division
     * error.
     *
     * @param list<Claim> $claims
     */
    public static function densityRatio(array $claims): float
    {
        if ($claims === []) {
            return 0.0;
        }

        $citedFactIds = [];
        $wordCount = 0;
        foreach ($claims as $claim) {
            foreach ($claim->citationIds as $citationId) {
                $citedFactIds[$citationId] = true;
            }
            $words = preg_split('/\s+/u', trim($claim->text));
            $wordCount += $words !== false ? count(array_filter($words, static fn (string $w): bool => $w !== '')) : 0;
        }

        if ($wordCount === 0) {
            return 0.0;
        }

        return round(count($citedFactIds) / $wordCount, 4);
    }

    /**
     * `fact_utilization_rate` (build-notes.md, verbatim): "% of extracted
     * facts left uncited" -- i.e. the UNCITED fraction, a fluff/capability-
     * tuning signal (a high value means the reduce pass is being handed
     * facts it never uses). No facts extracted at all is defined as 0.0
     * (nothing was left uncited because nothing existed to cite).
     *
     * @param list<Fact> $facts the attempt's own extraction-time fact set
     * @param list<Claim> $claims
     */
    public static function factUtilizationRate(array $facts, array $claims): float
    {
        if ($facts === []) {
            return 0.0;
        }

        $citedFactIds = [];
        foreach ($claims as $claim) {
            foreach ($claim->citationIds as $citationId) {
                $citedFactIds[$citationId] = true;
            }
        }

        $uncited = 0;
        foreach ($facts as $fact) {
            if (!isset($citedFactIds[$fact->factId])) {
                $uncited++;
            }
        }

        return round($uncited / count($facts), 4);
    }
}
