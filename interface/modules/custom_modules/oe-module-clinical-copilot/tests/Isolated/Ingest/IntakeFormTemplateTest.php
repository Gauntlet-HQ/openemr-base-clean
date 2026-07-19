<?php

/**
 * IntakeFormTemplate: the blank form stays in lock-step with the extraction schema.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Ingest;

use OpenEMR\Modules\ClinicalCopilot\Ingest\IntakeFormTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: the printed intake form drifting from the extraction
 * schema — a field on the paper the model has no enum for, or an enum key with
 * no box on the paper — which silently loses data on every scan. The form's
 * printed field keys MUST be exactly the intake_form.schema.json enum.
 */
final class IntakeFormTemplateTest extends TestCase
{
    /** @return list<string> the schema's field_key enum */
    private function schemaEnum(): array
    {
        $schema = json_decode(
            (string)file_get_contents(dirname(__DIR__, 3) . '/src/Ingest/schema/intake_form.schema.json'),
            true,
        );
        /** @var list<string> $enum */
        $enum = $schema['properties']['fields']['items']['properties']['field_key']['enum'];

        return $enum;
    }

    public function testFormFieldsAreExactlyTheSchemaEnum(): void
    {
        $formKeys = [];
        foreach (IntakeFormTemplate::sections() as $section) {
            foreach ($section['fields'] as $field) {
                $formKeys[] = $field['key'];
            }
        }

        $enum = $this->schemaEnum();
        sort($enum);
        sort($formKeys);

        self::assertSame($enum, $formKeys, 'the printed form must carry exactly the schema enum keys — no more, no fewer');
    }

    public function testHtmlPrintsEveryFieldKeyAndIsSelfContained(): void
    {
        $html = IntakeFormTemplate::html();

        foreach ($this->schemaEnum() as $key) {
            self::assertStringContainsString('[' . $key . ']', $html, "form is missing field {$key}");
        }
        // mPDF renders no external assets — the document must be self-contained.
        self::assertStringNotContainsString('http://', $html);
        self::assertStringNotContainsString('src=', $html);
    }

    public function testEveryDemographicFieldDeclaresItsPatientDataColumn(): void
    {
        foreach (IntakeFormTemplate::sections() as $section) {
            foreach ($section['fields'] as $field) {
                self::assertArrayHasKey('patient_data', $field);
                self::assertArrayHasKey('lines', $field);
                self::assertGreaterThanOrEqual(1, $field['lines']);
            }
        }
    }

    public function testSampleCoversEverySchemaKeyAndRendersAFilledForm(): void
    {
        $sample = IntakeFormTemplate::sample();

        // Every enum key must have an entry (a realistic blank is allowed, e.g.
        // a patient with no suffix), so a filled render never omits a field.
        foreach ($this->schemaEnum() as $key) {
            self::assertArrayHasKey($key, $sample, "sample is missing field {$key}");
        }
        // Synthetic only (OPEN-1): the sample must not carry a real-looking key
        // outside the schema, and the filled HTML must print the sample values.
        $html = IntakeFormTemplate::html($sample);
        self::assertStringContainsString('Rivera', $html);
        self::assertStringContainsString('1968-04-11', $html);
        self::assertStringContainsString('SAMPLE', $html, 'a filled form must be marked as a sample');
    }
}
