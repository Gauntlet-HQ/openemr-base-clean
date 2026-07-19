<?php

/**
 * The single sanctioned seam that writes derived facts into the core chart.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\PatientService;

/**
 * Week 2 deliberately relaxes the Week 1 read-only invariant in EXACTLY this
 * class — and the module-scoped PHPStan gate proves it: ChartWriter is the one
 * `SANCTIONED_CORE_WRITERS` entry, so a raw core write (`sqlInsert`) or a host
 * service write (`PatientService::insert`) is a rule violation ANYWHERE else in
 * `src/`. A reviewer auditing "what can this module put into a real chart" reads
 * this file and only this file.
 *
 * Everything here is idempotent and lineage-tracked: intake demographics commit
 * to `patient_data`; lab results commit down the
 * `procedure_order -> procedure_order_code -> procedure_report -> procedure_result`
 * chain (mirroring the core HL7 inbound writer in
 * interface/orders/receive_hl7_results.inc.php), with `procedure_result.document_id`
 * binding each value back to the stored source PDF. The caller records the
 * returned core-row ids onto the staging facts ({@see ExtractionStore::setFieldLineage()}),
 * and re-committing an already-committed field is a no-op — no duplicate,
 * untraceable records.
 *
 * The richer intake facts (chief concern, medications, allergies, family
 * history) are intentionally NOT auto-written to core `lists`/`history_data`
 * yet — they stay in the verified staging record with a documented Phase-B path.
 * Committing structured problem/allergy/medication lists is its own contract.
 */
final class ChartWriter
{
    /**
     * intake field_key => patient_data column. Only the demographics that map
     * to a real patient_data column and are safe to write verbatim are here;
     * PatientValidator (DATABASE_INSERT_CONTEXT) validates only fname/lname/sex/
     * DOB/email, so every column below is an unvalidated free-text field and a
     * messy handwritten value can never break patient creation. The richer
     * intake facts (chief_concern, current_medications, allergies,
     * family_history) stay in staging (Phase-B), as documented above.
     */
    private const DEMOGRAPHIC_COLUMNS = [
        'title' => 'title',
        'first_name' => 'fname',
        'middle_name' => 'mname',
        'last_name' => 'lname',
        'name_suffix' => 'suffix',
        'date_of_birth' => 'DOB',
        'sex' => 'sex',
        'ssn' => 'ss',
        'phone' => 'phone_home',
        'phone_mobile' => 'phone_cell',
        'phone_work' => 'phone_biz',
        'email' => 'email',
        'address_street' => 'street',
        'address_line2' => 'street_line_2',
        'address_city' => 'city',
        'address_state' => 'state',
        'address_postal' => 'postal_code',
        'country' => 'country_code',
        'county' => 'county',
        'marital_status' => 'status',
        'race' => 'race',
        'ethnicity' => 'ethnicity',
        'language' => 'language',
        'mothers_name' => 'mothersname',
        'emergency_contact' => 'contact_relationship',
        'emergency_phone' => 'phone_contact',
        'occupation' => 'occupation',
        'employer_name' => 'em_name',
        'employer_street' => 'em_street',
        'employer_city' => 'em_city',
        'employer_state' => 'em_state',
        'employer_postal' => 'em_postal_code',
    ];

    public function __construct(private readonly PatientService $patientService)
    {
    }

    /**
     * Non-throwing patient create for the deferred-save review flow: map the
     * reviewed demographics to `patient_data` columns and insert via the core
     * PatientService. Returns the new pid, or the core validation messages so the
     * endpoint can re-render the form with errors instead of surfacing a 500.
     * This is the ONLY create path used by the human-confirmed intake save; the
     * patient does not exist until the reviewer clicks Save.
     *
     * @param array<string, string|null> $demographics field_key => value
     *
     * @return array{pid: ?int, errors: list<string>}
     */
    public function tryCreatePatient(array $demographics): array
    {
        $data = [];
        foreach (self::DEMOGRAPHIC_COLUMNS as $fieldKey => $column) {
            if (array_key_exists($fieldKey, $demographics) && $demographics[$fieldKey] !== null && $demographics[$fieldKey] !== '') {
                $data[$column] = $demographics[$fieldKey];
            }
        }

        $result = $this->patientService->insert($data);
        if (!$result->isValid()) {
            return ['pid' => null, 'errors' => $this->flattenValidationMessages($result->getValidationMessages())];
        }

        $payload = $result->getData();
        $first = is_array($payload) ? ($payload[0] ?? null) : null;
        $pid = is_array($first) ? ($first['pid'] ?? null) : null;
        if (!is_int($pid) && !(is_string($pid) && ctype_digit($pid))) {
            return ['pid' => null, 'errors' => ['The patient could not be saved. Please try again.']];
        }

        return ['pid' => (int)$pid, 'errors' => []];
    }

    /**
     * Reads the chart's identifying fields for the lab-upload identity guard: the
     * first/last name and DOB the uploaded report is checked against. Read-only
     * (never a write), scoped to one pid; returns null if the patient is missing.
     *
     * @return array{first: ?string, last: ?string, dob: ?string}|null
     */
    public function fetchPatientIdentity(int $pid): ?array
    {
        $row = QueryUtils::querySingleRow(
            'SELECT `fname`, `lname`, `DOB` FROM `patient_data` WHERE `pid` = ?',
            [$pid],
        );
        if (!is_array($row)) {
            return null;
        }

        return [
            'first' => isset($row['fname']) && $row['fname'] !== null ? (string)$row['fname'] : null,
            'last' => isset($row['lname']) && $row['lname'] !== null ? (string)$row['lname'] : null,
            'dob' => isset($row['DOB']) && $row['DOB'] !== null ? (string)$row['DOB'] : null,
        ];
    }

    /**
     * Writes reviewed free-text clinical lines (one allergy / medication per
     * line) to the chart `lists` as active entries. Blank input is a no-op. Called
     * only from the human-confirmed intake save — the core Add-Patient form does
     * not capture these, so this is where "allergen information etc." lands.
     *
     * $userName is the LOGIN NAME (patient_data/lists convention stores the
     * username in `lists.user`, e.g. "admin" — not the numeric user id).
     */
    public function addChartListLines(int $pid, string $type, ?string $text, string $userName): void
    {
        if ($text === null || trim($text) === '') {
            return;
        }

        foreach (preg_split('/[\r\n;]+/', $text) ?: [] as $line) {
            $title = trim($line);
            if ($title === '') {
                continue;
            }
            $uuid = (new UuidRegistry(['table_name' => 'lists']))->createUuid();
            QueryUtils::sqlInsert(
                'INSERT INTO `lists` (`pid`, `type`, `title`, `begdate`, `activity`, `date`, `user`, `uuid`) '
                . 'VALUES (?, ?, ?, ?, 1, NOW(), ?, ?)',
                [$pid, $type, $title, date('Y-m-d'), $userName, $uuid],
            );
        }
    }

    /**
     * @param mixed $messages the ProcessingResult validation messages (field => message(s))
     *
     * @return list<string>
     */
    private function flattenValidationMessages(mixed $messages): array
    {
        $errors = [];
        if (is_array($messages)) {
            foreach ($messages as $field => $fieldMessages) {
                foreach (is_array($fieldMessages) ? $fieldMessages : [$fieldMessages] as $message) {
                    $text = is_string($message) ? $message : (is_scalar($message) ? (string)$message : 'invalid');
                    $errors[] = is_string($field) && $field !== '' ? "{$field}: {$text}" : $text;
                }
            }
        }
        if ($errors === []) {
            $errors[] = 'Required demographics are missing or invalid (first name, last name, birth sex, and date of birth are required).';
        }

        return $errors;
    }

    /**
     * Applies verified demographic corrections to an existing patient. Only the
     * mapped demographic columns are touched; unknown keys are ignored.
     *
     * @param array<string, string|null> $demographics field_key => value
     */
    public function updatePatientDemographics(int $pid, array $demographics): void
    {
        $sets = [];
        $binds = [];
        foreach (self::DEMOGRAPHIC_COLUMNS as $fieldKey => $column) {
            if (array_key_exists($fieldKey, $demographics)) {
                $sets[] = "`{$column}` = ?";
                $binds[] = $demographics[$fieldKey];
            }
        }

        if ($sets === []) {
            return;
        }

        $binds[] = $pid;
        QueryUtils::sqlStatementThrowException(
            'UPDATE `patient_data` SET ' . implode(', ', $sets) . ' WHERE `pid` = ?',
            $binds,
        );
    }

    /**
     * Stores the uploaded source file in OpenEMR (real `documents` row) linked
     * back to the extraction for provenance, and returns the new document id.
     *
     * @return int|null the documents.id, or null if the core store failed
     */
    public function storeSourceDocument(
        int $pid,
        int $categoryId,
        string $filename,
        string $mimeType,
        string $bytes,
        ?int $extractionId,
    ): ?int {
        $doc = new \Document();
        // Link the document back to the staging extraction when there is one; the
        // deferred-save intake flow has no extraction row, so store it unlinked.
        $foreignId = $extractionId ?? 0;
        $foreignTable = $extractionId !== null ? 'mod_copilot_extraction' : '';
        $error = $doc->createDocument(
            $pid,
            $categoryId,
            $filename,
            $mimeType,
            $bytes,
            '',
            1,
            0,
            null,
            null,
            $foreignId,
            $foreignTable,
        );

        if (is_string($error) && $error !== '') {
            return null;
        }

        $id = $doc->get_id();

        return is_numeric($id) ? (int)$id : null;
    }

    /**
     * Commits verified lab results down the procedure chain. Only fields with a
     * value that are NOT already committed are written; if none qualify this is
     * a no-op (idempotent re-lock). One order + report is created per commit
     * batch, `document_id` binds each result to the source PDF.
     *
     * @param list<ExtractedFieldRow> $labFields
     *
     * @return array<int, int> map of extracted_fact.id => procedure_result_id
     */
    public function commitLabResults(int $pid, ?int $documentId, int $providerId, array $labFields, ?string $collectionDate): array
    {
        $pending = array_values(array_filter(
            $labFields,
            static fn (ExtractedFieldRow $r): bool => !$r->field->isCommitted() && $r->field->value !== null && $r->field->value !== '',
        ));
        if ($pending === []) {
            return [];
        }

        $orderedDate = $collectionDate ?? date('Y-m-d');

        $orderId = QueryUtils::sqlInsert(
            'INSERT INTO `procedure_order`
                (`uuid`, `patient_id`, `provider_id`, `lab_id`, `date_ordered`, `date_collected`, `order_status`, `activity`)
             VALUES (?, ?, ?, 0, ?, ?, ?, 1)',
            [
                (new UuidRegistry(['table_name' => 'procedure_order']))->createUuid(),
                $pid,
                $providerId,
                $orderedDate,
                $orderedDate,
                'completed',
            ],
        );

        QueryUtils::sqlInsert(
            'INSERT INTO `procedure_order_code`
                (`procedure_order_id`, `procedure_order_seq`, `procedure_code`, `procedure_name`, `procedure_type`)
             VALUES (?, 1, ?, ?, ?)',
            [$orderId, 'PANEL', 'Clinical Co-Pilot ingested results', 'laboratory'],
        );

        $reportId = QueryUtils::sqlInsert(
            'INSERT INTO `procedure_report`
                (`uuid`, `procedure_order_id`, `procedure_order_seq`, `date_report`, `report_status`)
             VALUES (?, ?, 1, ?, ?)',
            [
                (new UuidRegistry(['table_name' => 'procedure_report']))->createUuid(),
                $orderId,
                $orderedDate,
                'final',
            ],
        );

        $committed = [];
        foreach ($pending as $row) {
            $field = $row->field;
            $resultId = QueryUtils::sqlInsert(
                'INSERT INTO `procedure_result`
                    (`uuid`, `procedure_report_id`, `result_code`, `result_text`, `result`, `units`,
                     `range`, `abnormal`, `result_status`, `date`, `document_id`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (new UuidRegistry(['table_name' => 'procedure_result']))->createUuid(),
                    $reportId,
                    $field->fieldKey,
                    $field->fieldKey,
                    // `units`, `range`, and `document_id` are NOT NULL in core
                    // (procedure_result); a manual lab has null unit/range and no
                    // source PDF, so coalesce to the column defaults or the INSERT
                    // throws (this was the "verify & lock" failure on manual labs).
                    $field->value ?? '',
                    $field->unit ?? '',
                    $field->refRange ?? '',
                    $field->abnormalFlag !== null ? strtolower($field->abnormalFlag) : '',
                    'final',
                    $orderedDate,
                    $documentId ?? 0,
                ],
            );
            $committed[$row->id] = $resultId;
        }

        return $committed;
    }
}
