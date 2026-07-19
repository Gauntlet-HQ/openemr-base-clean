<?php

/**
 * The schema-is-source-of-truth gate: valid payloads parse, invalid ones are rejected.
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
 * Failure mode guarded: raw VLM output bypassing the strict contract and
 * becoming a persisted, uncited, or out-of-vocabulary fact. Each assertion maps
 * to the `schema_valid` / `citation_present` eval rubric categories.
 */
final class ExtractionSchemaTest extends TestCase
{
    public function testValidIntakePayloadHasNoErrors(): void
    {
        $payload = ['fields' => [
            ['field_key' => 'first_name', 'value' => 'Ada', 'page' => 1, 'quote' => 'Name: Ada', 'confidence' => 0.9, 'bbox' => [10, 20, 30, 40]],
            ['field_key' => 'chief_concern', 'value' => null, 'page' => 1, 'quote' => 'Reason for visit:'],
        ]];

        self::assertSame([], ExtractionSchema::validate(DocType::IntakeForm, $payload));
    }

    public function testBlankFieldsWithoutACitationDoNotRejectTheExtraction(): void
    {
        // A comprehensive intake form has many blank fields; the model returns
        // them as value:null with an empty quote (nothing to cite). These must NOT
        // reject the whole extraction (which would blank the review form) — a
        // citation is only meaningful for a field that actually has a value.
        $payload = ['fields' => [
            ['field_key' => 'first_name', 'value' => 'Ada', 'page' => 1, 'quote' => 'Name: Ada'],
            ['field_key' => 'middle_name', 'value' => null, 'page' => 0, 'quote' => ''],
            ['field_key' => 'ssn', 'value' => null, 'quote' => ''],
        ]];

        self::assertSame([], ExtractionSchema::validate(DocType::IntakeForm, $payload));
    }

    public function testIntakeDoesNotRequireCitationsSoAValuedFieldWithoutOneValidates(): void
    {
        // Intake prefills a form beside the PDF — it has no click-to-source
        // overlay — so citations are not required. A valued field with no
        // page/quote must NOT reject the extraction (that blanked the form).
        $payload = ['fields' => [
            ['field_key' => 'first_name', 'value' => 'Ada'],
            ['field_key' => 'last_name', 'value' => 'Lovelace'],
        ]];

        self::assertSame([], ExtractionSchema::validate(DocType::IntakeForm, $payload));
    }

    public function testNonIntegerPageOnAValuedFieldIsRejected(): void
    {
        // Failure mode guarded (eval case ref-07): the VLM cites page "one"
        // for a field that has a value. A page that is not a positive 1-based
        // integer — a string (even a numeric "3": JSON-decoded integers stay
        // int, so a quoted page is always a model error), a float like 2.0,
        // a bool, zero, or a negative — can never address a real page in the
        // source document, so the extraction must be refused rather than
        // persisting an uncheckable citation. Blank fields keep their page:0
        // tolerance (see testBlankFieldsWithoutACitationDoNotRejectTheExtraction).
        foreach (['one', '3', 2.0, true, 0, -1] as $badPage) {
            $payload = ['fields' => [
                ['field_key' => 'first_name', 'value' => 'Ada', 'page' => $badPage, 'quote' => 'q'],
            ]];
            $errors = ExtractionSchema::validate(DocType::IntakeForm, $payload);

            self::assertNotSame([], $errors, 'page ' . var_export($badPage, true) . ' must reject the extraction');
            self::assertStringContainsString('page', implode(' ', $errors));
        }
    }

    public function testLabMissingPageIsRejectedBecauseCitationIsRequired(): void
    {
        // Labs drive the bbox overlay, so a valued result MUST cite its page.
        $payload = ['fields' => [['field_key' => 'A1c', 'value' => '7.2', 'quote' => 'A1c 7.2']]];
        $errors = ExtractionSchema::validate(DocType::LabPdf, $payload);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('page', implode(' ', $errors));
    }

    public function testLabMissingQuoteIsRejectedBecauseCitationIsRequired(): void
    {
        $payload = ['fields' => [['field_key' => 'A1c', 'value' => '7.2', 'page' => 1]]];
        $errors = ExtractionSchema::validate(DocType::LabPdf, $payload);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('quote', implode(' ', $errors));
    }

    public function testFieldKeyOutsideIntakeEnumIsRejected(): void
    {
        $payload = ['fields' => [['field_key' => 'social_security_number', 'value' => '1', 'page' => 1, 'quote' => 'x']]];
        $errors = ExtractionSchema::validate(DocType::IntakeForm, $payload);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('enum', implode(' ', $errors));
    }

    public function testValueMustBePresentEvenWhenNull(): void
    {
        // value key entirely absent (not merely null) is a contract violation.
        $payload = ['fields' => [['field_key' => 'first_name', 'page' => 1, 'quote' => 'x']]];
        $errors = ExtractionSchema::validate(DocType::IntakeForm, $payload);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('value', implode(' ', $errors));
    }

    public function testOutOfRangeConfidenceIsRejected(): void
    {
        $payload = ['fields' => [['field_key' => 'first_name', 'value' => 'Ada', 'page' => 1, 'quote' => 'x', 'confidence' => 1.7]]];
        $errors = ExtractionSchema::validate(DocType::IntakeForm, $payload);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('confidence', implode(' ', $errors));
    }

    public function testLabTestNamesAreOpenVocabulary(): void
    {
        $payload = ['fields' => [
            ['field_key' => 'Hemoglobin A1c', 'value' => '7.2', 'unit' => '%', 'reference_range' => '4.0-5.6', 'page' => 1, 'quote' => 'A1c 7.2 %'],
        ]];

        self::assertSame([], ExtractionSchema::validate(DocType::LabPdf, $payload));
    }

    public function testParseBuildsCitationsFromValidatedPayload(): void
    {
        $payload = ['fields' => [
            ['field_key' => 'Hemoglobin A1c', 'value' => '7.2', 'unit' => '%', 'page' => 1, 'quote' => 'A1c 7.2 %', 'bbox' => [10, 20, 30, 40]],
        ]];

        $parsed = ExtractionSchema::parse(DocType::LabPdf, $payload, 'extraction:7');
        self::assertCount(1, $parsed->fields);

        $field = $parsed->fields[0];
        self::assertSame('Hemoglobin A1c', $field->fieldKey);
        self::assertSame('7.2', $field->vlmValue);
        self::assertSame('7.2', $field->value, 'value starts equal to the model value (unedited)');
        self::assertNotNull($field->citation);
        self::assertSame(1, $field->citation->pageOrSection);
        self::assertSame('A1c 7.2 %', $field->citation->quoteOrValue);
        self::assertNotNull($field->citation->bbox);
    }

    public function testIntakeWithoutAnyCitationValidatesAndParsesClean(): void
    {
        // Failure mode guarded (the original intake breakage): page/quote are
        // OPTIONAL on intake — they were once required, which over-constrained
        // the model (forced to cite every blank demographic field) and made
        // extraction fail into a blank manual form. A citation-free payload
        // must validate with zero errors AND parse into citation-free fields;
        // nothing downstream may treat the missing citation as an error.
        $payload = ['fields' => [
            ['field_key' => 'first_name', 'value' => 'Ada'],
            ['field_key' => 'middle_name', 'value' => null],
        ]];

        self::assertSame([], ExtractionSchema::validate(DocType::IntakeForm, $payload));

        $parsed = ExtractionSchema::parse(DocType::IntakeForm, $payload, 'upload');
        self::assertCount(2, $parsed->fields);
        self::assertSame('Ada', $parsed->fields[0]->value);
        self::assertNull($parsed->fields[0]->citation, 'no volunteered citation => none, silently');
        self::assertNull($parsed->fields[1]->citation);
    }

    public function testIntakeWithVolunteeredPageAndQuoteCapturesThemIntoTheCitation(): void
    {
        // Failure mode guarded: the model volunteers the optional page/quote
        // (per the softened prompt) but parse() drops them, so the review
        // screen loses the "p.N + quote" verification aid the spec asks for.
        // Intake has no bbox overlay, so the citation must tolerate bbox null.
        $payload = ['fields' => [
            ['field_key' => 'first_name', 'value' => 'Ada', 'page' => 2, 'quote' => 'Patient: Ada Lovelace'],
        ]];

        self::assertSame([], ExtractionSchema::validate(DocType::IntakeForm, $payload));

        $field = ExtractionSchema::parse(DocType::IntakeForm, $payload, 'upload')->fields[0];
        self::assertNotNull($field->citation);
        self::assertSame(2, $field->citation->pageOrSection);
        self::assertSame('Patient: Ada Lovelace', $field->citation->quoteOrValue);
        self::assertNull($field->citation->bbox, 'intake has no overlay, so no bbox — and that must be fine');
    }

    public function testIntakePageWithoutAQuoteStillLandsInACitationViaTheValue(): void
    {
        // Failure mode guarded: a valid volunteered page arriving WITHOUT a
        // quote used to be dropped on the floor (SourceCitation requires a
        // non-empty quote_or_value, and there was no quote to satisfy it). The
        // spec's citation shape is quote OR value, so the field's own value
        // stands in and the page survives into the review screen's deep link.
        $payload = ['fields' => [
            ['field_key' => 'last_name', 'value' => 'Lovelace', 'page' => 3],
        ]];

        self::assertSame([], ExtractionSchema::validate(DocType::IntakeForm, $payload));

        $field = ExtractionSchema::parse(DocType::IntakeForm, $payload, 'upload')->fields[0];
        self::assertNotNull($field->citation);
        self::assertSame(3, $field->citation->pageOrSection);
        self::assertSame('Lovelace', $field->citation->quoteOrValue, 'the value stands in as quote_or_value');
    }

    public function testParseCarriesThePrintedCollectionDateThrough(): void
    {
        // Failure mode guarded (W5): the schema + prompt request the printed
        // specimen collection_date, but parse() used to drop it — so the
        // review screen's draw-date field always defaulted to today and the
        // committed procedure rows were dated on upload day, not draw day.
        $payload = [
            'collection_date' => '2026-01-10',
            'fields' => [
                ['field_key' => 'Hemoglobin A1c', 'value' => '7.2', 'unit' => '%', 'page' => 1, 'quote' => 'A1c 7.2 %'],
            ],
        ];

        self::assertSame([], ExtractionSchema::validate(DocType::LabPdf, $payload));
        $parsed = ExtractionSchema::parse(DocType::LabPdf, $payload, 'extraction:7');

        self::assertSame('2026-01-10', $parsed->collectionDate);
        self::assertCount(1, $parsed->fields, 'the result fields must be untouched by the header date');
    }

    public function testMissingOrGarbageCollectionDateDegradesToNullWithoutRejectingTheExtraction(): void
    {
        // Failure mode guarded (W5): a bad header date must NOT reject the
        // whole extraction (which would blank every result the model DID read
        // correctly). Like its header siblings patient_name/patient_dob,
        // collection_date is advisory prefill metadata the reviewer can always
        // override — so anything that is not a real calendar date in strict
        // Y-m-d degrades to null and the review screen falls back to today.
        // Contrast with `page` (W1b), where a bad value breaks a citation's
        // integrity and rightly rejects.
        $garbage = [null, '', 12345, 2026.0, true, '01/10/2026', 'Jan 10, 2026', '2026-1-9', '2026-13-45', '2026-02-30', ['2026-01-10']];
        foreach ($garbage as $bad) {
            $payload = [
                'collection_date' => $bad,
                'fields' => [
                    ['field_key' => 'Hemoglobin A1c', 'value' => '7.2', 'unit' => '%', 'page' => 1, 'quote' => 'A1c 7.2 %'],
                ],
            ];

            self::assertSame([], ExtractionSchema::validate(DocType::LabPdf, $payload), 'collection_date ' . var_export($bad, true) . ' must not reject the extraction');
            $parsed = ExtractionSchema::parse(DocType::LabPdf, $payload, 'extraction:7');
            self::assertNull($parsed->collectionDate, 'collection_date ' . var_export($bad, true) . ' must degrade to null');
            self::assertCount(1, $parsed->fields, 'the result fields must survive a garbage header date');
        }

        // The key being entirely absent (intake schemas have no such key) is
        // the same null, not an error.
        $payload = ['fields' => [
            ['field_key' => 'Hemoglobin A1c', 'value' => '7.2', 'unit' => '%', 'page' => 1, 'quote' => 'A1c 7.2 %'],
        ]];
        self::assertSame([], ExtractionSchema::validate(DocType::LabPdf, $payload));
        self::assertNull(ExtractionSchema::parse(DocType::LabPdf, $payload, 'extraction:7')->collectionDate);
    }

    public function testBlankIntakeExtractionSeedsEveryEnumKey(): void
    {
        $blank = ExtractionSchema::blankExtraction(DocType::IntakeForm);

        self::assertNotSame([], $blank->fields);
        foreach ($blank->fields as $field) {
            self::assertNull($field->vlmValue, 'blank fields never count toward accuracy');
            self::assertNull($field->value);
        }
        self::assertNull($blank->fieldAccuracy(), 'no model claims => no accuracy to measure');
    }

    public function testResponseSchemaLoadsForBothDocTypes(): void
    {
        self::assertArrayHasKey('properties', ExtractionSchema::responseSchema(DocType::IntakeForm));
        self::assertArrayHasKey('properties', ExtractionSchema::responseSchema(DocType::LabPdf));
    }
}
