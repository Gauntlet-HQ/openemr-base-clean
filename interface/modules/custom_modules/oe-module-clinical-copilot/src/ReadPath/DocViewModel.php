<?php

/**
 * Transforms a SynthesisReadResult into the plain arrays doc.html.twig renders.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Doc\DocRow;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verdict;

/**
 * Pure and DB-free (isolated-testable, tests/Isolated/ReadPath/DocViewModelTest.php)
 * -- deliberately kept out of the Twig layer so grouping/ordering logic is
 * typed and unit-testable rather than living in template conditionals.
 * Facts-first rendering (ARCHITECTURE.md §2.5) is enforced structurally
 * here: every returned bucket is built from {@see SynthesisReadResult::$facts},
 * which is ALWAYS the fresh, current extraction (I2) -- never the served
 * doc row's own stale snapshot.
 *
 * Three buckets, mutually exclusive by `FactKind`:
 * - `inFlight`: `pending_order` / `preliminary_result` / `expected_result_date`
 *   -- C2's rule that a preliminary result renders here and NEVER as a
 *   trend point holds structurally, because ControlProxy/OverdueTests never
 *   emit these kinds at all (U5) -- this presenter does not need to
 *   re-derive that rule, only route by kind.
 * - `exclusions`: `exclusion` kind (I5 -- "N excluded (reason)" facts, with citations).
 * - `byCapability`: everything else, grouped by capability for the
 *   always-visible facts table.
 */
final class DocViewModel
{
    /**
     * Most-recent facts shown per group in the tabbed Chart Facts panel. A
     * multi-year chart yields hundreds of trend points per capability; beyond
     * the last ~20 the panel is noise the physician scrolls past, so each
     * group is capped to its most recent {@see self::MAX_FACTS_PER_GROUP} (the
     * group's true total is carried alongside so the UI can say "showing the
     * 20 most recent of 175").
     */
    private const MAX_FACTS_PER_GROUP = 20;

    private function __construct()
    {
        // static-only
    }

    /**
     * @param array<string, array{key: string, label: string}> $analyteByFactId
     *        fact_id => analyte (lab type) for lab facts, from
     *        {@see FactAnalyteResolver}; empty is fine (labs then group by
     *        capability, unsplit). Threaded in rather than resolved here so
     *        this presenter stays DB-free.
     * @return array{
     *     narrative: list<array{text: string, claim_type: string, flags: list<string>, emphasis: ?string, citations: list<array{label: string, refs: list<array{pk: int, url: ?string}>}>}>,
     *     fact_groups: list<array{key: string, label: string, total: int, shown: int, consolidated: bool, summary: array<string, mixed>|null, sparkline: array<string, mixed>|null, facts: list<array<string, mixed>>}>
     * }
     */
    public static function build(SynthesisReadResult $result, string $webRoot, array $analyteByFactId = []): array
    {
        /** @var array<string, Fact> $factById */
        $factById = [];
        foreach ($result->facts as $fact) {
            $factById[$fact->factId] = $fact;
        }

        return [
            'narrative' => self::buildNarrative($result->claims, $factById, $webRoot),
            'fact_groups' => self::buildFactGroups($result->facts, $webRoot, $analyteByFactId),
        ];
    }

    /**
     * The top-of-page status summary -- deliberately pre-flattens every
     * backed enum on {@see SynthesisReadResult} to its plain string `value`
     * here, once, so the Twig template (autoescape OFF, build-notes.md)
     * never has to compare an enum object to a string literal (a silent
     * false-positive-free `false` in PHP/Twig's loose `==`, not a template
     * error) -- Twig only ever sees plain scalars from this method.
     *
     * @return array{
     *     capability_crash: bool, crash_banner: ?string, verify_status: ?string,
     *     regen_reason: ?string, degraded_message: ?string, served_from_cache: bool,
     *     computed_at: ?string, correlation_id: string, qa_status: ?string,
     *     qa_score: ?float, verdict_hover: string
     * }
     */
    public static function summary(SynthesisReadResult $result): array
    {
        return [
            'capability_crash' => $result->capabilityCrash,
            'crash_banner' => $result->crashBanner,
            'verify_status' => $result->verifyStatus?->value,
            'regen_reason' => $result->regenReason?->value,
            'degraded_message' => $result->degradedMessage,
            'degraded_reason' => $result->degradedReason,
            'served_from_cache' => $result->servedFromCache,
            'computed_at' => $result->computedAt?->format('Y-m-d H:i'),
            'correlation_id' => $result->correlationId,
            'qa_status' => $result->qaStatus?->value,
            'qa_score' => $result->qaScore,
            'verdict_hover' => self::verdictHover($result->verdicts),
        ];
    }

    /**
     * The technical "History" audit table: one row per past synthesis attempt.
     * Degraded attempts are hidden -- they produced no served narrative, so a
     * clinician scanning history has nothing to view in them. QA is intentionally
     * omitted: the advisory second-pass reviewer is no longer used.
     *
     * @param list<DocRow> $history
     * @return list<array{computed_at: string, fact_digest: string, verify_status: string, regen_reason: string, correlation_id: string}>
     */
    public static function historyRows(array $history): array
    {
        $rows = [];
        foreach ($history as $row) {
            if ($row->verifyStatus === VerifyStatus::Degraded) {
                continue;
            }
            $rows[] = [
                'computed_at' => $row->computedAt->format('Y-m-d H:i:s'),
                'fact_digest' => substr($row->factDigest, 0, 12),
                'verify_status' => $row->verifyStatus->value,
                'regen_reason' => $row->regenReason->value,
                'correlation_id' => $row->correlationId,
            ];
        }

        return $rows;
    }

    /**
     * The "Recent Narratives" tab: the last {@see $limit} PAST narrative
     * attempts (never the one already shown in the Current Narrative tab),
     * each re-rendered from its OWN persisted fact snapshot
     * ({@see SynthesisDocPayload}) so a past attempt's citations resolve
     * against the facts that were true when it was generated, not today's
     * chart. A degraded attempt (no claims) is skipped -- there is nothing to
     * show in a narrative tab for it, and is detected cheaply off the raw
     * `doc['claims']` array before paying for a full {@see SynthesisDocPayload}
     * hydration (which validates every persisted Fact) -- the same
     * cheap-check-first approach {@see self::visitRows()} uses, needed here
     * too because a long run of degraded attempts would otherwise be fully
     * hydrated just to be discarded on every page load.
     *
     * @param list<DocRow> $history most-recent-first ({@see DocHistoryReader::forPid()})
     * @return list<array{id: int, computed_at: string, narrative: list<array{text: string, claim_type: string, flags: list<string>, emphasis: ?string, citations: list<array{label: string, refs: list<array{pk: int, url: ?string}>}>}>}>
     */
    public static function recentNarratives(array $history, string $webRoot, ?int $excludeDocId, int $limit = 3): array
    {
        $out = [];
        foreach ($history as $row) {
            if (count($out) >= $limit) {
                break;
            }
            if ($excludeDocId !== null && $row->id === $excludeDocId) {
                continue;
            }

            $claimsRaw = $row->doc['claims'] ?? null;
            if (!is_array($claimsRaw) || $claimsRaw === []) {
                continue;
            }

            $payload = SynthesisDocPayload::fromDocArray($row->doc);
            if ($payload->claims === null || $payload->claims === []) {
                continue;
            }

            $factById = [];
            foreach ($payload->facts as $fact) {
                $factById[$fact->factId] = $fact;
            }

            $narrative = self::buildNarrative($payload->claims, $factById, $webRoot);
            if ($narrative === []) {
                continue;
            }

            $out[] = [
                'id' => $row->id,
                'computed_at' => $row->computedAt->format('Y-m-d H:i'),
                'narrative' => $narrative,
            ];
        }

        return $out;
    }

    /**
     * The "Previous Results / Visits" subtab: one line per past synthesis
     * attempt (never the one already shown in the Current Narrative tab).
     * This module has no separate "visit" record of its own (a `DocRow` is
     * keyed by `pid` + `fact_digest`, not by appointment) -- `computed_at` is
     * the closest honest proxy, alongside the appointment id when
     * {@see \OpenEMR\Modules\ClinicalCopilot\DocStore} happened to have one
     * at insert time.
     *
     * A one-liner needs only the claim COUNT, not the claims themselves, so
     * this reads `doc['claims']` straight off the raw decoded row rather
     * than going through {@see SynthesisDocPayload::fromDocArray()} -- which
     * would also hydrate and validate every persisted Fact just to be
     * discarded, unnecessary work for up to
     * {@see DocHistoryReader::forPid()}'s 50-row default on every page load.
     *
     * @param list<DocRow> $history most-recent-first
     * @return list<array{id: int, computed_at: string, appt_id: ?int, verify_status: string, claim_count: int}>
     */
    public static function visitRows(array $history, ?int $excludeDocId = null): array
    {
        $rows = [];
        foreach ($history as $row) {
            if ($excludeDocId !== null && $row->id === $excludeDocId) {
                continue;
            }
            // Degraded attempts served no narrative -- hide them from the
            // clinician-facing history rather than listing empty rows.
            if ($row->verifyStatus === VerifyStatus::Degraded) {
                continue;
            }

            $claimsRaw = $row->doc['claims'] ?? null;
            $rows[] = [
                'id' => $row->id,
                'computed_at' => $row->computedAt->format('Y-m-d H:i'),
                'appt_id' => $row->apptId,
                'verify_status' => $row->verifyStatus->value,
                'claim_count' => is_array($claimsRaw) ? count($claimsRaw) : 0,
            ];
        }

        return $rows;
    }

    /**
     * The "Previous Results / Variance" subtab: every lab and vitals draw
     * that has a computable "change vs prior", flattened to ONE ROW PER
     * DRAW/READING across all analytes -- the Chart Facts tab keeps these
     * split into a tab per analyte, but this view is deliberately one flat,
     * zebra-stripable table.
     *
     * Labs are read straight off {@see $factGroups} -- the SAME `lab:*`
     * consolidated groups {@see self::buildFactGroups()} already built for
     * the Chart Facts tab -- rather than re-deriving them from `$facts`:
     * this avoids running {@see self::factRow()} a second time over every
     * lab fact, and for free inherits buildFactGroups()'s own exclusion of
     * `FactKind::Exclusion`/in-flight kinds (I5 -- an excluded, unreliable
     * lab value must never render as if it were a normal trend point; only a
     * `consolidated` group can ever be a `lab:*` group, so filtering on
     * `consolidated` here is exactly "labs, already exclusion-filtered").
     *
     * Vitals are NOT available pre-built this way (VitalsTrend's single
     * `vitals_trend` group is never marked `consolidated` -- see the note on
     * {@see self::vitalsMetricKey()}), so they are derived here from the raw
     * `$facts` instead, which needs each Citation's `field` to route
     * weight/BMI/bp -- something the already-flattened `fact_groups` rows no
     * longer carry (only the resolved citation label/url survive
     * {@see self::factRow()}). VitalsTrend never emits `FactKind::Exclusion`
     * or an in-flight kind (verified: it only ever builds `vital` and
     * `derived_*` facts), so no equivalent exclusion filter is needed on
     * this branch.
     *
     * Vitals need the same per-series isolation the per-analyte lab split
     * gets: {@see \OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend}
     * emits weight and BMI as two independent series that often share a
     * clinical_date (same visit, same form_vitals row) -- consolidating them
     * as one combined bucket would let a same-day weight delta collide with
     * that day's BMI delta in {@see self::consolidateLabRows()}'s
     * date-keyed lookup. Grouping vitals by their citation `field` first
     * (weight/BMI/bp, via {@see self::vitalsMetricKey()}) keeps each series
     * isolated. Blood pressure has no derived-fact math (VitalsTrend's
     * documented v1 scope limit) and is shown as-is with no change column.
     *
     * @param list<array{key: string, label: string, total: int, shown: int, consolidated: bool, summary: array<string, mixed>|null, sparkline: array<string, mixed>|null, facts: list<array<string, mixed>>}> $factGroups {@see self::buildFactGroups()}'s output for the SAME `$facts`
     * @param list<Fact> $facts current extraction's full fact set
     * @param array<string, array{key: string, label: string}> $analyteByFactId
     * @return list<array<string, mixed>> most-recent-first, each row carrying a 'group_label' (the analyte/metric name)
     */
    public static function varianceRows(array $factGroups, array $facts, string $webRoot, array $analyteByFactId): array
    {
        $rows = [];
        foreach ($factGroups as $group) {
            if (!$group['consolidated']) {
                continue;
            }
            foreach ($group['facts'] as $row) {
                $row['group_label'] = $group['label'];
                $rows[] = $row;
            }
        }

        /** @var array<string, list<array<string, mixed>>> $vitalsByMetric */
        $vitalsByMetric = [];
        foreach ($facts as $fact) {
            if ($fact->capability->value !== 'vitals_trend') {
                continue;
            }
            $metric = self::vitalsMetricKey($fact);
            if ($metric !== null) {
                $vitalsByMetric[$metric][] = self::factRow($fact, $webRoot, $analyteByFactId);
            }
        }

        foreach ($vitalsByMetric as $metric => $metricRows) {
            if ($metric === 'bp') {
                foreach ($metricRows as $row) {
                    $row['group_label'] = 'Blood pressure';
                    $row['change_display'] = null;
                    $rows[] = $row;
                }
                continue;
            }

            [$consolidated] = self::consolidateLabRows($metricRows);
            $label = $metric === 'weight' ? 'Weight' : 'BMI';
            foreach ($consolidated as $row) {
                $row['group_label'] = $label;
                $rows[] = $row;
            }
        }

        return self::sortMostRecentFirst($rows);
    }

    /**
     * Which VitalsTrend series a Fact belongs to, read off its own citation
     * field rather than re-deriving it from capability + kind -- every vital
     * Fact and every derived fact built from it carries this same field on
     * every citation ({@see \OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend::buildNumericVital()},
     * {@see \OpenEMR\Modules\ClinicalCopilot\Capability\Support\DerivedFacts::deltas()}
     * unions the series' own citations), so this is exact, not a guess.
     */
    private static function vitalsMetricKey(Fact $fact): ?string
    {
        $field = $fact->citations[0]->field ?? null;

        return match ($field) {
            'weight' => 'weight',
            'BMI' => 'bmi',
            'bps', 'bpd' => 'bp',
            default => null,
        };
    }

    /**
     * Most-recent-first by `clinical_date` (ISO `Y-m-d` sorts correctly as a
     * plain string); undated rows trail. Shared by {@see self::group()} (the
     * Chart Facts panel) and {@see self::varianceRows()} (the flattened
     * Previous Results / Variance table) so both views order rows the same
     * way from one implementation.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function sortMostRecentFirst(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $ad = is_string($a['clinical_date'] ?? null) ? $a['clinical_date'] : '';
            $bd = is_string($b['clinical_date'] ?? null) ? $b['clinical_date'] : '';

            return $bd <=> $ad;
        });

        return $rows;
    }

    /**
     * ARCHITECTURE.md §2.5: "hover: exactly which checks V1-V6 ran and
     * their verdicts."
     *
     * @param list<Verdict> $verdicts
     */
    private static function verdictHover(array $verdicts): string
    {
        if ($verdicts === []) {
            return 'V1-V6: not run (LLM unavailable, facts-only)';
        }

        $lines = array_map(
            static function (Verdict $v): string {
                $status = $v->skipped ? 'skipped' : ($v->passed ? 'passed' : 'failed');

                return "{$v->checkId->value}: {$status}";
            },
            $verdicts,
        );

        return implode(' | ', $lines);
    }

    /**
     * @param list<Claim>|null $claims
     * @param array<string, Fact> $factById
     * @return list<array{text: string, claim_type: string, flags: list<string>, emphasis: ?string, citations: list<array{label: string, refs: list<array{pk: int, url: ?string}>}>}>
     */
    private static function buildNarrative(?array $claims, array $factById, string $webRoot): array
    {
        if ($claims === null) {
            return [];
        }

        $ordered = $claims;
        usort($ordered, static fn (Claim $a, Claim $b): int => $a->order <=> $b->order);

        $narrative = [];
        foreach ($ordered as $claim) {
            $citations = [];
            foreach ($claim->citationIds as $factId) {
                $fact = $factById[$factId] ?? null;
                if ($fact === null) {
                    continue;
                }
                foreach ($fact->citations as $citation) {
                    $citations[] = $citation;
                }
            }

            $narrative[] = [
                'text' => self::stripInlineFactIds($claim->text),
                'claim_type' => $claim->claimType->value,
                'flags' => $claim->flags,
                'emphasis' => self::stripInlineFactIds($claim->emphasis ?? '') ?: null,
                'citations' => self::consolidateCitations($citations, $webRoot),
            ];
        }

        return $narrative;
    }

    /**
     * The ordered, clamped groups the tabbed Chart Facts panel renders: one
     * tab per non-empty group, each sorted most-recent-first and capped to
     * {@see self::MAX_FACTS_PER_GROUP} with its true `total` carried through so
     * the UI can show "N most recent of TOTAL".
     *
     * The trend labs (control_proxy) are split into ONE group per analyte --
     * A1c, Glucose, LDL, HDL, ... -- so units never mix within a tab and each
     * lab type gets its own last-20 (the actual A1c's go back 10-20 draws
     * instead of sharing a budget with glucose and lipids). Every other
     * capability stays a single group; in-flight and exclusions are their own
     * buckets. Group order is clinical: A1c first, other analytes, then
     * overdue / in-flight / meds / vitals, exclusions last.
     *
     * @param list<Fact> $facts
     * @param array<string, array{key: string, label: string}> $analyteByFactId
     * @return list<array{key: string, label: string, total: int, shown: int, consolidated: bool, summary: array<string, mixed>|null, sparkline: array<string, mixed>|null, facts: list<array<string, mixed>>}>
     */
    private static function buildFactGroups(array $facts, string $webRoot, array $analyteByFactId): array
    {
        $inFlight = self::buildBucket($facts, self::inFlightKinds(), $webRoot, $analyteByFactId);
        $exclusions = self::buildBucket($facts, [FactKind::Exclusion], $webRoot, $analyteByFactId);

        $capabilityKinds = [...self::inFlightKinds(), FactKind::Exclusion];
        /** @var array<string, array{label: string, rows: list<array<string, mixed>>}> $grouped */
        $grouped = [];
        foreach ($facts as $fact) {
            if (in_array($fact->kind, $capabilityKinds, true)) {
                continue;
            }
            $row = self::factRow($fact, $webRoot, $analyteByFactId);
            $analyte = $analyteByFactId[$fact->factId] ?? null;

            if ($fact->capability->value === 'control_proxy' && $analyte !== null) {
                $key = 'lab:' . $analyte['key'];
                $label = $analyte['label'];
            } else {
                $key = $fact->capability->value;
                $label = is_string($row['capability_label'] ?? null) ? $row['capability_label'] : $key;
            }

            $grouped[$key]['label'] ??= $label;
            $grouped[$key]['rows'][] = $row;
        }

        $groups = [];
        if ($inFlight !== []) {
            $groups[] = self::group('in_flight', 'In flight', $inFlight);
        }
        foreach ($grouped as $key => $bucket) {
            // A trend lab (control_proxy, one group per analyte) explodes into a
            // separate row per fact -- the draw's trend_point, then its
            // derived_change, plus the series-level derived_span and
            // derived_count -- so one LDL draw became 4+ near-identical rows.
            // Collapse them: one row per draw (date), the change-from-prior as a
            // column on that row, and the span/count carried as a one-line series
            // summary. Every other group stays a flat fact-per-row table.
            if (str_starts_with($key, 'lab:')) {
                [$rows, $summary] = self::consolidateLabRows($bucket['rows']);
                $groups[] = self::group($key, $bucket['label'], $rows, consolidated: true, summary: $summary);
            } else {
                $groups[] = self::group($key, $bucket['label'], $bucket['rows']);
            }
        }
        if ($exclusions !== []) {
            $groups[] = self::group('exclusions', 'Excluded', $exclusions);
        }

        usort($groups, static fn (array $a, array $b): int => self::groupOrder($a['key']) <=> self::groupOrder($b['key']));

        return $groups;
    }

    /**
     * Clinical reading order for the tabs: the trend labs lead (A1c is the
     * headline), then the rest of the panel, exclusions last. Unlisted keys
     * sort into the middle, keeping their insertion order relative to each
     * other (PHP's sort is stable).
     */
    private static function groupOrder(string $key): int
    {
        return match ($key) {
            'lab:a1c' => 0,
            'lab:glucose' => 1,
            'lab:cholesterol' => 2,
            'lab:ldl' => 3,
            'lab:hdl' => 4,
            'lab:triglycerides' => 5,
            'overdue_tests' => 20,
            'in_flight' => 21,
            'med_response' => 22,
            'vitals_trend' => 23,
            'exclusions' => 99,
            default => 50,
        };
    }

    /**
     * Sorts one group most-recent-first (by clinical_date; undated facts trail)
     * and caps it to the most recent {@see self::MAX_FACTS_PER_GROUP}, carrying
     * the pre-cap total so the panel can disclose what was trimmed.
     *
     * A consolidated (trend-lab) group additionally carries the inline-SVG
     * sparkline coordinates for the SAME rows the table shows (the capped
     * `$shown`, so the chart never plots draws the table hides) -- null when
     * nothing in the group is plottable ({@see TrendSparkline::fromRows()}).
     *
     * @param list<array<string, mixed>> $rows
     * @param array{count: ?string, count_citations: list<array{label: string, url: ?string}>, span: ?string, span_citations: list<array{label: string, url: ?string}>}|null $summary
     * @return array{key: string, label: string, total: int, shown: int, consolidated: bool, summary: array<string, mixed>|null, sparkline: array<string, mixed>|null, facts: list<array<string, mixed>>}
     */
    private static function group(string $key, string $label, array $rows, bool $consolidated = false, ?array $summary = null): array
    {
        $rows = self::sortMostRecentFirst($rows);

        $total = count($rows);
        $shown = array_slice($rows, 0, self::MAX_FACTS_PER_GROUP);

        return [
            'key' => $key,
            'label' => $label,
            'total' => $total,
            'shown' => count($shown),
            'consolidated' => $consolidated,
            'summary' => $summary,
            'sparkline' => $consolidated ? TrendSparkline::fromRows($shown) : null,
            'facts' => $shown,
        ];
    }

    /**
     * Collapses one trend-lab group's flat fact rows into one row per draw.
     *
     * {@see \OpenEMR\Modules\ClinicalCopilot\Lab\LabRowProcessor} resolves a
     * single winner per (analyte, clinical_date), so a date uniquely identifies
     * a draw within one analyte -- which lets us attach each `derived_delta`
     * (change-from-prior, whose clinical_date is the later point's date) to the
     * draw it lands on as a `change` column, rather than as its own row. The
     * series-level `derived_span` and `derived_count` (one each) are lifted out
     * into a one-line summary. A draw with no computed delta (the earliest, or
     * a corrected/censored point that is not trend-eligible) simply has a null
     * change.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: list<array<string, mixed>>, 1: array{count: ?string, count_citations: list<array{label: string, url: ?string}>, span: ?string, span_citations: list<array{label: string, url: ?string}>}|null}
     */
    private static function consolidateLabRows(array $rows): array
    {
        /** @var array<string, array<string, mixed>> $changeByDate */
        $changeByDate = [];
        $span = null;
        $count = null;
        $anchors = [];

        foreach ($rows as $row) {
            $kind = $row['kind'] ?? null;
            $date = is_string($row['clinical_date'] ?? null) ? $row['clinical_date'] : '';

            if ($kind === FactKind::DerivedDelta->value) {
                if ($date !== '') {
                    $changeByDate[$date] = $row;
                }
            } elseif ($kind === FactKind::DerivedSpan->value) {
                $span ??= $row;
            } elseif ($kind === FactKind::DerivedCount->value) {
                $count ??= $row;
            } else {
                $anchors[] = $row;
            }
        }

        foreach ($anchors as $index => $anchor) {
            $date = is_string($anchor['clinical_date'] ?? null) ? $anchor['clinical_date'] : '';
            $change = $date !== '' ? ($changeByDate[$date] ?? null) : null;
            $anchors[$index]['change_display'] = $change !== null ? self::derivedDisplay($change) : null;
            /** @var list<array{label: string, url: ?string}> $changeCitations */
            $changeCitations = is_array($change['citations'] ?? null) ? $change['citations'] : [];
            $anchors[$index]['change_citations'] = $changeCitations;
        }

        $summary = null;
        if ($span !== null || $count !== null) {
            /** @var list<array{label: string, url: ?string}> $countCitations */
            $countCitations = $count !== null && is_array($count['citations'] ?? null) ? $count['citations'] : [];
            /** @var list<array{label: string, url: ?string}> $spanCitations */
            $spanCitations = $span !== null && is_array($span['citations'] ?? null) ? $span['citations'] : [];
            $summary = [
                'count' => $count !== null ? self::derivedDisplay($count) : null,
                'count_citations' => $countCitations,
                'span' => $span !== null ? self::derivedDisplay($span) : null,
                'span_citations' => $spanCitations,
            ];
        }

        return [array_values($anchors), $summary];
    }

    /**
     * The human value of a derived fact (change / span / count) with its unit.
     * Uses `raw` -- not `parsed` -- because a delta's raw string carries its
     * sign ("+0.30", "-14"), which a physician reading a change column needs and
     * which the bare parsed number would drop.
     *
     * @param array<string, mixed> $row
     */
    private static function derivedDisplay(array $row): string
    {
        $raw = is_string($row['raw'] ?? null) ? $row['raw'] : '';
        $unit = is_string($row['unit'] ?? null) && $row['unit'] !== '' ? ' ' . $row['unit'] : '';

        return $raw . $unit;
    }

    /**
     * @param list<Fact> $facts
     * @param list<FactKind> $kinds
     * @param array<string, array{key: string, label: string}> $analyteByFactId
     * @return list<array<string, mixed>>
     */
    private static function buildBucket(array $facts, array $kinds, string $webRoot, array $analyteByFactId): array
    {
        $rows = [];
        foreach ($facts as $fact) {
            if (in_array($fact->kind, $kinds, true)) {
                $rows[] = self::factRow($fact, $webRoot, $analyteByFactId);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, array{key: string, label: string}> $analyteByFactId
     * @return array<string, mixed>
     */
    private static function factRow(Fact $fact, string $webRoot, array $analyteByFactId): array
    {
        $analyte = $analyteByFactId[$fact->factId] ?? null;

        return [
            'capability' => $fact->capability->value,
            'analyte_key' => $analyte['key'] ?? null,
            'analyte_label' => $analyte['label'] ?? null,
            'kind' => $fact->kind->value,
            'raw' => $fact->value?->raw,
            'parsed' => $fact->value?->parsed,
            'comparator' => $fact->value?->comparator->value,
            'unit' => $fact->value?->unitCanonical ?? $fact->value?->unitOriginal,
            'status' => $fact->status->value,
            'clinical_date' => $fact->clinicalDate?->format('Y-m-d'),
            'date_source' => $fact->dateSource->value,
            'flags' => array_map(static fn (Flag $f): string => $f->value, $fact->flags),
            'flag_labels' => array_map(
                static fn (Flag $f): string => FactDisplayFormatter::flagLabel($f->value),
                $fact->flags,
            ),
            'kind_label' => FactDisplayFormatter::kindLabel($fact->kind->value),
            'status_label' => FactDisplayFormatter::statusLabel($fact->status->value),
            'capability_label' => FactDisplayFormatter::capabilityLabel($fact->capability->value),
            'is_excluded' => $fact->kind === FactKind::Exclusion,
            'citations' => self::consolidateCitations($fact->citations, $webRoot),
        ];
    }

    /**
     * Group a flat citation list into one display token per source table so
     * many provenance pointers read as "Lab result 5240, 5241, 5242 ·
     * Prescription 318, 320" instead of a wall of repeated
     * "[Lab result #5240.result]" chips. Deduplicated by (table, pk) -- the
     * same physical row cited by several facts, or under different `field`s,
     * collapses to one pk -- with table groups and pks both kept in first-seen
     * order. Purely a display transform: the raw per-citation provenance is
     * untouched in the persisted doc and in `citation_ids`.
     *
     * @param list<Citation> $citations
     * @return list<array{label: string, refs: list<array{pk: int, url: ?string}>}>
     */
    private static function consolidateCitations(array $citations, string $webRoot): array
    {
        /** @var array<string, array{label: string, refs: array<int, array{pk: int, url: ?string}>}> $groups */
        $groups = [];
        foreach ($citations as $citation) {
            $table = $citation->table;
            if (!isset($groups[$table])) {
                $groups[$table] = ['label' => ChartLinkResolver::tableLabel($table), 'refs' => []];
            }
            if (!isset($groups[$table]['refs'][$citation->pk])) {
                $groups[$table]['refs'][$citation->pk] = [
                    'pk' => $citation->pk,
                    'url' => ChartLinkResolver::url($citation, $webRoot),
                ];
            }
        }

        $out = [];
        foreach ($groups as $group) {
            $out[] = ['label' => $group['label'], 'refs' => array_values($group['refs'])];
        }

        return $out;
    }

    /**
     * Remove the "(fact_id: <hash>)" provenance markers the model emits inline
     * in claim prose. The fact ids are already carried structurally in
     * `citation_ids` (persisted, and what verification runs on), so the inline
     * copies are pure redundant noise -- 64-hex-char hashes that dominate the
     * clinician's screen. Stripping happens only on the read/display path;
     * the persisted claim text in SQL keeps its raw form for audit.
     */
    private static function stripInlineFactIds(string $text): string
    {
        // Matches " (fact_id: <hex>)" and " (fact_ids: <hex>, <hex>)", with the
        // leading whitespace consumed so no double space is left behind.
        $stripped = preg_replace(
            '/\s*\(fact_ids?:\s*[0-9a-f]{6,}(?:\s*,\s*[0-9a-f]{6,})*\s*\)/i',
            '',
            $text,
        );
        $stripped = $stripped ?? $text;

        // Collapse any run of spaces the removal may have created, and tidy a
        // stray space left in front of sentence punctuation.
        $stripped = preg_replace('/ {2,}/', ' ', $stripped) ?? $stripped;
        $stripped = preg_replace('/ +([.,;:])/', '$1', $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * @return list<FactKind>
     */
    private static function inFlightKinds(): array
    {
        return [FactKind::PendingOrder, FactKind::PreliminaryResult, FactKind::ExpectedResultDate];
    }
}
