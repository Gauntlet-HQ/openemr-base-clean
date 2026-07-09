<?php

/**
 * Bounds a fact set to a recent, prompt-sized window before it is sent to the LLM.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;

/**
 * A long chart is dominated by trend points: a 20-year patient carries ~440
 * facts (~240 KB, ~60K tokens) and the whole block is re-serialized into EVERY
 * LLM round -- synthesis and each chat round alike -- which makes the model
 * call slow enough to hit the Vertex request timeout (the narrative never
 * finishes) and stacks up across a multi-round chat turn (2nd/3rd message dies
 * with a client network error). The panel already decided the last ~20 draws
 * per lab are what a physician reads; the LLM needs no more than that either.
 *
 * This trims ONLY what the model is shown, not the fact set the digest and the
 * verifier operate on (those stay full, so a claim citing a windowed fact
 * still resolves -- the window is a subset). Two entry points, because the two
 * surfaces have different budgets:
 *
 * - {@see self::forChat()} -- LEANER. The whole block is re-sent on every round
 *   of a multi-round chat turn, so per dense (capability, unit) series it keeps
 *   the last {@see self::MAX_AGE_MONTHS} months OR the last
 *   {@see self::MIN_PER_SERIES}, whichever is MORE (a union, never both capping
 *   each other) -- 40 visits in two years all survive; a patient not seen in
 *   years still gets their last 15.
 * - {@see self::forNarrative()} -- RICHER. The synthesis is a one-shot call, so
 *   it gets the last {@see self::MAX_NARRATIVE_VISITS} visits' worth of trend
 *   history instead of the tighter 2-year window.
 *
 * Both keep every sparse, decision-bearing fact (results, medications, overdue
 * and pending items, preliminary results, exclusions, conflicts, and the
 * derived_count / derived_span summaries that describe the WHOLE series in one
 * fact) regardless of age -- a five-year-old medication may still be active.
 *
 * TODO(config): the three tuning knobs (MAX_AGE_MONTHS, MIN_PER_SERIES,
 * MAX_NARRATIVE_VISITS) are the intended surface of a future per-office control
 * panel -- how much chat history to send, how to construct the narrative --
 * so a practice can dial recency/verbosity itself. Constants for now.
 *
 * Pure and deterministic: same facts in, same subset out.
 */
final class PromptFactWindow
{
    /** Chat window: keep dense trend history from the last this-many months... */
    public const MAX_AGE_MONTHS = 24;

    /** ...OR at least the last this-many per series, whichever is MORE (never both-capped). */
    public const MIN_PER_SERIES = 15;

    /** Narrative window: the synthesis is a one-shot call, so it gets a richer slice -- the last N visits. */
    public const MAX_NARRATIVE_VISITS = 20;

    private function __construct()
    {
        // static-only
    }

    /**
     * The CHAT window: leaner, because the whole fact block is re-sent on every
     * round of a multi-round turn. Per dense (capability, unit) series, keep a
     * fact if it is within the last {@see self::MAX_AGE_MONTHS} months OR among
     * the last {@see self::MIN_PER_SERIES} of that series -- a UNION, not both
     * capping each other. So a patient with 40 visits in two years keeps all 40
     * (the window wins), while a patient who has not been seen in years still
     * gets their last 15 (the minimum wins). Both are anchored to the most
     * recent datum, so this stays a pure "last N of available data" function.
     *
     * @param list<Fact> $facts
     * @return list<Fact>
     */
    public static function forChat(array $facts, int $minPerSeries = self::MIN_PER_SERIES, int $maxAgeMonths = self::MAX_AGE_MONTHS): array
    {
        $cutoff = self::cutoffDate($facts, $maxAgeMonths);

        $kept = [];
        /** @var array<string, list<Fact>> $denseSeries */
        $denseSeries = [];

        foreach ($facts as $fact) {
            if (!self::isDense($fact->kind)) {
                // Sparse, decision-bearing facts are kept regardless of age: a
                // medication started five years ago may still be active, and an
                // overdue/pending item is about now, not its origin date.
                $kept[] = $fact;
                continue;
            }
            $unit = $fact->value?->unitCanonical ?? $fact->value?->unitOriginal ?? '';
            $denseSeries[$fact->capability->value . '|' . $unit][] = $fact;
        }

        foreach ($denseSeries as $series) {
            usort($series, static function (Fact $a, Fact $b): int {
                // Most recent first; undated facts trail (ISO Y-m-d sorts as a string).
                $ad = $a->clinicalDate?->format('Y-m-d') ?? '';
                $bd = $b->clinicalDate?->format('Y-m-d') ?? '';

                return $bd <=> $ad;
            });

            foreach ($series as $index => $fact) {
                $date = $fact->clinicalDate?->format('Y-m-d');
                $withinWindow = $cutoff === null || $date === null || $date >= $cutoff;
                $withinMinimum = $index < max(0, $minPerSeries);
                if ($withinWindow || $withinMinimum) {
                    $kept[] = $fact;
                }
            }
        }

        return array_values($kept);
    }

    /**
     * The NARRATIVE window: the synthesis is generated once (not re-sent per
     * round), so it gets the last {@see self::MAX_NARRATIVE_VISITS} visits'
     * worth of dense trend history -- a "visit" being a distinct clinical date
     * on which a trend/reading/vital was recorded -- rather than the tighter
     * 2-year chat window. Sparse decision-bearing facts are kept regardless,
     * exactly as {@see self::forChat()} keeps them.
     *
     * @param list<Fact> $facts
     * @return list<Fact>
     */
    public static function forNarrative(array $facts, int $maxVisits = self::MAX_NARRATIVE_VISITS): array
    {
        $cutoff = self::nthMostRecentVisitDate($facts, $maxVisits);

        $kept = [];
        foreach ($facts as $fact) {
            if (!self::isDense($fact->kind)) {
                $kept[] = $fact;
                continue;
            }
            $date = $fact->clinicalDate?->format('Y-m-d');
            if ($cutoff !== null && $date !== null && $date < $cutoff) {
                continue;
            }
            $kept[] = $fact;
        }

        return array_values($kept);
    }

    /**
     * The `Y-m-d` floor for the last `$maxVisits` visit dates (distinct dense
     * clinical dates), or null when there are no more than that many -- then
     * nothing is dropped.
     *
     * @param list<Fact> $facts
     */
    private static function nthMostRecentVisitDate(array $facts, int $maxVisits): ?string
    {
        $visitDates = [];
        foreach ($facts as $fact) {
            if (!self::isDense($fact->kind)) {
                continue;
            }
            $date = $fact->clinicalDate?->format('Y-m-d');
            if ($date !== null) {
                $visitDates[$date] = true;
            }
        }

        if (count($visitDates) <= $maxVisits) {
            return null;
        }

        $dates = array_keys($visitDates);
        rsort($dates); // most recent first

        return $dates[$maxVisits - 1];
    }

    /**
     * The `Y-m-d` floor: `$maxAgeMonths` before the most recent dated fact, or
     * null when no fact carries a date (then nothing is age-filtered).
     *
     * @param list<Fact> $facts
     */
    private static function cutoffDate(array $facts, int $maxAgeMonths): ?string
    {
        $newest = null;
        foreach ($facts as $fact) {
            $date = $fact->clinicalDate?->format('Y-m-d');
            if ($date !== null && ($newest === null || $date > $newest)) {
                $newest = $date;
            }
        }

        if ($newest === null) {
            return null;
        }

        return (new \DateTimeImmutable($newest))->modify("-{$maxAgeMonths} months")->format('Y-m-d');
    }

    /**
     * The per-visit kinds that explode over a long history. `derived_count`
     * and `derived_span` are deliberately NOT dense: there is one of each per
     * series and each summarizes the full series, so they are always kept.
     */
    private static function isDense(FactKind $kind): bool
    {
        return match ($kind) {
            FactKind::TrendPoint, FactKind::Vital, FactKind::DerivedDelta => true,
            default => false,
        };
    }
}
