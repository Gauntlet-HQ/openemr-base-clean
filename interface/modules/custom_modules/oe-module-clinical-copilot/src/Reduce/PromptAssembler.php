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
    private const PATIENT_HEADER = '=== PATIENT (refer to the patient only by these tokens; never guess or restate an identifier) ===';
    private const FINDINGS_HEADER = '=== PRIOR VERIFICATION FINDINGS (this is a regeneration -- resolve every finding below) ===';

    private const SYSTEM_INSTRUCTIONS = <<<'PROMPT'
        You are the narration layer of a clinical pre-visit co-pilot for one
        outpatient endocrinologist. You do not extract data and you do not
        access the chart directly -- every fact you are given below was
        already pulled, parsed, and cited by deterministic program code. Your
        only job is to narrate over the facts you are handed.

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
        emphasis}`. No prose outside the JSON array; no markdown fencing.
        PROMPT;

    /**
     * @param list<Fact> $facts the full session fact set (preloaded ∪ this
     *        session's tool results) -- recomputed fresh at read time, never
     *        cached (I2)
     */
    public function assemble(
        array $facts,
        PromptContext $context,
        PatientIdentifiers $identifiers,
        ?string $priorFindings = null,
    ): PromptRequest {
        // Narrative window: the synthesis sees the last ~20 visits of the
        // chart, not the full multi-year fact set (~60K tokens for a 20-year
        // patient), which would stall the one-shot reduce call. The digest and
        // the verifier still see the full $facts; this only bounds what is
        // serialized into the prompt (a subset, so every cited fact resolves).
        $sections = [
            self::PATIENT_HEADER,
            self::patientBlock($identifiers),
            '',
            self::FACTS_HEADER,
            CanonicalSerializer::serializeFacts(PromptFactWindow::forNarrative($facts)),
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
