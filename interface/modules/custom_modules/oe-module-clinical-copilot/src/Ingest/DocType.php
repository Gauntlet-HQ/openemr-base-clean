<?php

/**
 * The closed set of Week 2 document-ingestion types.
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
 * The closed set of patient-attached document types, grown deliberately (the
 * Week 2 spec's "make two work reliably before supporting five" pitfall):
 * `intake_form` drives the new-patient creation flow, `lab_pdf` drives the
 * patient-page Labs tab, and `medication_list` is the third type — extract +
 * human review only; chart reconciliation of medications is deliberately NOT
 * implemented (see {@see ExtractionReview::lock()}). The backing value is what
 * lands in `mod_copilot_extraction.doc_type`.
 */
enum DocType: string
{
    case IntakeForm = 'intake_form';
    case LabPdf = 'lab_pdf';
    case MedicationList = 'medication_list';

    /**
     * The relative filename (under src/Ingest/schema/) of the strict schema
     * that is the source of truth for this doc type's extraction — raw VLM
     * output never bypasses it.
     */
    public function schemaFile(): string
    {
        return match ($this) {
            self::IntakeForm => 'intake_form.schema.json',
            self::LabPdf => 'lab_pdf.schema.json',
            self::MedicationList => 'medication_list.schema.json',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::IntakeForm => 'Intake form',
            self::LabPdf => 'Lab report (PDF)',
            self::MedicationList => 'Medication list',
        };
    }
}
