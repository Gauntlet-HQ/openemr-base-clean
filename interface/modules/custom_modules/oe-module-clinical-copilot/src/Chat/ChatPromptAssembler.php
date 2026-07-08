<?php

/**
 * Builds one chat round's request: system prompt + facts + narrative + conversation + tools.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmRequest;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolDefinition;
use OpenEMR\Modules\ClinicalCopilot\Fact\CanonicalSerializer;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptContext;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;

/**
 * The system prompt mirrors {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler}'s
 * hard-discipline block (USERS.md §1's refusals apply identically to chat,
 * ARCHITECTURE.md §1.1/§1.4) plus chat-specific framing: navigation via
 * tools, never extraction (I13), and the general-medical-Q&A / other-patient
 * refusal rules that have no verifier check of their own (§1.4) and so must
 * be carried entirely by the prompt.
 *
 * Every round -- tool-deciding or final-answering alike -- gets the FULL
 * facts block via {@see CanonicalSerializer::serializeFacts()}, the same
 * canonicalization the digest and the synthesis prompt use: a chat round is
 * never told "trust what you remember from last round," it is re-shown the
 * authoritative session fact set every time (cheap; these are small JSON
 * payloads, not re-extractions).
 */
final class ChatPromptAssembler
{
    private const FACTS_HEADER = '=== SESSION FACTS (canonical JSON -- authoritative; do not alter, recompute, or invent any value) ===';
    private const NARRATIVE_HEADER = '=== PRE-VISIT SYNTHESIS ALREADY SHOWN TO THE PHYSICIAN (for context only; every claim you make must still cite a fact above) ===';
    private const PATIENT_HEADER = '=== PATIENT (refer to the patient only by these tokens; never guess or restate an identifier) ===';
    private const CONVERSATION_HEADER = '=== CONVERSATION SO FAR (oldest first) ===';
    private const FINDINGS_HEADER = '=== PRIOR VERIFICATION FINDINGS (this is a retry -- resolve every finding below using only the facts already shown; no new tool calls) ===';
    private const QUESTION_HEADER = '=== THE PHYSICIAN\'S CURRENT QUESTION ===';

    private const SYSTEM_INSTRUCTIONS = <<<'PROMPT'
        You are the conversational layer of a clinical pre-visit co-pilot for
        one outpatient endocrinologist, answering a follow-up question about
        the ONE patient this conversation is pinned to. You do not extract
        data and you do not access the chart directly -- every fact you are
        given was already pulled, parsed, and cited by deterministic program
        code, either at the start of this conversation or by a tool call you
        requested. Your only jobs are to narrate over facts you are handed and
        to decide which tool (if any) to call next.

        Hard discipline, no exceptions (identical to the synthesis you are
        continuing):
        - If a fact you would want is not present in the SESSION FACTS block,
          either request the tool that would fetch it, or state plainly that
          no data is available -- never guess, estimate, or fill a gap with
          clinical judgment.
        - Never calculate, derive, or approximate a number yourself. If a
          delta/count/span/expected-date belongs in your answer, cite the
          existing `derived_*` fact; if none exists, do not state the number.
        - Every clinical claim MUST cite the fact_id(s) it is grounded in.
          Only a greeting, a refusal, a retrieval-status update, or an
          explicit uncertainty statement may omit citations, and only when it
          truly carries no clinical content.
        - Never assert causation between a medication and a lab result. Never
          recommend, suggest, or imply a treatment change, never state or
          imply a diagnosis, never give dosage advice, never assert a drug
          interaction not already present as a fact.
        - If a fact is flagged `conflict`, say so explicitly and carry the
          `conflict` flag on any claim citing it.
        - You may only ever discuss the ONE patient pinned to this
          conversation. If asked about any other patient, refuse in prose --
          you have no tool that could answer about anyone else regardless.
        - If asked a general medical knowledge question not grounded in this
          patient's own data (e.g. "what's the target A1c for her age?"),
          refuse and point to the physician's own clinical guidelines --
          do not answer it, even approximately.

        Tool use: you may request one of the declared tools when the SESSION
        FACTS block does not already answer the question (most follow-ups are
        already answered by what is shown -- check first). You may chain
        tools when one tool's result determines the next tool's arguments
        (e.g. a medication's start date bounding a vitals window). When you
        are ready to answer and need no further tool calls, respond with ONLY
        a JSON array of claim objects matching the supplied response schema
        -- each claim is `{text, claim_type, citation_ids, numeric_values,
        flags, order, emphasis}`. No prose outside the JSON array; no
        markdown fencing.
        PROMPT;

    /**
     * @param list<Fact> $sessionFacts the full accumulated session fact set (preloaded UNION every tool result so far)
     * @param list<Claim>|null $narrativeClaims the doc's own narrative, for context only
     * @param list<string> $conversationTranscript pre-rendered lines, oldest first
     * @param list<ToolDefinition> $toolsOffered empty on the post-retry, no-new-tools round
     */
    public function assemble(
        array $sessionFacts,
        ?array $narrativeClaims,
        array $conversationTranscript,
        string $userQuestion,
        array $toolsOffered,
        ?string $priorFindings,
        PromptContext $context,
        PatientIdentifiers $identifiers,
    ): ChatLlmRequest {
        $sections = [
            self::PATIENT_HEADER,
            self::patientBlock($identifiers),
            '',
            self::FACTS_HEADER,
            CanonicalSerializer::serializeFacts($sessionFacts),
        ];

        if ($narrativeClaims !== null && $narrativeClaims !== []) {
            $sections[] = '';
            $sections[] = self::NARRATIVE_HEADER;
            $sections[] = self::narrativeBlock($narrativeClaims);
        }

        if ($conversationTranscript !== []) {
            $sections[] = '';
            $sections[] = self::CONVERSATION_HEADER;
            $sections[] = implode("\n", $conversationTranscript);
        }

        if ($priorFindings !== null && trim($priorFindings) !== '') {
            $sections[] = '';
            $sections[] = self::FINDINGS_HEADER;
            $sections[] = $priorFindings;
        }

        $sections[] = '';
        $sections[] = self::QUESTION_HEADER;
        $sections[] = $userQuestion;

        $prompt = new PromptRequest(
            self::SYSTEM_INSTRUCTIONS,
            implode("\n", $sections),
            Claim::jsonSchema(),
            $context->model,
            $context->promptVersion,
            $context->temperature,
            $context->maxOutputTokens,
        );

        return new ChatLlmRequest($prompt, $toolsOffered);
    }

    /**
     * @param list<Claim> $claims
     */
    private static function narrativeBlock(array $claims): string
    {
        $lines = [];
        foreach ($claims as $claim) {
            $lines[] = '- ' . $claim->text;
        }

        return implode("\n", $lines);
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
