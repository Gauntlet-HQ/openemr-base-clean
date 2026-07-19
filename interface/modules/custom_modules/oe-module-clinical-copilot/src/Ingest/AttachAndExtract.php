<?php

/**
 * Orchestrates one upload: store the source, extract, persist a draft, (intake) create the patient.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\NullTraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;

/**
 * The Week 2 `attach_and_extract` flow. It never commits derived facts to the
 * chart itself — labs reach the chart only at the human lock step
 * ({@see ExtractionReview} via {@see ChartWriter}), and intake creates the
 * patient only at the human-confirmed save ({@see commitReviewedIntake}); nothing
 * is written at upload.
 *
 * Everything degrades: no model configured ({@see LlmUnavailableException}) or
 * model output that fails the strict schema ({@see SchemaValidationException})
 * both fall back to a blank draft/form the reviewer hand-fills — the flow never
 * dead-ends on a missing or misbehaving model. The correlation id threads through
 * the ingest span and the vision_extract child span, so the extraction is one
 * reconstructable trace.
 *
 * Span parentage: {@see previewIntake} / {@see ingestLab} accept an OPTIONAL
 * `$parentSpanId`. Standalone callers (lab_upload.php / intake_upload.php via
 * {@see \OpenEMR\Modules\ClinicalCopilot\Controller\IngestController}) omit it,
 * so their `ingest`/`preview` spans stay ROOT spans under their own
 * `ingest-...` correlation id — unchanged behavior. When the agent path drives
 * ingestion it passes its worker span id (and the supervisor run's correlation
 * id), attaching the whole ingest subtree under the supervisor trace:
 * `supervisor -> worker -> ingest/preview -> vision_extract`.
 */
final class AttachAndExtract
{
    public function __construct(
        private readonly ExtractionClient $extractionClient,
        private readonly ExtractionStore $store,
        private readonly ChartWriter $chartWriter,
        private readonly int $documentCategoryId,
        private readonly TraceRecorderInterface $tracer = new NullTraceRecorder(),
    ) {
    }

    /**
     * Deferred-save intake preview: extract the intake fields but persist NOTHING
     * and create NO patient. Degrades to an empty map (a blank form the reviewer
     * hand-fills) when the model is unavailable or its output fails the schema —
     * so this can never crash the way the old create-at-upload path did. The
     * human confirms on the review screen; only then does {@see commitReviewedIntake}
     * write anything.
     *
     * `citations` carries the OPTIONAL per-field source citations (page/quote)
     * the model volunteered — the review screen shows them beside the prefilled
     * fields as advisory verification aids. The map is sparse and often empty:
     * intake citations are never required, so an extraction with none is still
     * fully successful.
     *
     * `$parentSpanId`: null (the standalone upload endpoints) keeps the
     * `preview` span a root; the agent path passes its worker span id so the
     * preview subtree attaches under the supervisor trace.
     *
     * @return array{fields: array<string, string|null>, citations: array<string, array{page: int|string|null, quote: string}>, vision_used: bool, schema_rejected: bool}
     */
    public function previewIntake(string $bytes, string $mimeType, string $correlationId, ?string $parentSpanId = null): array
    {
        $span = $this->openSpan($correlationId, $parentSpanId, 'preview', 0);
        [$outcome, $visionUsed, $schemaRejected] = $this->tryExtract(
            DocType::IntakeForm,
            $bytes,
            $mimeType,
            $correlationId,
            $span,
            0,
        );
        $this->tracer->record($span);

        return [
            'fields' => $this->demographicsFrom($outcome),
            'citations' => $this->citationsFrom($outcome),
            'vision_used' => $visionUsed,
            'schema_rejected' => $schemaRejected,
        ];
    }

    /**
     * The human-confirmed save (deferred persistence): create the patient from
     * the REVIEWED demographics, store the source PDF against the new chart, and
     * write the reviewed allergies/medications to the chart lists — all here, in
     * the one save the user triggered, nothing before it. Returns the new pid, or
     * validation errors so the endpoint re-renders the form instead of crashing.
     *
     * @param array<string, string|null> $demographics field_key => reviewed value
     * @param array{allergies?: ?string, medications?: ?string} $clinical
     * @param string $userName the acting clinician's LOGIN NAME (for lists.user)
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
        $create = $this->chartWriter->tryCreatePatient($demographics);
        if ($create['pid'] === null) {
            return ['pid' => null, 'errors' => $create['errors']];
        }
        $pid = $create['pid'];

        // The patient now EXISTS. The PDF store and the allergy/medication list
        // writes are best-effort from here: a failure must NOT report a total
        // failure (which would prompt the reviewer to re-save and create a
        // duplicate patient). Log any shortfall and land them on the chart.
        $logger = new SystemLogger();
        try {
            if ($pdfBytes !== '') {
                $documentId = $this->chartWriter->storeSourceDocument($pid, $this->documentCategoryId, $filename, $mimeType, $pdfBytes, null);
                if ($documentId === null) {
                    $logger->warning('ClinicalCopilot: intake source PDF could not be stored', ['pid' => $pid]);
                }
            }
            $this->chartWriter->addChartListLines($pid, 'allergy', $clinical['allergies'] ?? null, $userName);
            $this->chartWriter->addChartListLines($pid, 'medication', $clinical['medications'] ?? null, $userName);
        } catch (\Throwable $e) {
            $logger->error('ClinicalCopilot: intake post-create writes partially failed', ['pid' => $pid, 'exception' => $e]);
        }

        return ['pid' => $pid, 'errors' => []];
    }

    /**
     * Labs: existing patient. Store the source, extract, persist the draft. No
     * chart write here — results reach `procedure_result` only on lock.
     *
     * `$parentSpanId`: null (the standalone lab_upload.php path) keeps the
     * `ingest` span a root; the agent path passes its worker span id so the
     * ingest subtree attaches under the supervisor trace.
     */
    public function ingestLab(
        int $pid,
        string $bytes,
        string $filename,
        string $mimeType,
        string $correlationId,
        int $userId,
        ?string $parentSpanId = null,
    ): IngestResult {
        $span = $this->openSpan($correlationId, $parentSpanId, 'ingest', $pid);
        [$outcome, $visionUsed, $schemaRejected] = $this->tryExtract(
            DocType::LabPdf,
            $bytes,
            $mimeType,
            $correlationId,
            $span,
            $pid,
        );

        $extractionId = $this->persistDraft($pid, DocType::LabPdf, $outcome, $visionUsed, $correlationId, $userId);
        $this->storeSource($pid, $filename, $mimeType, $bytes, $extractionId);

        // PHI-mixing guard: a lab report is uploaded ONTO a chart, but the file
        // itself names a patient. Match the document-header name/DOB to the chart
        // and record the verdict so the review screen can alert the uploader if
        // they disagree. Only when vision actually ran (a blank/degraded draft has
        // no identity to check); never fatal — a failure here must not block the
        // upload the reviewer still needs to verify by eye.
        if ($visionUsed) {
            $this->recordLabIdentity($pid, $extractionId, $outcome->extraction);
        }

        $this->tracer->record($span);

        return new IngestResult($pid, $extractionId, DocType::LabPdf, $visionUsed, $schemaRejected);
    }

    /**
     * Medication list: existing patient, the third patient-attached document
     * type. Mirrors {@see ingestLab} — store the source, extract, persist the
     * draft into module STAGING (never a core table) — because, like a lab and
     * unlike intake, the chart already exists to attach the source document to,
     * and a persisted draft lets the reviewer's verification survive the
     * session and render through the generic review screen. The deliberate
     * difference is at lock: {@see ExtractionReview::lock()} writes NOTHING to
     * the chart for this type — medication chart reconciliation is a
     * clinical-safety-sensitive step this module defers, exactly as intake
     * once deferred create-at-upload. No identity guard either: the medication
     * schema carries no patient_name/patient_dob header (a future step,
     * alongside reconciliation).
     *
     * `$parentSpanId`: null (the standalone medication_upload.php path) keeps
     * the `ingest` span a root; an agent-driven path may pass its worker span
     * id, as with labs.
     */
    public function ingestMedicationList(
        int $pid,
        string $bytes,
        string $filename,
        string $mimeType,
        string $correlationId,
        int $userId,
        ?string $parentSpanId = null,
    ): IngestResult {
        $span = $this->openSpan($correlationId, $parentSpanId, 'ingest', $pid);
        [$outcome, $visionUsed, $schemaRejected] = $this->tryExtract(
            DocType::MedicationList,
            $bytes,
            $mimeType,
            $correlationId,
            $span,
            $pid,
        );

        $extractionId = $this->persistDraft($pid, DocType::MedicationList, $outcome, $visionUsed, $correlationId, $userId);
        $this->storeSource($pid, $filename, $mimeType, $bytes, $extractionId);
        $this->tracer->record($span);

        return new IngestResult($pid, $extractionId, DocType::MedicationList, $visionUsed, $schemaRejected);
    }

    /**
     * Manual-entry start: an empty draft for a lab tab with no PDF. The
     * physician adds result rows in the review UI, then locks.
     */
    public function startManualLab(int $pid, string $correlationId, int $userId): IngestResult
    {
        $blank = ExtractionSchema::blankExtraction(DocType::LabPdf);
        $extractionId = $this->persistDraft($pid, DocType::LabPdf, $this->outcome($blank, null), false, $correlationId, $userId);

        return new IngestResult($pid, $extractionId, DocType::LabPdf, false, false);
    }

    /**
     * @return array{0: ExtractionOutcome, 1: bool, 2: bool} [outcome, visionUsed, schemaRejected]
     */
    private function tryExtract(
        DocType $docType,
        string $bytes,
        string $mimeType,
        string $correlationId,
        TraceSpan $parent,
        int $pid,
    ): array {
        $childStart = new \DateTimeImmutable();
        $t0 = microtime(true);
        try {
            $outcome = $this->extractionClient->extract($docType, $bytes, $mimeType, 'upload');
            $this->recordChild($parent, $correlationId, 'vision_extract', $childStart, $t0, 'ok', $pid, $outcome);

            return [$outcome, true, false];
        } catch (LlmUnavailableException $e) {
            // Log the real reason: "unavailable" here covers both "no client
            // configured" AND a provider/transport failure on the (multimodal)
            // call — without this the intake form just blanks with no clue why.
            (new SystemLogger())->warning('ClinicalCopilot: vision extraction unavailable', [
                'doc_type' => $docType->value,
                'exception' => $e,
            ]);
            $this->recordChild($parent, $correlationId, 'vision_extract', $childStart, $t0, 'degraded', $pid, null);

            return [$this->outcome(ExtractionSchema::blankExtraction($docType), null), false, false];
        } catch (SchemaValidationException $e) {
            (new SystemLogger())->warning('ClinicalCopilot: vision extraction rejected by schema', [
                'doc_type' => $docType->value,
                'exception' => $e,
            ]);
            $this->recordChild($parent, $correlationId, 'vision_extract', $childStart, $t0, 'error', $pid, null);

            return [$this->outcome(ExtractionSchema::blankExtraction($docType), null), false, true];
        }
    }

    private function persistDraft(
        int $pid,
        DocType $docType,
        ExtractionOutcome $outcome,
        bool $visionUsed,
        string $correlationId,
        int $userId,
    ): int {
        $extractionId = $this->store->insertHeader(
            pid: $pid,
            docType: $docType,
            sourceDocumentId: null,
            correlationId: $correlationId,
            model: $visionUsed ? $outcome->modelVersion : null,
            promptVersion: $visionUsed ? $outcome->promptVersion : null,
            latencyMs: $visionUsed ? $outcome->latencyMs : null,
            tokensIn: $visionUsed ? $outcome->tokensIn : null,
            tokensOut: $visionUsed ? $outcome->tokensOut : null,
            costUsd: null,
            createdBy: $userId,
            // Parsed printed specimen date (labs; null when absent/garbage) —
            // persisted on the header so the review screen can prefill the
            // draw-date field with it instead of today (W5).
            collectionDate: $outcome->extraction->collectionDate,
        );

        foreach ($outcome->extraction->fields as $field) {
            $this->store->insertField($extractionId, $field);
        }

        return $extractionId;
    }

    /**
     * Computes and persists the lab-identity guard verdict for one extraction.
     * Best-effort: any failure is logged and swallowed so a hiccup reading the
     * chart never blocks an upload the reviewer must still verify by hand.
     */
    private function recordLabIdentity(int $pid, int $extractionId, ParsedExtraction $extraction): void
    {
        try {
            $chart = $this->chartWriter->fetchPatientIdentity($pid) ?? ['first' => null, 'last' => null, 'dob' => null];
            $match = LabIdentityMatcher::compare(
                $chart['first'],
                $chart['last'],
                $chart['dob'],
                $extraction->patientName,
                $extraction->patientDob,
            );
            $this->store->setIdentity($extractionId, $match->status, $match->detail());
        } catch (\Throwable $e) {
            (new SystemLogger())->error('ClinicalCopilot: lab identity check failed', [
                'extraction_id' => $extractionId,
                'exception' => $e,
            ]);
        }
    }

    private function storeSource(int $pid, string $filename, string $mimeType, string $bytes, int $extractionId): void
    {
        if ($bytes === '') {
            return;
        }

        $documentId = $this->chartWriter->storeSourceDocument(
            $pid,
            $this->documentCategoryId,
            $filename,
            $mimeType,
            $bytes,
            $extractionId,
        );

        if ($documentId !== null) {
            $this->store->setSourceDocument($extractionId, $documentId);
        }
    }

    /**
     * The sparse map of OPTIONAL intake citations: only fields where the model
     * volunteered a page and/or quote appear at all — fields without one are
     * simply absent, never an error (blank extractions have no citations, and
     * that is the normal case, not a degraded one).
     *
     * @return array<string, array{page: int|string|null, quote: string}> field_key => citation
     */
    private function citationsFrom(ExtractionOutcome $outcome): array
    {
        $out = [];
        foreach ($outcome->extraction->fields as $field) {
            if ($field->citation !== null) {
                $out[$field->fieldKey] = [
                    'page' => $field->citation->pageOrSection,
                    'quote' => $field->citation->quoteOrValue,
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string, string|null> field_key => verified value
     */
    private function demographicsFrom(ExtractionOutcome $outcome): array
    {
        $out = [];
        foreach ($outcome->extraction->fields as $field) {
            $out[$field->fieldKey] = $field->value;
        }

        return $out;
    }

    private function outcome(ParsedExtraction $extraction, ?string $model): ExtractionOutcome
    {
        return new ExtractionOutcome($extraction, $model ?? '', '', 0, 0, 0);
    }

    private function openSpan(string $correlationId, ?string $parentSpanId, string $kind, int $pid): TraceSpan
    {
        return new TraceSpan(
            $correlationId,
            TraceSpan::newSpanId(),
            $parentSpanId,
            $kind,
            new \DateTimeImmutable(),
            null,
            'ok',
            $pid,
        );
    }

    private function recordChild(
        TraceSpan $parent,
        string $correlationId,
        string $kind,
        \DateTimeImmutable $start,
        float $t0,
        string $status,
        int $pid,
        ?ExtractionOutcome $outcome,
    ): void {
        $durationMs = (int)round((microtime(true) - $t0) * 1000);
        $this->tracer->record(new TraceSpan(
            $correlationId,
            TraceSpan::newSpanId(),
            $parent->spanId,
            $kind,
            $start,
            $durationMs,
            $status,
            $pid,
            null,
            null,
            null,
            $outcome?->modelVersion,
            $outcome?->tokensIn,
            $outcome?->tokensOut,
        ));
    }
}
