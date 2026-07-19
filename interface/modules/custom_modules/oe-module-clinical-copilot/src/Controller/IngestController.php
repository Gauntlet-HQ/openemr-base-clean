<?php

/**
 * Composition root + request handlers for the Week 2 document-ingestion flows.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Controller;

use OpenEMR\Modules\ClinicalCopilot\Ingest\AttachAndExtract;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ChartWriter;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractedField;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionReview;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionStore;
use OpenEMR\Modules\ClinicalCopilot\Ingest\IngestResult;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory;
use OpenEMR\Services\PatientService;

/**
 * Thin, endpoint-facing wiring — the same pattern as {@see DocController}. It
 * owns no business logic itself; it constructs the ingestion collaborators (all
 * of which degrade cleanly with no LLM configured) and exposes one method per
 * endpoint action. Uploaded-file plumbing (superglobal `$_FILES`) is read in the
 * endpoint and handed here as plain bytes + metadata, so this class never
 * touches a superglobal.
 */
final class IngestController
{
    /** OpenEMR document category for stored source files; 1 is the root category. */
    private const DEFAULT_DOCUMENT_CATEGORY_ID = 1;

    public function __construct(
        private readonly AttachAndExtract $ingest,
        private readonly ExtractionStore $store,
        private readonly ExtractionReview $review,
    ) {
    }

    public static function createDefault(): self
    {
        $tracer = new TraceRecorder();
        $store = new ExtractionStore();
        $chartWriter = new ChartWriter(new PatientService());
        $extractionClient = new ExtractionClient(
            LlmClientFactory::create(),
            LlmRuntimeConfig::synthesisModel(),
        );

        $ingest = new AttachAndExtract(
            $extractionClient,
            $store,
            $chartWriter,
            self::DEFAULT_DOCUMENT_CATEGORY_ID,
            $tracer,
        );

        return new self($ingest, $store, new ExtractionReview($store, $chartWriter, $tracer));
    }

    /**
     * Deferred-save intake: extract the fields but create/persist NOTHING, for
     * the human-reviewed create-at-save flow. `citations` is the sparse map of
     * OPTIONAL page/quote citations the model volunteered (often empty — never
     * required for intake).
     *
     * @return array{fields: array<string, string|null>, citations: array<string, array{page: int|string|null, quote: string}>, vision_used: bool, schema_rejected: bool}
     */
    public function previewIntake(string $bytes, string $mimeType): array
    {
        return $this->ingest->previewIntake($bytes, $mimeType, $this->newCorrelationId());
    }

    /**
     * The human-confirmed intake save: create the patient from reviewed
     * demographics, store the source PDF, and write reviewed allergies/meds to
     * the chart lists. Returns the new pid or validation errors.
     *
     * @param array<string, string|null> $demographics field_key => reviewed value
     * @param array{allergies?: ?string, medications?: ?string} $clinical
     * @param string $userName the acting clinician's login name (for lists.user)
     *
     * @return array{pid: ?int, errors: list<string>}
     */
    public function commitReviewedIntake(
        array $demographics,
        array $clinical,
        string $pdfBytes,
        string $filename,
        string $mimeType,
        string $userName,
    ): array {
        return $this->ingest->commitReviewedIntake($demographics, $clinical, $pdfBytes, $filename, $mimeType, $userName);
    }

    public function ingestLab(int $pid, string $bytes, string $filename, string $mimeType, int $userId): IngestResult
    {
        return $this->ingest->ingestLab($pid, $bytes, $filename, $mimeType, $this->newCorrelationId(), $userId);
    }

    /**
     * Medication list (third patient-attached type): store the source, extract,
     * persist the draft to module staging. Review + lock freeze the verified
     * transcription; chart reconciliation is deliberately not performed
     * ({@see \OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionReview::lock()}).
     */
    public function ingestMedicationList(int $pid, string $bytes, string $filename, string $mimeType, int $userId): IngestResult
    {
        return $this->ingest->ingestMedicationList($pid, $bytes, $filename, $mimeType, $this->newCorrelationId(), $userId);
    }

    public function startManualLab(int $pid, int $userId): IngestResult
    {
        return $this->ingest->startManualLab($pid, $this->newCorrelationId(), $userId);
    }

    public function editField(int $extractionId, int $fieldId, ?string $value, bool $elevated): void
    {
        $this->review->editField($extractionId, $fieldId, $value, $elevated);
    }

    public function lock(int $extractionId, int $userId, ?string $collectionDate = null): void
    {
        // provider_id for committed lab results = the verifying clinician.
        $this->review->lock($extractionId, $userId, $userId, $collectionDate);
    }

    public function unlock(int $extractionId, bool $elevated): void
    {
        $this->review->unlock($extractionId, $elevated);
    }

    /**
     * The patient (pid) an extraction belongs to, or null if it does not exist.
     * The endpoint uses this to authorize access PER PATIENT — an extraction_id is
     * otherwise an IDOR handle onto another patient's staged extraction (view,
     * edit, or lock-to-chart).
     */
    public function extractionPatientId(int $extractionId): ?int
    {
        return $this->store->findHeader($extractionId)?->pid;
    }

    /**
     * Adds a hand-entered lab result row to a draft extraction (the manual
     * Labs-tab path). Refused once the extraction is locked unless the actor is
     * elevated. `vlmValue` is null, so a hand-entered row never counts toward
     * extraction accuracy — there was no model claim to measure.
     */
    public function addManualLabField(
        int $extractionId,
        string $fieldKey,
        ?string $value,
        ?string $unit,
        ?string $refRange,
        ?string $abnormal,
        bool $elevated,
    ): void {
        $header = $this->store->findHeader($extractionId);
        if ($header === null) {
            throw new \DomainException("Extraction {$extractionId} not found");
        }
        if ($header->isLocked() && !$elevated) {
            throw new \DomainException('Extraction is locked; adding a row requires elevated permission');
        }
        if ($fieldKey === '') {
            throw new \DomainException('A test name is required');
        }

        $this->store->insertField($extractionId, new ExtractedField(
            fieldKey: $fieldKey,
            vlmValue: null,
            value: $value,
            unit: $unit,
            refRange: $refRange,
            abnormalFlag: $abnormal,
        ));
    }

    /**
     * The review view model: header + fields, shaped for the Twig template
     * (including the citation page/bbox/quote each field carries).
     *
     * @return array<string, mixed>
     */
    public function reviewViewModel(int $extractionId): array
    {
        $header = $this->store->findHeader($extractionId);
        if ($header === null) {
            return ['found' => false, 'extraction_id' => $extractionId];
        }

        $fields = [];
        foreach ($this->store->listFields($extractionId) as $row) {
            $field = $row->field;
            $citation = $field->citation;
            $fields[] = [
                'id' => $row->id,
                'field_key' => $field->fieldKey,
                'vlm_value' => $field->vlmValue,
                'value' => $field->value,
                'unit' => $field->unit,
                'ref_range' => $field->refRange,
                'abnormal_flag' => $field->abnormalFlag,
                'page' => $citation?->pageOrSection,
                'quote' => $citation?->quoteOrValue,
                'bbox' => $citation?->bbox?->toArray(),
                'confidence' => $field->confidence,
                'edited_by_user' => $field->editedByUser,
                'committed' => $field->isCommitted(),
            ];
        }

        return [
            'found' => true,
            'extraction_id' => $header->id,
            'pid' => $header->pid,
            'doc_type' => $header->docType->value,
            'doc_type_label' => $header->docType->label(),
            'is_lab' => $header->docType->value === 'lab_pdf',
            // Medication lists render through the same review screen but lock
            // WITHOUT a chart write (reconciliation deferred); the template
            // uses this to swap the lock/locked wording and show the banner.
            'is_medication_list' => $header->docType->value === 'medication_list',
            'status' => $header->status->value,
            'locked' => $header->isLocked(),
            'field_accuracy' => $header->fieldAccuracy,
            'source_document_id' => $header->sourceDocumentId,
            'model' => $header->model,
            // Lab-identity guard: did the document-header patient name/DOB match
            // this chart (null for non-lab / no vision run). Drives the review
            // banner that alerts the uploader to a possible PHI mix-up.
            'identity_status' => $header->identityStatus?->value,
            'identity_detail' => $header->identityDetail,
            // Printed specimen collection date parsed at ingest (labs; null
            // when not printed / not parseable). The endpoint prefers this as
            // the draw-date prefill, falling back to today.
            'collection_date' => $header->collectionDate,
            'fields' => $fields,
        ];
    }

    private function newCorrelationId(): string
    {
        return 'ingest-' . bin2hex(random_bytes(8));
    }
}
