<?php

/**
 * The insert -> verify -> lock lifecycle: edit draft fields, then commit + lock.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

use OpenEMR\Modules\ClinicalCopilot\ReadPath\NullTraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;

/**
 * The human half of Week 2: the physician verifies the extracted values, edits
 * the model's misses, then locks. Locking is the single moment derived facts
 * become chart records — via {@see ChartWriter} — and it also crystallizes the
 * extraction-accuracy metric (how many fields the human accepted unchanged).
 * After lock the record is immutable except to elevated ACL, which unlocks,
 * edits, and re-commits (the re-commit is idempotent, so a correction appends /
 * updates lineage rather than duplicating chart rows).
 *
 * ACL enforcement lives in the endpoints; this class takes an `$elevated` flag
 * and refuses post-lock edits when it is false — a defence-in-depth check next
 * to the state transition itself, not instead of the endpoint's ACL gate.
 */
final class ExtractionReview
{
    public function __construct(
        private readonly ExtractionStore $store,
        private readonly ChartWriter $chartWriter,
        private readonly TraceRecorderInterface $tracer = new NullTraceRecorder(),
    ) {
    }

    /**
     * Applies a human edit to one field of a draft (or a locked extraction when
     * the actor is elevated). `edited_by_user` is recomputed from whether the
     * new value diverges from the model's — that is the accuracy signal.
     *
     * @throws \DomainException when the extraction is locked and the actor is not elevated
     */
    public function editField(int $extractionId, int $fieldId, ?string $value, bool $elevated): void
    {
        $header = $this->requireHeader($extractionId);
        if ($header->isLocked() && !$elevated) {
            throw new \DomainException('Extraction is locked; editing requires elevated permission');
        }

        $target = null;
        foreach ($this->store->listFields($extractionId) as $row) {
            if ($row->id === $fieldId) {
                $target = $row;
                break;
            }
        }

        if ($target === null) {
            throw new \DomainException("Field {$fieldId} does not belong to extraction {$extractionId}");
        }

        $edited = $target->field->withHumanValue($value);
        $this->store->updateFieldValue($fieldId, $edited->value, $edited->editedByUser);
    }

    /**
     * Verify & lock. Commits the verified values to the chart (intake
     * demographics / lab results), records the write-back lineage, computes and
     * stores extraction accuracy, and flips the extraction to `locked`.
     * Idempotent: a second lock of the same extraction commits nothing new.
     * For a MEDICATION LIST, lock deliberately writes NOTHING to the chart —
     * it freezes the verified transcription only; see the match arm below.
     *
     * `$parentSpanId`: null (the standalone extraction_review.php lock) keeps
     * the `chart_commit` span a ROOT span under the extraction's own
     * correlation id — unchanged behavior. When an agent-driven flow commits,
     * it passes its span id (with the extraction already carrying the
     * supervisor run's correlation id) so `chart_commit` attaches under the
     * supervisor trace tree.
     */
    public function lock(int $extractionId, int $actorUserId, int $providerId, ?string $collectionDate = null, ?string $parentSpanId = null): void
    {
        $header = $this->requireHeader($extractionId);
        if ($header->isLocked()) {
            return;
        }

        $fields = $this->store->listFields($extractionId);
        $start = new \DateTimeImmutable();
        $t0 = microtime(true);

        match ($header->docType) {
            DocType::IntakeForm => $this->commitIntake($header, $fields),
            // Fallback chain for the specimen date: the reviewer's explicit
            // form value wins; absent that, the collection date parsed off the
            // printed report at ingest (W5); ChartWriter's own last resort is
            // today. The review screen prefills the form field from the same
            // parsed date, so on the human path these usually agree.
            DocType::LabPdf => $this->commitLabs($header, $providerId, $fields, $collectionDate ?? $header->collectionDate),
            // DELIBERATE no chart write. Locking a medication list freezes the
            // human-verified transcription in module staging and records
            // extraction accuracy — nothing more. Reconciling extracted
            // medications into the chart's medication/prescription tables is a
            // clinical-safety-sensitive step (interactions, duplicates,
            // superseded orders) this module defers, matching its pattern of
            // deferring risky writes until a dedicated, human-gated flow
            // exists. Fields therefore get NO lineage and stay uncommitted —
            // honestly reflecting that they are not in the chart.
            DocType::MedicationList => null,
        };

        $this->store->markLocked($extractionId, $actorUserId, $this->accuracy($fields));

        $this->tracer->record(new TraceSpan(
            $header->correlationId,
            TraceSpan::newSpanId(),
            $parentSpanId,
            'chart_commit',
            $start,
            (int)round((microtime(true) - $t0) * 1000),
            'ok',
            $header->pid,
            $actorUserId,
        ));
    }

    /**
     * Elevated-only: reopen a locked extraction for correction.
     *
     * @throws \DomainException when the actor is not elevated
     */
    public function unlock(int $extractionId, bool $elevated): void
    {
        if (!$elevated) {
            throw new \DomainException('Unlocking a committed extraction requires elevated permission');
        }

        $this->requireHeader($extractionId);
        $this->store->markDraft($extractionId);
    }

    /**
     * @param list<ExtractedFieldRow> $fields
     */
    private function commitIntake(ExtractionRow $header, array $fields): void
    {
        $changed = [];
        foreach ($fields as $row) {
            // Only demographic corrections round-trip to patient_data on lock;
            // the patient row itself was created at upload from the model's
            // best guess. Non-demographic intake facts stay in staging.
            if ($row->field->editedByUser) {
                $changed[$row->field->fieldKey] = $row->field->value;
            }
        }

        if ($changed !== []) {
            $this->chartWriter->updatePatientDemographics($header->pid, $changed);
        }

        foreach ($fields as $row) {
            if (!$row->field->isCommitted()) {
                $this->store->setFieldLineage($row->id, 'patient_data', $header->pid);
            }
        }
    }

    /**
     * @param list<ExtractedFieldRow> $fields
     */
    private function commitLabs(ExtractionRow $header, int $providerId, array $fields, ?string $collectionDate = null): void
    {
        $committed = $this->chartWriter->commitLabResults(
            $header->pid,
            $header->sourceDocumentId,
            $providerId,
            $fields,
            $collectionDate,
        );

        foreach ($committed as $fieldId => $resultId) {
            $this->store->setFieldLineage($fieldId, 'procedure_result', $resultId);
        }
    }

    /**
     * Extraction accuracy = accepted-unchanged / model-proposed fields. Null
     * when the model proposed nothing (pure manual entry). PHI-free — a rate.
     *
     * @param list<ExtractedFieldRow> $fields
     */
    private function accuracy(array $fields): ?float
    {
        $proposed = 0;
        $accepted = 0;
        foreach ($fields as $row) {
            if ($row->field->vlmValue === null) {
                continue;
            }
            $proposed++;
            if (!$row->field->editedByUser) {
                $accepted++;
            }
        }

        return $proposed === 0 ? null : $accepted / $proposed;
    }

    private function requireHeader(int $extractionId): ExtractionRow
    {
        $header = $this->store->findHeader($extractionId);
        if ($header === null) {
            throw new \DomainException("Extraction {$extractionId} not found");
        }

        return $header;
    }
}
