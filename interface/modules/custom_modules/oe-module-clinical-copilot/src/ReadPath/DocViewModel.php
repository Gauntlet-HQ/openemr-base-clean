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
     *     narrative: list<array{text: string, claim_type: string, flags: list<string>, emphasis: ?string, citations: list<array{label: string, url: ?string}>}>,
     *     fact_groups: list<array{key: string, label: string, total: int, shown: int, consolidated: bool, summary: array<string, mixed>|null, facts: list<array<string, mixed>>}>
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
     *     regen_reason: ?string, degraded_message: ?string, degraded_reason: ?string,
     *     served_from_cache: bool, computed_at: ?string, correlation_id: string,
     *     qa_status: ?string, qa_score: ?float, verdict_hover: string
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
     * @param list<DocRow> $history
     * @return list<array{computed_at: string, fact_digest: string, verify_status: string, regen_reason: string, qa_status: string, qa_score: ?float, correlation_id: string}>
     */
    public static function historyRows(array $history): array
    {
        return array_map(
            static fn (DocRow $row): array => [
                'computed_at' => $row->computedAt->format('Y-m-d H:i:s'),
                'fact_digest' => substr($row->factDigest, 0, 12),
                'verify_status' => $row->verifyStatus->value,
                'regen_reason' => $row->regenReason->value,
                'qa_status' => $row->qaStatus->value,
                'qa_score' => $row->qaScore,
                'correlation_id' => $row->correlationId,
            ],
            $history,
        );
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
     * @return list<array{text: string, claim_type: string, flags: list<string>, emphasis: ?string, citations: list<array{label: string, url: ?string}>}>
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
                    $citations[] = self::citationLink($citation, $webRoot);
                }
            }

            $narrative[] = [
                'text' => $claim->text,
                'claim_type' => $claim->claimType->value,
                'flags' => $claim->flags,
                'emphasis' => $claim->emphasis,
                'citations' => $citations,
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
     * @return list<array{key: string, label: string, total: int, shown: int, consolidated: bool, summary: array<string, mixed>|null, facts: list<array<string, mixed>>}>
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
     * @param list<array<string, mixed>> $rows
     * @param array{count: ?string, count_citations: list<array{label: string, url: ?string}>, span: ?string, span_citations: list<array{label: string, url: ?string}>}|null $summary
     * @return array{key: string, label: string, total: int, shown: int, consolidated: bool, summary: array<string, mixed>|null, facts: list<array<string, mixed>>}
     */
    private static function group(string $key, string $label, array $rows, bool $consolidated = false, ?array $summary = null): array
    {
        usort($rows, static function (array $a, array $b): int {
            $ad = is_string($a['clinical_date'] ?? null) ? $a['clinical_date'] : '';
            $bd = is_string($b['clinical_date'] ?? null) ? $b['clinical_date'] : '';

            // ISO Y-m-d sorts correctly as a string; empty (undated) trails.
            return $bd <=> $ad;
        });

        $total = count($rows);
        $shown = array_slice($rows, 0, self::MAX_FACTS_PER_GROUP);

        return [
            'key' => $key,
            'label' => $label,
            'total' => $total,
            'shown' => count($shown),
            'consolidated' => $consolidated,
            'summary' => $summary,
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
            'citations' => array_map(static fn (Citation $c): array => self::citationLink($c, $webRoot), $fact->citations),
        ];
    }

    /**
     * @return array{label: string, url: ?string}
     */
    private static function citationLink(Citation $citation, string $webRoot): array
    {
        return [
            'label' => ChartLinkResolver::label($citation),
            'url' => ChartLinkResolver::url($citation, $webRoot),
        ];
    }

    /**
     * @return list<FactKind>
     */
    private static function inFlightKinds(): array
    {
        return [FactKind::PendingOrder, FactKind::PreliminaryResult, FactKind::ExpectedResultDate];
    }
}
