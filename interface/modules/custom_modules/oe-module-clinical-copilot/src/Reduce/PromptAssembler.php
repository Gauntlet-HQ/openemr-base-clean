<?php

/**
 * Builds the reduce prompt from the canonical fact set (ARCHITECTURE_COMPLETE.md).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

use OpenEMR\Modules\ClinicalCopilot\Fact\CanonicalSerializer;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\PromptFactWindow;

/**
 * Contract this class exists to hold (docs/build-notes.md, U7): the fact
 * portion of the assembled prompt is BYTE-IDENTICAL to
 * {@see CanonicalSerializer::serializeFacts()} over the same fact list --
 * the same function that feeds the digest (U3). This class never
 * re-encodes, re-orders, or otherwise transforms that string; it only wraps
 * it in delimited instructional text. A prompt-assembly test asserts
 * `str_contains($assembled->userContent, CanonicalSerializer::serializeFacts($facts))`.
 *
 * Bakes in the Stage-3 discipline from the spec: state "no data available for
 * X" rather than guessing; never calculate a value (all arithmetic the model
 * may cite already exists as a `derived_*` Fact -- U5); cite every clinical
 * claim; never assert causation, a treatment recommendation, or a diagnosis.
 * Also states the §2.1 claim output contract explicitly, in addition to the
 * provider-enforced `responseSchema` ({@see Claim::jsonSchema()}) -- belt and
 * suspenders, since V1's schema gate is the backstop, not the only defense.
 */
final class PromptAssembler
{
    private const FACTS_HEADER = '=== SESSION FACTS (canonical JSON -- authoritative; do not alter, recompute, or invent any value) ===';
    private const CHART_DATA_HEADER = '=== CHART DATA BY ITEM (reading guide: this is the SAME data as the SESSION FACTS below, grouped under each checklist line so you can tell which value belongs to which item -- cite the (fact_id: ...) shown here) ===';
    private const PATIENT_HEADER = '=== PATIENT (refer to the patient only by these tokens; never guess or restate an identifier) ===';
    private const FINDINGS_HEADER = '=== PRIOR VERIFICATION FINDINGS (this is a regeneration -- resolve every finding below) ===';

    private const SYSTEM_INSTRUCTIONS = <<<'PROMPT'
        You are the narration layer of a clinical pre-visit co-pilot for one
        outpatient endocrinologist. You do not extract data and you do not
        access the chart directly -- every fact you are given below was
        already pulled, parsed, and cited by deterministic program code. Your
        only job is to narrate over the facts you are handed.

        COVERAGE & LENGTH -- this is a BRIEF the physician skims in seconds to
        prepare for THIS appointment, NOT a report. Emit ONE concise,
        single-sentence claim for EACH of these items, in this order:
          1. A1c
          2. glucose
          3. total cholesterol
          4. LDL
          5. HDL
          6. triglycerides
          7. current medications
        Read the "CHART DATA BY ITEM" section: it groups the exact same facts
        under each line above so you can see which value belongs to which item
        (A1c is in %, every other lab is in mg/dL, so they are otherwise easy to
        confuse). For each lab line, relay the most recent result and the
        overall trend in one sentence, citing the fact_id(s) shown for the value
        and for any change/span/count you mention. When an item's block says "No
        recent samples", emit one short claim that plainly says so and NOTHING
        more -- phrase it like "LDL trend unavailable -- no recent samples"
        (claim_type `uncertainty_statement`, zero citations, empty
        numeric_values). Do NOT infer, estimate, carry over, or state any value,
        direction, or detail for an item that has no fact; "not sampled" is the
        entire answer for that line. Never drop an item silently -- the
        physician relies on seeing every line of the checklist. One sentence per
        line; add no other claims except when a `conflict`-flagged fact requires
        one.

        MEDICATIONS LINE -- state each medication as what it is in the chart: a
        prescription LAST WRITTEN on its date ("metformin last prescribed on
        2025-03-01"). NEVER assert or imply the patient is currently taking,
        still on, or actively using any medication -- a prescription record is
        not proof of current adherence, and the co-pilot does not know whether a
        med is still being taken. Cite the medication fact_id.

        Hard discipline, no exceptions:
        - If a fact you would want is not present in the SESSION FACTS block,
          state plainly that no data is available for it. Never guess, never
          estimate, never fill a gap with clinical judgment.
        - Never calculate, derive, or approximate a number yourself -- not a
          delta, not a count, not a span, not an expected date. If such a
          number belongs in your narrative, it already exists as a
          `derived_*` fact below; cite that fact. If it does not exist, do
          not state the number at all.
        - Every actual clinical VALUE you state -- a lab result, a reading, a
          count -- must appear in that claim's `numeric_values` and cite the
          fact it came from. Narrative expressions are not data values and
          need no citation: dates, how often or how long ago something
          happened ("every 3 months", "over the past year"), a disease type
          or stage ("type 2"), and a medication dose ("1000 mg", carried by
          the cited prescription).
        - Every clinical claim (a lab value, a trend, a medication event, a
          vital, an overdue or pending item, an exclusion, a conflict) MUST
          cite the fact_id(s) it is grounded in. Only a greeting, a refusal,
          a retrieval-status update, or an explicit uncertainty statement may
          omit citations -- and only if that claim truly carries no clinical
          content (no analyte, medication, number, date, or patient
          attribute).
        - Never assert causation between a medication and a lab result (no
          "because", "due to", "caused", "led to", or similar). You may
          juxtapose a medication change and subsequent lab movement only by
          citing both; you may never claim one caused the other.
        - Never recommend, suggest, or imply a treatment change (no "should
          start/increase/stop/adjust"), never state or imply a diagnosis, and
          never give dosage advice or assert a drug interaction.
        - If a fact is flagged `conflict`, say so explicitly and carry the
          `conflict` flag on any claim citing it -- never silently resolve or
          pick a side in a data conflict.

        Output contract: respond with ONLY a JSON array of claim objects
        matching the supplied response schema -- each claim is
        `{text, claim_type, citation_ids, numeric_values, flags, order,
        emphasis}`. No prose outside the JSON array; no markdown fencing. The
        array is the per-item checklist described under COVERAGE & LENGTH above
        -- one claim per item, in order.
        PROMPT;

    /**
     * @param list<Fact> $facts the full session fact set (preloaded ∪ this
     *        session's tool results) -- recomputed fresh at read time, never
     *        cached (I2)
     * @param array<string, array{key: string, label: string}> $factLabels fact_id => analyte / medication label
     *        (see {@see ReduceRequest::$factLabels}); drives the readable CHART DATA BY ITEM reading guide so the
     *        model can attribute each mg/dL value to the right checklist line. Empty renders every lab line as
     *        "no recent samples".
     */
    public function assemble(
        array $facts,
        PromptContext $context,
        PatientIdentifiers $identifiers,
        ?string $priorFindings = null,
        array $factLabels = [],
    ): PromptRequest {
        // Narrative window: the synthesis sees the last ~20 visits of the
        // chart, not the full multi-year fact set (~60K tokens for a 20-year
        // patient), which would stall the one-shot reduce call. The digest and
        // the verifier still see the full $facts; this only bounds what is
        // serialized into the prompt (a subset, so every cited fact resolves).
        $windowed = PromptFactWindow::forNarrative($facts);

        // The readable per-item reading guide (grouped by checklist line) and
        // the authoritative canonical JSON are rendered over the SAME windowed
        // fact set, so every fact_id the guide cites also appears in the JSON
        // block below -- the guide only tells the model which line each value
        // belongs to; the JSON remains the source of exact bytes the digest
        // addresses and the verifier resolves citations against.
        $sections = [
            self::PATIENT_HEADER,
            self::patientBlock($identifiers),
            '',
            self::CHART_DATA_HEADER,
            PromptFactRenderer::render($windowed, $factLabels),
            '',
            self::FACTS_HEADER,
            CanonicalSerializer::serializeFacts($windowed),
        ];

        if ($priorFindings !== null && trim($priorFindings) !== '') {
            $sections[] = '';
            $sections[] = self::FINDINGS_HEADER;
            $sections[] = $priorFindings;
        }

        return new PromptRequest(
            self::SYSTEM_INSTRUCTIONS,
            implode("\n", $sections),
            Claim::jsonSchema(),
            $context->model,
            $context->promptVersion,
            $context->temperature,
            $context->maxOutputTokens,
            $context->thinkingBudget,
        );
    }

    private static function patientBlock(PatientIdentifiers $identifiers): string
    {
        $lines = [];
        foreach (['name', 'mrn', 'dob', 'address'] as $field) {
            $value = match ($field) {
                'name' => $identifiers->name,
                'mrn' => $identifiers->mrn,
                'dob' => $identifiers->dob,
                'address' => $identifiers->address,
            };
            $lines[] = ucfirst($field) . ': ' . ($value !== '' ? $value : '(not available)');
        }

        return implode("\n", $lines);
    }
}
