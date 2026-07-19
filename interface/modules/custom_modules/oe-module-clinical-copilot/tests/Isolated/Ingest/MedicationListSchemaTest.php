<?php

/**
 * The medication_list contract: closed key set, exact transcription, optional citations.
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
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionSchema;
use PHPUnit\Framework\TestCase;

/**
 * Failure modes guarded for the third patient-attached document type: (1) an
 * out-of-vocabulary field_key sneaking a made-up medication attribute past the
 * closed enum (the intake convention — medication attribute names are fixed,
 * so an unknown key is always a model error); (2) the optional-citation
 * contract regressing to lab-style MUST-cite, which would reject an
 * otherwise-good read whenever the model omits a page/quote (the original
 * intake breakage); (3) an uncheckable citation — a non-integer or
 * non-positive page next to a real value — being persisted instead of
 * refused (the global page rule); (4) the document-order attribute runs
 * (medication_name starts each medication) being reordered or dropped in
 * parse, which would attach doses to the wrong drug on the review screen.
 */
final class MedicationListSchemaTest extends TestCase
{
    public function testValidMedicationListValidatesAndParsesInDocumentOrder(): void
    {
        // Two medications as document-order attribute runs, each starting at
        // a medication_name entry. Values are verbatim-as-printed strings.
        $payload = ['fields' => [
            ['field_key' => 'medication_name', 'value' => 'Metformin', 'page' => 1, 'quote' => 'Metformin 500 mg PO BID'],
            ['field_key' => 'dose', 'value' => '500 mg', 'page' => 1, 'quote' => 'Metformin 500 mg PO BID'],
            ['field_key' => 'route', 'value' => 'PO', 'page' => 1, 'quote' => 'Metformin 500 mg PO BID'],
            ['field_key' => 'frequency', 'value' => 'BID', 'page' => 1, 'quote' => 'Metformin 500 mg PO BID'],
            ['field_key' => 'prn', 'value' => null],
            ['field_key' => 'medication_name', 'value' => 'Lisinopril', 'page' => 1, 'quote' => 'Lisinopril 10 mg daily'],
            ['field_key' => 'dose', 'value' => '10 mg', 'page' => 1, 'quote' => 'Lisinopril 10 mg daily'],
            ['field_key' => 'prescriber', 'value' => 'Dr. A. Chen', 'page' => 2, 'quote' => 'Prescriber: Dr. A. Chen'],
        ]];

        self::assertSame([], ExtractionSchema::validate(DocType::MedicationList, $payload));

        $parsed = ExtractionSchema::parse(DocType::MedicationList, $payload, 'upload');

        // Document order is the medication grouping: parse must preserve it.
        self::assertSame(
            ['medication_name', 'dose', 'route', 'frequency', 'prn', 'medication_name', 'dose', 'prescriber'],
            array_map(static fn ($f): string => $f->fieldKey, $parsed->fields),
        );
        self::assertSame('Metformin', $parsed->fields[0]->value);
        self::assertSame('Lisinopril', $parsed->fields[5]->value);
        // Volunteered citations survive into the parsed field.
        self::assertNotNull($parsed->fields[0]->citation);
        self::assertSame(1, $parsed->fields[0]->citation->pageOrSection);
        self::assertSame('Metformin 500 mg PO BID', $parsed->fields[0]->citation->quoteOrValue);
        // A medication list carries no patient-identity header keys.
        self::assertNull($parsed->patientName);
        self::assertNull($parsed->patientDob);
    }

    public function testValuedFieldsWithoutCitationsStillValidate(): void
    {
        // Citations are OPTIONAL (the intake convention): a pharmacy printout
        // read without clean page/quote provenance must still prefill the
        // review — demanding a citation per attribute is exactly the
        // over-constraint that degraded intake to a blank form.
        $payload = ['fields' => [
            ['field_key' => 'medication_name', 'value' => 'Atorvastatin'],
            ['field_key' => 'dose', 'value' => '20 mg'],
            ['field_key' => 'frequency', 'value' => 'nightly'],
        ]];

        self::assertSame([], ExtractionSchema::validate(DocType::MedicationList, $payload));

        $parsed = ExtractionSchema::parse(DocType::MedicationList, $payload, 'upload');
        self::assertNull($parsed->fields[0]->citation, 'no volunteered citation means a null citation, never an error');
    }

    public function testInvalidPageOnAValuedFieldIsRejected(): void
    {
        // The global page rule holds here too: a page supplied alongside a
        // real value must be a positive 1-based integer — "one", "3"
        // (JSON-decoded ints stay int, so a quoted page is always a model
        // error), 2.0, true, zero, and negatives can never address a real
        // page, so the extraction is refused, not persisted uncheckable.
        foreach (['one', '3', 2.0, true, 0, -1] as $badPage) {
            $payload = ['fields' => [
                ['field_key' => 'medication_name', 'value' => 'Metformin', 'page' => $badPage, 'quote' => 'q'],
            ]];

            self::assertNotSame(
                [],
                ExtractionSchema::validate(DocType::MedicationList, $payload),
                'page ' . var_export($badPage, true) . ' must reject the extraction',
            );
        }
    }

    public function testUnknownFieldKeyIsRejectedByTheClosedEnum(): void
    {
        // field_key is a CLOSED enum (the intake convention): the attribute
        // vocabulary is fixed, so an out-of-vocabulary key — a hallucinated
        // "strength", a lab-style free-text drug name in field_key — is a
        // model error, never a new column.
        $payload = ['fields' => [
            ['field_key' => 'strength', 'value' => '500 mg'],
        ]];

        $errors = ExtractionSchema::validate(DocType::MedicationList, $payload);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('medication_list field enum', implode('; ', $errors));
    }

    public function testValueMustBePresentEvenWhenNull(): void
    {
        // Same present-but-nullable value contract as every other doc type:
        // an entry with no value key at all is malformed model output.
        $payload = ['fields' => [
            ['field_key' => 'medication_name'],
        ]];

        self::assertNotSame([], ExtractionSchema::validate(DocType::MedicationList, $payload));
    }

    public function testBlankExtractionSeedsOneEmptyAttributePerEnumKey(): void
    {
        // The degraded path (no model / rejected output): the reviewer gets a
        // single blank medication skeleton — one empty row per attribute key —
        // to hand-fill against the document, never a dead end. vlmValue stays
        // null so hand-entered rows never count toward extraction accuracy.
        $blank = ExtractionSchema::blankExtraction(DocType::MedicationList);

        self::assertSame(
            ['medication_name', 'dose', 'route', 'frequency', 'prn', 'prescriber'],
            array_map(static fn ($f): string => $f->fieldKey, $blank->fields),
        );
        foreach ($blank->fields as $field) {
            self::assertNull($field->value);
            self::assertNull($field->vlmValue);
        }
    }
}
