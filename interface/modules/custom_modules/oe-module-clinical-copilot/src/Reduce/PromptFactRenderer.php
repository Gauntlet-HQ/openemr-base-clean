<?php

/**
 * Renders the fact set as a readable, per-checklist-item block for the reduce prompt.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

/**
 * A Fact carries its capability and value but NOT which analyte it is, nor the
 * drug a medication event names -- the LOINC / drug text is dropped after
 * extraction because it is not part of fact identity (see
 * {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\FactAnalyteResolver}). The
 * canonical JSON we hand the model is therefore analyte-blind: A1c is the only
 * ControlProxy lab in `%`, while glucose, total cholesterol, LDL, HDL, and
 * triglycerides ALL arrive as `control_proxy` in `mg/dL` with nothing to tell
 * them apart -- so the model could only ever fill the A1c line of the 7-item
 * checklist, which is exactly the "only A1c and meds come through" bug.
 *
 * This renderer closes that gap: given a `fact_id => {key, label}` map resolved
 * upstream (the DB hop the Chart Facts panel already does), it groups the facts
 * under each checklist item -- A1c, glucose, total cholesterol, LDL, HDL,
 * triglycerides, then medications -- and prints, per item, the recent results
 * and the derived trend (change / span / count) with each fact_id inline so the
 * model can cite it. It is an ADDITION to the prompt, not a replacement: the
 * authoritative canonical JSON still follows (that is what the digest addresses
 * and the verifier resolves citations against); this block is the reading guide
 * that tells the model which value belongs to which line.
 *
 * Pure and deterministic: same facts + labels in, same string out, regardless
 * of input fact order (every group is sorted by clinical date then fact_id).
 */
final class PromptFactRenderer
{
    /** The 6 lab lines of the checklist, in the fixed order the narrative emits them. */
    private const LAB_ITEMS = [
        'a1c' => 'A1c',
        'glucose' => 'Glucose',
        'cholesterol' => 'Total Cholesterol',
        'ldl' => 'LDL Cholesterol',
        'hdl' => 'HDL Cholesterol',
        'triglycerides' => 'Triglycerides',
    ];

    private const MEDICATION_KEY = 'medication';

    private const MAX_RESULTS_PER_ITEM = 3;

    private const RESULT_KINDS = [
        FactKind::Result,
        FactKind::TrendPoint,
        FactKind::PreliminaryResult,
    ];

    private const DERIVED_KINDS = [
        FactKind::DerivedDelta,
        FactKind::DerivedSpan,
        FactKind::DerivedCount,
    ];

    private function __construct()
    {
        // static-only
    }

    /**
     * @param list<Fact> $facts
     * @param array<string, array{key: string, label: string}> $factLabels fact_id => analyte/medication label
     */
    public static function render(array $facts, array $factLabels): string
    {
        /** @var array<string, list<Fact>> $byKey */
        $byKey = [];
        $medications = [];
        $other = [];

        foreach ($facts as $fact) {
            $entry = $factLabels[$fact->factId] ?? null;
            $key = $entry['key'] ?? null;

            if ($key !== null && array_key_exists($key, self::LAB_ITEMS)) {
                $byKey[$key][] = $fact;
            } elseif ($key === self::MEDICATION_KEY) {
                $medications[] = $fact;
            } else {
                $other[] = $fact;
            }
        }

        $lines = [];
        foreach (self::LAB_ITEMS as $key => $label) {
            $lines[] = '# ' . $label;
            $lines[] = self::renderLabItem($byKey[$key] ?? []);
            $lines[] = '';
        }

        $lines[] = '# Medications (state each as LAST PRESCRIBED on its date -- never assert the patient is currently taking it)';
        $lines[] = self::renderMedications($medications, $factLabels);
        $lines[] = '';

        $otherBlock = self::renderOther($other);
        if ($otherBlock !== '') {
            $lines[] = '# Other flagged facts (overdue / pending / vitals / conflicts)';
            $lines[] = $otherBlock;
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    /**
     * @param list<Fact> $facts
     */
    private static function renderLabItem(array $facts): string
    {
        $results = self::sortByDateDescThenId(self::ofKinds($facts, self::RESULT_KINDS));
        $derived = self::sortByDateDescThenId(self::ofKinds($facts, self::DERIVED_KINDS));

        if ($results === [] && $derived === []) {
            return 'No recent samples.';
        }

        $lines = [];
        if ($results !== []) {
            $recent = array_slice($results, 0, self::MAX_RESULTS_PER_ITEM);
            $rendered = array_map(self::renderResult(...), $recent);
            $lines[] = 'Recent results (newest first): ' . implode('; ', $rendered);
        }
        if ($derived !== []) {
            $rendered = array_map(self::renderDerived(...), $derived);
            $lines[] = 'Trend: ' . implode('; ', $rendered);
        }

        return implode("\n", $lines);
    }

    private static function renderResult(Fact $fact): string
    {
        $flags = self::flagText($fact);
        $flagSuffix = $flags !== '' ? ' [' . $flags . ']' : '';

        return self::valueText($fact) . ' on ' . self::dateText($fact) . $flagSuffix . ' ' . self::factRef($fact);
    }

    private static function renderDerived(Fact $fact): string
    {
        $label = match ($fact->kind) {
            FactKind::DerivedDelta => 'change ',
            FactKind::DerivedSpan => 'span ',
            FactKind::DerivedCount => 'count ',
            default => '',
        };

        return $label . self::valueText($fact) . ' ' . self::factRef($fact);
    }

    /**
     * @param list<Fact> $facts
     * @param array<string, array{key: string, label: string}> $factLabels
     */
    private static function renderMedications(array $facts, array $factLabels): string
    {
        if ($facts === []) {
            return 'No medications on file.';
        }

        $lines = [];
        foreach (self::sortByDateDescThenId($facts) as $fact) {
            $label = $factLabels[$fact->factId]['label'] ?? 'Medication';
            $lines[] = $label . ' -- last prescribed ' . self::dateText($fact) . ' ' . self::factRef($fact);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<Fact> $facts
     */
    private static function renderOther(array $facts): string
    {
        if ($facts === []) {
            return '';
        }

        $lines = [];
        foreach (self::sortByDateDescThenId($facts) as $fact) {
            $flags = self::flagText($fact);
            $flagSuffix = $flags !== '' ? ' [' . $flags . ']' : '';
            $value = $fact->value !== null ? self::valueText($fact) . ' ' : '';

            $lines[] = str_replace('_', ' ', $fact->kind->value) . ': ' . $value . 'on ' . self::dateText($fact) . $flagSuffix . ' ' . self::factRef($fact);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<Fact> $facts
     * @param list<FactKind> $kinds
     * @return list<Fact>
     */
    private static function ofKinds(array $facts, array $kinds): array
    {
        return array_values(array_filter($facts, static fn (Fact $f): bool => in_array($f->kind, $kinds, true)));
    }

    /**
     * @param list<Fact> $facts
     * @return list<Fact>
     */
    private static function sortByDateDescThenId(array $facts): array
    {
        usort($facts, static function (Fact $a, Fact $b): int {
            $ad = $a->clinicalDate?->format('Y-m-d') ?? '';
            $bd = $b->clinicalDate?->format('Y-m-d') ?? '';
            // Most recent first; fact_id breaks ties so the order is total and
            // independent of the input order (order-independence is asserted by
            // PromptAssemblerTest).
            return [$bd, $b->factId] <=> [$ad, $a->factId];
        });

        return $facts;
    }

    private static function valueText(Fact $fact): string
    {
        $value = $fact->value;
        if ($value === null) {
            return '(no value)';
        }

        $unit = $value->unitCanonical ?? $value->unitOriginal;

        return $unit !== '' && $unit !== null ? $value->raw . ' ' . $unit : $value->raw;
    }

    private static function dateText(Fact $fact): string
    {
        return $fact->clinicalDate?->format('Y-m-d') ?? 'undated';
    }

    private static function factRef(Fact $fact): string
    {
        return '(fact_id: ' . $fact->factId . ')';
    }

    private static function flagText(Fact $fact): string
    {
        $parts = [];
        foreach ($fact->flags as $flag) {
            $value = $flag->value;
            if ($value === 'out_of_range_by_value' || $value === 'out_of_range_by_lab_flag') {
                $parts['out of range'] = true;
            } elseif ($value === 'conflict') {
                $parts['CONFLICT'] = true;
            } elseif ($value === 'censored') {
                $parts['censored value'] = true;
            } elseif (str_starts_with($value, 'superseded_')) {
                $parts['supersedes an earlier same-day draw'] = true;
            }
        }

        return implode(', ', array_keys($parts));
    }
}
