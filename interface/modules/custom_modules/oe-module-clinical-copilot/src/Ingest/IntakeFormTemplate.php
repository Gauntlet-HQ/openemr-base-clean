<?php

/**
 * Blank OpenEMR-compliant intake form: the printable HTML behind the PDF.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

/**
 * Produces the blank patient-intake form staff hand to a patient: print it, the
 * patient fills it in, staff scan it, and the scan goes back through
 * `intake_upload.php` → Gemini extraction → review → chart. It is the front of
 * the loop the module already implements the back half of.
 *
 * "OpenEMR-compliant" here means two things: (1) every field maps to a real
 * `patient_data` column (or the intake clinical sections), and (2) the visible
 * label for each field is the EXACT enum value the extraction schema
 * (`src/Ingest/schema/intake_form.schema.json`) accepts, printed alongside the
 * human label — so the vision model reads a form whose fields already match the
 * strict schema it must emit, maximising extraction accuracy and keeping the
 * form, the schema, and the chart columns in lock-step.
 *
 * This class is pure (no I/O, no PDF library): it only builds HTML. The mPDF
 * render + streaming lives in `public/intake_form_pdf.php`, so the field
 * contract here is unit-testable with no toolchain.
 */
final class IntakeFormTemplate
{
    /**
     * The intake fields, grouped for the printed layout. `key` is the exact
     * `intake_form.schema.json` enum value; `patient_data` is the core column it
     * commits to (null = a clinical section, not a demographics column); `lines`
     * is how much write space to leave.
     *
     * @return list<array{section: string, fields: list<array{key: string, label: string, patient_data: ?string, lines: int}>}>
     */
    public static function sections(): array
    {
        return [
            // Mirrors OpenEMR's "Who" section on the Add-Patient form. sex/DOB
            // are the validated columns: birth sex must be spelled out
            // (Male/Female) and DOB written YYYY-MM-DD, or core rejects them.
            [
                'section' => 'Who',
                'fields' => [
                    ['key' => 'title', 'label' => 'Title (Mr., Mrs., Dr., …)', 'patient_data' => 'title', 'lines' => 1],
                    ['key' => 'first_name', 'label' => 'First name', 'patient_data' => 'fname', 'lines' => 1],
                    ['key' => 'middle_name', 'label' => 'Middle name', 'patient_data' => 'mname', 'lines' => 1],
                    ['key' => 'last_name', 'label' => 'Last name', 'patient_data' => 'lname', 'lines' => 1],
                    ['key' => 'name_suffix', 'label' => 'Suffix (Jr., Sr., III, …)', 'patient_data' => 'suffix', 'lines' => 1],
                    ['key' => 'date_of_birth', 'label' => 'Date of birth (YYYY-MM-DD)', 'patient_data' => 'DOB', 'lines' => 1],
                    ['key' => 'sex', 'label' => 'Birth sex (Male / Female)', 'patient_data' => 'sex', 'lines' => 1],
                    ['key' => 'marital_status', 'label' => 'Marital status (Single / Married / …)', 'patient_data' => 'status', 'lines' => 1],
                    ['key' => 'ssn', 'label' => 'Social Security No.', 'patient_data' => 'ss', 'lines' => 1],
                ],
            ],
            // Mirrors OpenEMR's "Contact" section.
            [
                'section' => 'Contact',
                'fields' => [
                    ['key' => 'phone', 'label' => 'Home phone', 'patient_data' => 'phone_home', 'lines' => 1],
                    ['key' => 'phone_mobile', 'label' => 'Mobile phone', 'patient_data' => 'phone_cell', 'lines' => 1],
                    ['key' => 'phone_work', 'label' => 'Work phone', 'patient_data' => 'phone_biz', 'lines' => 1],
                    ['key' => 'email', 'label' => 'Email', 'patient_data' => 'email', 'lines' => 1],
                    ['key' => 'address_street', 'label' => 'Street address', 'patient_data' => 'street', 'lines' => 1],
                    ['key' => 'address_line2', 'label' => 'Address line 2', 'patient_data' => 'street_line_2', 'lines' => 1],
                    ['key' => 'address_city', 'label' => 'City', 'patient_data' => 'city', 'lines' => 1],
                    ['key' => 'address_state', 'label' => 'State / Province', 'patient_data' => 'state', 'lines' => 1],
                    ['key' => 'address_postal', 'label' => 'Postal code', 'patient_data' => 'postal_code', 'lines' => 1],
                    ['key' => 'country', 'label' => 'Country', 'patient_data' => 'country_code', 'lines' => 1],
                    ['key' => 'county', 'label' => 'County', 'patient_data' => 'county', 'lines' => 1],
                    ['key' => 'mothers_name', 'label' => "Mother's name", 'patient_data' => 'mothersname', 'lines' => 1],
                    ['key' => 'emergency_contact', 'label' => 'Emergency contact (name & relationship)', 'patient_data' => 'contact_relationship', 'lines' => 1],
                    ['key' => 'emergency_phone', 'label' => 'Emergency contact phone', 'patient_data' => 'phone_contact', 'lines' => 1],
                ],
            ],
            // Mirrors OpenEMR's "Stats" section (list-backed demographics).
            [
                'section' => 'Background',
                'fields' => [
                    ['key' => 'race', 'label' => 'Race', 'patient_data' => 'race', 'lines' => 1],
                    ['key' => 'ethnicity', 'label' => 'Ethnicity', 'patient_data' => 'ethnicity', 'lines' => 1],
                    ['key' => 'language', 'label' => 'Preferred language', 'patient_data' => 'language', 'lines' => 1],
                ],
            ],
            // Mirrors OpenEMR's "Employer" section.
            [
                'section' => 'Employment',
                'fields' => [
                    ['key' => 'occupation', 'label' => 'Occupation', 'patient_data' => 'occupation', 'lines' => 1],
                    ['key' => 'employer_name', 'label' => 'Employer name', 'patient_data' => 'em_name', 'lines' => 1],
                    ['key' => 'employer_street', 'label' => 'Employer street address', 'patient_data' => 'em_street', 'lines' => 1],
                    ['key' => 'employer_city', 'label' => 'Employer city', 'patient_data' => 'em_city', 'lines' => 1],
                    ['key' => 'employer_state', 'label' => 'Employer state', 'patient_data' => 'em_state', 'lines' => 1],
                    ['key' => 'employer_postal', 'label' => 'Employer postal code', 'patient_data' => 'em_postal_code', 'lines' => 1],
                ],
            ],
            [
                'section' => 'Reason for visit & history',
                'fields' => [
                    ['key' => 'chief_concern', 'label' => 'Chief concern / reason for visit', 'patient_data' => null, 'lines' => 3],
                    ['key' => 'current_medications', 'label' => 'Current medications (name, dose, frequency)', 'patient_data' => null, 'lines' => 4],
                    ['key' => 'allergies', 'label' => 'Allergies (and reaction)', 'patient_data' => null, 'lines' => 3],
                    ['key' => 'family_history', 'label' => 'Family history', 'patient_data' => null, 'lines' => 3],
                ],
            ],
        ];
    }

    /**
     * A fully-filled SAMPLE intake (synthetic data only, OPEN-1 — never real
     * PHI). Rendering this and re-uploading it exercises the whole intake loop
     * (vision extraction → review → create patient) end-to-end without anyone
     * hand-scanning a form; because the values are typed, not handwritten,
     * extraction is near-perfect, which makes it a clean demo. Every schema enum
     * key has a value here (guarded by IntakeFormTemplateTest).
     *
     * @return array<string, string> field_key => value
     */
    public static function sample(): array
    {
        return [
            'title' => 'Ms.',
            'first_name' => 'Jordan',
            'middle_name' => 'A.',
            'last_name' => 'Rivera',
            'name_suffix' => '',
            'date_of_birth' => '1968-04-11',
            'sex' => 'Female',
            'marital_status' => 'Married',
            'ssn' => '521-83-0047',
            'phone' => '(415) 555-0182',
            'phone_mobile' => '(415) 555-0147',
            'phone_work' => '',
            'email' => 'jordan.rivera@example.com',
            'address_street' => '19 Birchwood Lane',
            'address_line2' => 'Apt 4',
            'address_city' => 'Springfield',
            'address_state' => 'CA',
            'address_postal' => '94062',
            'country' => 'USA',
            'county' => 'San Mateo',
            'mothers_name' => 'Elena Rivera',
            'emergency_contact' => 'Marcus Rivera (spouse)',
            'emergency_phone' => '(415) 555-0190',
            'race' => 'White',
            'ethnicity' => 'Not Hispanic or Latino',
            'language' => 'English',
            'occupation' => 'Teacher',
            'employer_name' => 'Springfield Unified School District',
            'employer_street' => '400 Oak Avenue',
            'employer_city' => 'Springfield',
            'employer_state' => 'CA',
            'employer_postal' => '94062',
            'chief_concern' => 'Follow-up for type 2 diabetes; rising home glucose readings.',
            'current_medications' => 'Metformin 1000mg twice daily; Lisinopril 10mg daily; Atorvastatin 20mg nightly.',
            'allergies' => 'Penicillin (rash).',
            'family_history' => 'Mother: type 2 diabetes. Father: hypertension, myocardial infarction at 61.',
        ];
    }

    /**
     * The full printable HTML document (single self-contained string, inline
     * CSS only — mPDF renders no external assets). Pass a `field_key => value`
     * map to render a FILLED form (e.g. {@see self::sample()}); omit it for the
     * blank form patients hand-fill.
     *
     * @param array<string, string> $values
     */
    public static function html(array $values = []): string
    {
        $filled = $values !== [];
        $rows = '';
        foreach (self::sections() as $section) {
            $rows .= '<h2 class="sec">' . self::esc($section['section']) . '</h2>';
            foreach ($section['fields'] as $field) {
                $rows .= self::fieldBlock($field['label'], $field['key'], $field['lines'], $values[$field['key']] ?? null);
            }
        }

        $subtitle = $filled
            ? 'SAMPLE — synthetic data for testing the intake pipeline (not a real patient). Upload this to exercise extraction → review → create-patient end-to-end.'
            : 'Please print clearly. Staff will scan this form; the clinical co-pilot reads it, and a staff member verifies every field before anything is saved to the chart.';
        $foot = $filled
            ? 'SAMPLE intake — synthetic test data only (OPEN-1). No real PHI.'
            : 'Patient signature: _______________________________&nbsp;&nbsp;&nbsp;Date: ____________&nbsp;&nbsp;|&nbsp;&nbsp;For office use — scan &amp; upload via Patient &rarr; New Patient from Intake PDF.';

        $subtitleHtml = self::esc($subtitle);
        $footHtml = $filled ? self::esc($foot) : $foot;

        return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><style>
  body { font-family: DejaVuSans, sans-serif; color: #111; font-size: 11pt; }
  .title { font-size: 17pt; font-weight: bold; margin: 0; }
  .subtitle { color: #555; font-size: 9.5pt; margin: 2px 0 10px; }
  h2.sec { font-size: 12pt; background: #f0f2f5; padding: 5px 8px; margin: 14px 0 8px; border-left: 3px solid #6b7785; }
  .field { margin: 0 0 9px; }
  .flabel { font-weight: bold; font-size: 10.5pt; }
  .fkey { color: #8a94a0; font-size: 8pt; }
  .line { border-bottom: 1px solid #333; height: 16px; margin-top: 3px; }
  .answer { border-bottom: 1px solid #333; min-height: 16px; margin-top: 3px; font-size: 11pt; }
  .foot { margin-top: 16px; color: #666; font-size: 8pt; border-top: 1px solid #ccc; padding-top: 6px; }
</style></head><body>
  <p class="title">Patient Intake Form</p>
  <p class="subtitle">{$subtitleHtml}</p>
  {$rows}
  <p class="foot">{$footHtml}</p>
</body></html>
HTML;
    }

    /**
     * One label + either the printed value (filled form) or blank write-lines.
     */
    private static function fieldBlock(string $label, string $key, int $lines, ?string $value): string
    {
        if ($value !== null && $value !== '') {
            $body = '<div class="answer">' . self::esc($value) . '</div>';
        } else {
            $body = str_repeat('<div class="line"></div>', max(1, $lines));
        }

        return '<div class="field"><span class="flabel">' . self::esc($label) . '</span> '
            . '<span class="fkey">[' . self::esc($key) . ']</span>' . $body . '</div>';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES);
    }
}
