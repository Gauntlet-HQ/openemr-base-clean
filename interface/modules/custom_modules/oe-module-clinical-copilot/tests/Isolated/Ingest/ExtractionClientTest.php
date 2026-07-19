<?php

/**
 * The vision extractor: valid model output parses; bad output is rejected; no model degrades.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Ingest;

use OpenEMR\Modules\ClinicalCopilot\Ingest\DocType;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Ingest\SchemaValidationException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce\StubLlmClient;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: (1) a hallucinated / malformed model response becoming
 * a persisted fact instead of being discarded; (2) the whole ingestion flow
 * dead-ending when no model is configured, rather than degrading to manual
 * entry. No live model calls, ever — the LlmClientInterface stub stands in.
 */
final class ExtractionClientTest extends TestCase
{
    public function testValidModelOutputBecomesTypedExtraction(): void
    {
        $json = json_encode(['fields' => [
            ['field_key' => 'Hemoglobin A1c', 'value' => '7.2', 'unit' => '%', 'reference_range' => '4.0-5.6', 'page' => 1, 'quote' => 'A1c 7.2 %'],
        ]], JSON_THROW_ON_ERROR);

        $client = new ExtractionClient(
            StubLlmClient::up(new LlmResponse($json, 'gemini-2.5-pro', 1200, 40, 850)),
            'gemini-2.5-pro',
        );

        $outcome = $client->extract(DocType::LabPdf, 'PDFBYTES', 'application/pdf', 'upload');

        self::assertSame('gemini-2.5-pro', $outcome->modelVersion);
        self::assertSame(1200, $outcome->tokensIn);
        self::assertCount(1, $outcome->extraction->fields);
        self::assertSame('7.2', $outcome->extraction->fields[0]->value);
    }

    public function testIntakeExtractionSucceedsWithoutPerFieldCitations(): void
    {
        // Regression: intake prefills a form with no click-to-source overlay, so
        // it must NOT require a page/quote citation per field. Previously the
        // shared lab-style contract forced one, so the model — asked to cite every
        // blank demographic field — produced output that failed the request and
        // degraded to "extraction unavailable". A key+value payload must now parse.
        $json = json_encode(['fields' => [
            ['field_key' => 'first_name', 'value' => 'Jane'],
            ['field_key' => 'last_name', 'value' => 'Doe'],
            ['field_key' => 'middle_name', 'value' => null],
        ]], JSON_THROW_ON_ERROR);

        $client = new ExtractionClient(
            StubLlmClient::up(new LlmResponse($json, 'gemini-2.5-pro', 300, 20, 120)),
            'gemini-2.5-pro',
        );

        $outcome = $client->extract(DocType::IntakeForm, 'PDFBYTES', 'application/pdf', 'upload');

        self::assertCount(3, $outcome->extraction->fields);
        self::assertSame('Jane', $outcome->extraction->fields[0]->value);
        self::assertNull($outcome->extraction->fields[0]->citation);
    }

    public function testCitationIsMandatoryInTheLabPromptButOptionalInTheIntakePrompt(): void
    {
        // Failure mode guarded: the prompts drifting back to the shared
        // lab-first contract. Labs keep the MUST-cite clause (page/quote/bbox
        // feed the click-to-source overlay). Intake gets the SOFT clause —
        // invite a page + short verbatim quote when clearly identifiable, omit
        // otherwise — and must never regain "MUST" citation language or a bbox
        // demand: commanding a citation for every (often blank) demographic
        // field is exactly what over-constrained the model and degraded intake
        // to "extraction unavailable".
        $json = json_encode(['fields' => []], JSON_THROW_ON_ERROR);
        $stub = StubLlmClient::up(new LlmResponse($json, 'gemini-2.5-pro', 1, 1, 1));
        $client = new ExtractionClient($stub, 'gemini-2.5-pro');

        $client->extract(DocType::LabPdf, 'PDFBYTES', 'application/pdf', 'upload');
        $client->extract(DocType::IntakeForm, 'PDFBYTES', 'application/pdf', 'upload');

        $labPrompt = $stub->calls()[0]->systemInstructions;
        $intakePrompt = $stub->calls()[1]->systemInstructions;

        self::assertStringContainsString('MUST', $labPrompt);
        self::assertStringContainsString('bounding box', $labPrompt);

        self::assertStringContainsString('page', $intakePrompt, 'intake softly invites the optional citation');
        self::assertStringContainsString('quote', $intakePrompt);
        self::assertStringContainsString('omit', $intakePrompt, 'omitting the citation must be explicitly allowed');
        self::assertStringNotContainsString('MUST', $intakePrompt, 'never demand citations from intake again');
        self::assertStringNotContainsString('bounding box', $intakePrompt, 'intake has no overlay, so no bbox ask');
    }

    public function testIntakeExtractionWithVolunteeredCitationsCapturesThem(): void
    {
        // The flip side of the citation-free regression test above: when the
        // model DOES volunteer the optional page/quote, they must survive
        // end-to-end into the parsed field's citation (the review screen shows
        // them as a p.N deep link + quote tooltip) — while the uncited sibling
        // field in the same payload stays valid with a null citation.
        $json = json_encode(['fields' => [
            ['field_key' => 'first_name', 'value' => 'Jane', 'page' => 1, 'quote' => 'Name: Jane Doe'],
            ['field_key' => 'last_name', 'value' => 'Doe'],
        ]], JSON_THROW_ON_ERROR);

        $client = new ExtractionClient(
            StubLlmClient::up(new LlmResponse($json, 'gemini-2.5-pro', 300, 20, 120)),
            'gemini-2.5-pro',
        );

        $outcome = $client->extract(DocType::IntakeForm, 'PDFBYTES', 'application/pdf', 'upload');

        $cited = $outcome->extraction->fields[0];
        self::assertNotNull($cited->citation);
        self::assertSame(1, $cited->citation->pageOrSection);
        self::assertSame('Name: Jane Doe', $cited->citation->quoteOrValue);
        self::assertNull($outcome->extraction->fields[1]->citation, 'uncited fields stay valid alongside cited ones');
    }

    public function testMedicationPromptDemandsExactTranscriptionAndNeverDemandsCitations(): void
    {
        // Failure modes guarded: (1) the medication prompt losing its
        // exactness clause, letting the model "helpfully" normalize a dose
        // (500 mg -> 0.5 g) or expand an abbreviation (BID -> twice daily) —
        // silently rewriting a prescription before any human reviews it; and
        // (2) the prompt regaining lab-style MUST-cite / bounding-box language,
        // which over-constrains the model into failing otherwise-good reads —
        // the exact regression the intake prompt already guards against.
        // Citations stay on the intake-style OPTIONAL clause.
        $json = json_encode(['fields' => []], JSON_THROW_ON_ERROR);
        $stub = StubLlmClient::up(new LlmResponse($json, 'gemini-2.5-pro', 1, 1, 1));
        $client = new ExtractionClient($stub, 'gemini-2.5-pro');

        $client->extract(DocType::MedicationList, 'PDFBYTES', 'application/pdf', 'upload');

        $prompt = $stub->calls()[0]->systemInstructions;

        self::assertStringContainsString('exactly as written', $prompt);
        self::assertStringContainsString('Never normalize', $prompt);
        self::assertStringContainsString('never infer', $prompt);

        self::assertStringContainsString('page', $prompt, 'medication softly invites the optional citation');
        self::assertStringContainsString('quote', $prompt);
        self::assertStringContainsString('omit', $prompt, 'omitting the citation must be explicitly allowed');
        self::assertStringNotContainsString('MUST', $prompt, 'never demand citations from a medication list');
        self::assertStringNotContainsString('bounding box', $prompt, 'no overlay is fed, so no bbox ask');

        $user = $stub->calls()[0]->userContent;
        self::assertStringContainsString('medication_name', $user, 'the grouping convention rides in the user instruction');
        self::assertStringContainsString('document order', $user);
    }

    public function testMedicationListExtractionParsesWithAndWithoutVolunteeredCitations(): void
    {
        // End-to-end through the client: a valid attribute-run payload parses;
        // a volunteered citation lands on the field and an uncited sibling in
        // the same payload stays valid with a null citation (never an error).
        $json = json_encode(['fields' => [
            ['field_key' => 'medication_name', 'value' => 'Metformin', 'page' => 1, 'quote' => 'Metformin 500 mg'],
            ['field_key' => 'dose', 'value' => '500 mg'],
        ]], JSON_THROW_ON_ERROR);

        $client = new ExtractionClient(
            StubLlmClient::up(new LlmResponse($json, 'gemini-2.5-pro', 300, 20, 120)),
            'gemini-2.5-pro',
        );

        $outcome = $client->extract(DocType::MedicationList, 'PDFBYTES', 'application/pdf', 'upload');

        self::assertCount(2, $outcome->extraction->fields);
        self::assertNotNull($outcome->extraction->fields[0]->citation);
        self::assertSame(1, $outcome->extraction->fields[0]->citation->pageOrSection);
        self::assertNull($outcome->extraction->fields[1]->citation);
    }

    public function testMedicationListOutputWithAnUnknownFieldKeyIsRejected(): void
    {
        // The closed enum is the backstop even when constrained decoding
        // misbehaves: a hallucinated attribute key never becomes a stored row.
        $json = json_encode(['fields' => [
            ['field_key' => 'strength', 'value' => '500 mg'],
        ]], JSON_THROW_ON_ERROR);

        $client = new ExtractionClient(
            StubLlmClient::up(new LlmResponse($json, 'gemini-2.5-pro', 10, 10, 10)),
            'gemini-2.5-pro',
        );

        $this->expectException(SchemaValidationException::class);
        $client->extract(DocType::MedicationList, 'PDFBYTES', 'application/pdf', 'upload');
    }

    public function testModelOutputThatFailsTheSchemaIsRejected(): void
    {
        // value present but no page/quote — fails the citation-required contract.
        $json = json_encode(['fields' => [['field_key' => 'A1c', 'value' => '7.2']]], JSON_THROW_ON_ERROR);

        $client = new ExtractionClient(
            StubLlmClient::up(new LlmResponse($json, 'gemini-2.5-pro', 10, 10, 10)),
            'gemini-2.5-pro',
        );

        $this->expectException(SchemaValidationException::class);
        $client->extract(DocType::LabPdf, 'PDFBYTES', 'application/pdf', 'upload');
    }

    public function testNonJsonModelOutputIsRejected(): void
    {
        $client = new ExtractionClient(
            StubLlmClient::up(new LlmResponse('not json at all', 'gemini-2.5-pro', 10, 10, 10)),
            'gemini-2.5-pro',
        );

        $this->expectException(SchemaValidationException::class);
        $client->extract(DocType::LabPdf, 'PDFBYTES', 'application/pdf', 'upload');
    }

    public function testNoModelPropagatesUnavailableForTheManualFallback(): void
    {
        $client = new ExtractionClient(StubLlmClient::down(), 'gemini-2.5-pro');

        $this->expectException(LlmUnavailableException::class);
        $client->extract(DocType::IntakeForm, 'PDFBYTES', 'application/pdf', 'upload');
    }

    public function testTheDocumentIsSentAsAMultimodalPart(): void
    {
        $json = json_encode(['fields' => []], JSON_THROW_ON_ERROR);
        $stub = StubLlmClient::up(new LlmResponse($json, 'gemini-2.5-pro', 1, 1, 1));

        (new ExtractionClient($stub, 'gemini-2.5-pro'))
            ->extract(DocType::LabPdf, 'RAWPDFBYTES', 'application/pdf', 'upload');

        $request = $stub->calls()[0] ?? null;
        self::assertNotNull($request);
        self::assertCount(1, $request->parts, 'the document must ride as an inline part');
        self::assertSame('application/pdf', $request->parts[0]->mimeType);
        self::assertSame(base64_encode('RAWPDFBYTES'), $request->parts[0]->base64Data);
    }
}
