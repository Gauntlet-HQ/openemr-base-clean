<?php

/**
 * Runs one document through the vision model and returns validated, typed facts.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

use OpenEMR\Modules\ClinicalCopilot\Reduce\InlineDataPart;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;

/**
 * The Week 2 inverse of {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer}:
 * Reducer narrates over facts PHP already extracted; this class asks the model
 * to EXTRACT facts from a document — but under the exact same discipline.
 * Constrained decoding against the strict schema does the first pass;
 * {@see ExtractionSchema::validate()} is the backstop that keeps raw model
 * output from ever becoming a persisted fact ({@see SchemaValidationException}).
 *
 * It depends only on {@see LlmClientInterface} (never a concrete provider), so
 * every test binds a stub — no live model calls, exactly as Week 1. With no
 * credentials the injected client throws {@see LlmUnavailableException}, which
 * propagates so the endpoint can fall back to manual entry.
 */
final class ExtractionClient
{
    private const PROMPT_VERSION = 'ingest-extract-v2';

    private const SYSTEM_INSTRUCTIONS_BASE =
        "You are a careful clinical document transcriber. Read the attached document and "
        . "extract ONLY values that are literally present. Never infer, complete, or invent a "
        . "value: if a field is blank, illegible, or absent, return its value as null. ";

    // Labs drive a click-to-source bbox overlay on the review screen, so each result must
    // cite where it was read from. Intake does NOT: its values prefill a plain form beside
    // the PDF with no overlay, so demanding a page/quote/bbox for every demographic field
    // (including the blank ones) only over-constrains the model and fails an otherwise-good
    // read — the exact "extraction unavailable" degrade seen on intake. Only labs get it.
    private const SYSTEM_INSTRUCTIONS_CITATION =
        "For every field you MUST return the 1-based page it appears on, the exact source "
        . "text as `quote`, and a bounding box `[x0,y0,x1,y1]` normalized to 0-1000. ";

    // Intake citations are OPTIONAL (mirrors intake_form.schema.json, where page/quote sit
    // outside the required array): softly invite a citation when the source text is clearly
    // identifiable, never demand one — a MUST-cite clause here is exactly what broke intake
    // extraction before. Deliberately no bbox: intake has no overlay to feed.
    private const SYSTEM_INSTRUCTIONS_CITATION_OPTIONAL =
        "When the source text for a field is clearly identifiable, also include the 1-based "
        . "page it appears on and a short verbatim quote of that text; omit page and quote "
        . "entirely otherwise — never guess or invent a citation. ";

    // Medication lists are clinical-safety-sensitive transcription: a "normalized"
    // dose (500 mg -> 0.5 g), an expanded abbreviation (BID -> twice daily), or an
    // inferred route silently rewrites a prescription before any human sees it.
    // The clause pins the model to verbatim transcription; citations stay on the
    // OPTIONAL intake-style clause (no overlay, so never a MUST-cite demand).
    private const SYSTEM_INSTRUCTIONS_MEDICATION_EXACTNESS =
        "Transcribe each medication exactly as written on the document. Never normalize, "
        . "convert, abbreviate, or expand a dose, route, or frequency, and never infer a "
        . "missing one — a blank or illegible attribute is null. ";

    private const SYSTEM_INSTRUCTIONS_TAIL =
        "Return output strictly matching the provided JSON schema and nothing else.";

    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly string $model,
    ) {
    }

    /**
     * @param string $documentBytes the raw file bytes (PDF or image)
     *
     * @throws LlmUnavailableException when no model is configured/reachable
     * @throws SchemaValidationException when the model output fails the contract
     */
    public function extract(DocType $docType, string $documentBytes, string $mimeType, string $sourceId): ExtractionOutcome
    {
        $request = new PromptRequest(
            systemInstructions: $this->systemInstructions($docType),
            userContent: $this->userInstruction($docType),
            responseSchema: ExtractionSchema::responseSchema($docType),
            model: $this->model,
            promptVersion: self::PROMPT_VERSION,
            temperature: 0.0,
            parts: [InlineDataPart::fromBytes($mimeType, $documentBytes)],
        );

        $response = $this->llm->generateStructured($request);

        $decoded = json_decode($response->rawJson, true);
        if (!is_array($decoded)) {
            throw new SchemaValidationException(['model output was not a JSON object'], $docType);
        }

        $errors = ExtractionSchema::validate($docType, $decoded);
        if ($errors !== []) {
            throw new SchemaValidationException($errors, $docType);
        }

        return new ExtractionOutcome(
            extraction: ExtractionSchema::parse($docType, $decoded, $sourceId),
            modelVersion: $response->modelVersion,
            promptVersion: self::PROMPT_VERSION,
            tokensIn: $response->tokensIn,
            tokensOut: $response->tokensOut,
            latencyMs: $response->latencyMs,
        );
    }

    /**
     * The transcriber system prompt, tailored per doc type. Labs require a
     * per-field citation (they drive the click-to-source overlay); intake and
     * medication lists get the OPTIONAL clause — cite when clearly
     * identifiable, omit otherwise — matching their schemas, where page/quote
     * are optional properties. Medication lists additionally get the
     * exactness clause: transcribe verbatim, never normalize or infer doses.
     */
    private function systemInstructions(DocType $docType): string
    {
        $middle = match ($docType) {
            DocType::LabPdf => self::SYSTEM_INSTRUCTIONS_CITATION,
            DocType::IntakeForm => self::SYSTEM_INSTRUCTIONS_CITATION_OPTIONAL,
            DocType::MedicationList => self::SYSTEM_INSTRUCTIONS_MEDICATION_EXACTNESS
                . self::SYSTEM_INSTRUCTIONS_CITATION_OPTIONAL,
        };

        return self::SYSTEM_INSTRUCTIONS_BASE . $middle . self::SYSTEM_INSTRUCTIONS_TAIL;
    }

    private function userInstruction(DocType $docType): string
    {
        return match ($docType) {
            DocType::IntakeForm =>
                'Extract the patient intake fields from this form: demographics (name, date of '
                . 'birth, sex, contact, address), chief concern, current medications, allergies, and '
                . 'family history. Use only the field_key values allowed by the schema.',
            DocType::LabPdf =>
                'Extract every discrete lab result from this report. For each result capture the test '
                . 'name (field_key), value, unit, reference range, and abnormal flag exactly as '
                . 'printed. Also capture the specimen collection_date if present. From the report '
                . 'header capture the patient_name and patient_dob (YYYY-MM-DD) exactly as printed, or '
                . 'null if absent — these identify whose results these are so the report can be '
                . 'matched to the correct chart. Never invent an identity value.',
            DocType::MedicationList =>
                'Extract every medication from this medication list. Emit attribute entries in '
                . 'document order using only the field_key values allowed by the schema: start '
                . 'each medication with a medication_name entry, then its dose, route, frequency, '
                . 'prn (as-needed) flag, and prescriber as printed — each attribute exactly as '
                . 'written, null when blank or illegible.',
        };
    }
}
