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
    private function __construct()
    {
        // static-only
    }

    /**
     * @return array{
     *     narrative: list<array{text: string, claim_type: string, flags: list<string>, emphasis: ?string, citations: list<array{label: string, url: ?string}>}>,
     *     facts_by_capability: array<string, list<array<string, mixed>>>,
     *     in_flight: list<array<string, mixed>>,
     *     exclusions: list<array<string, mixed>>
     * }
     */
    public static function build(SynthesisReadResult $result, string $webRoot): array
    {
        /** @var array<string, Fact> $factById */
        $factById = [];
        foreach ($result->facts as $fact) {
            $factById[$fact->factId] = $fact;
        }

        return [
            'narrative' => self::buildNarrative($result->claims, $factById, $webRoot),
            'facts_by_capability' => self::buildFactsByCapability($result->facts, $webRoot),
            'in_flight' => self::buildBucket($result->facts, self::inFlightKinds(), $webRoot),
            'exclusions' => self::buildBucket($result->facts, [FactKind::Exclusion], $webRoot),
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
     * @param list<Fact> $facts
     * @return array<string, list<array<string, mixed>>>
     */
    private static function buildFactsByCapability(array $facts, string $webRoot): array
    {
        $excludedKinds = [...self::inFlightKinds(), FactKind::Exclusion];

        $byCapability = [];
        foreach ($facts as $fact) {
            if (in_array($fact->kind, $excludedKinds, true)) {
                continue;
            }
            $byCapability[$fact->capability->value][] = self::factRow($fact, $webRoot);
        }

        return $byCapability;
    }

    /**
     * @param list<Fact> $facts
     * @param list<FactKind> $kinds
     * @return list<array<string, mixed>>
     */
    private static function buildBucket(array $facts, array $kinds, string $webRoot): array
    {
        $rows = [];
        foreach ($facts as $fact) {
            if (in_array($fact->kind, $kinds, true)) {
                $rows[] = self::factRow($fact, $webRoot);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function factRow(Fact $fact, string $webRoot): array
    {
        return [
            'capability' => $fact->capability->value,
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
