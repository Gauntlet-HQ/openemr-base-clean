<?php

/**
 * Repository over the two Week 2 module-owned tables (extraction + extracted_fact).
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

/**
 * The ONLY class that writes `mod_copilot_extraction` / `mod_copilot_extracted_fact`
 * (whitelisted in {@see \OpenEMR\Modules\ClinicalCopilot\Tests\PHPStan\Rules\ForbiddenWriteOutsideRepositoriesRule}).
 * These are module-owned staging tables, editable while the extraction is in
 * `draft`; unlike the core chart, in-place UPDATE of a draft field is fine —
 * that is the review window. The core commit is a SEPARATE, sanctioned write
 * ({@see ChartWriter}); this repository never touches a core table.
 */
final class ExtractionStore
{
    /**
     * @return int the new mod_copilot_extraction.id
     */
    public function insertHeader(
        int $pid,
        DocType $docType,
        ?int $sourceDocumentId,
        string $correlationId,
        ?string $model,
        ?string $promptVersion,
        ?int $latencyMs,
        ?int $tokensIn,
        ?int $tokensOut,
        ?float $costUsd,
        ?int $createdBy,
        ?string $collectionDate = null,
    ): int {
        return QueryUtils::sqlInsert(
            'INSERT INTO `mod_copilot_extraction`
                (`pid`, `doc_type`, `source_document_id`, `status`, `model`, `prompt_version`,
                 `correlation_id`, `latency_ms`, `tokens_in`, `tokens_out`, `cost_usd`, `created_by`,
                 `collection_date`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $pid,
                $docType->value,
                $sourceDocumentId,
                ExtractionStatus::Draft->value,
                $model,
                $promptVersion,
                $correlationId,
                $latencyMs,
                $tokensIn,
                $tokensOut,
                $costUsd,
                $createdBy,
                $collectionDate,
            ],
        );
    }

    /**
     * @return int the new mod_copilot_extracted_fact.id
     */
    public function insertField(int $extractionId, ExtractedField $field): int
    {
        $citation = $field->citation;

        return QueryUtils::sqlInsert(
            'INSERT INTO `mod_copilot_extracted_fact`
                (`extraction_id`, `field_key`, `vlm_value`, `value`, `unit`, `ref_range`,
                 `abnormal_flag`, `page`, `bbox_json`, `quote`, `confidence`, `edited_by_user`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $extractionId,
                $field->fieldKey,
                $field->vlmValue,
                $field->value,
                $field->unit,
                $field->refRange,
                $field->abnormalFlag,
                $citation?->pageOrSection,
                $citation?->bbox?->toJson(),
                $citation?->quoteOrValue,
                $field->confidence,
                $field->editedByUser ? 1 : 0,
            ],
        );
    }

    /**
     * Applies a human edit to one draft field. `edited_by_user` is set from
     * whether the value actually diverged from the model's — the accuracy
     * signal. No-op guard against locked extractions is the caller's job
     * ({@see ExtractionReview}).
     */
    public function updateFieldValue(int $fieldId, ?string $value, bool $editedByUser): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `mod_copilot_extracted_fact` SET `value` = ?, `edited_by_user` = ? WHERE `id` = ?',
            [$value, $editedByUser ? 1 : 0, $fieldId],
        );
    }

    /**
     * Records the write-back lineage after {@see ChartWriter} commits a field
     * to a core row — the traceable link from staging fact to chart record.
     */
    public function setFieldLineage(int $fieldId, string $coreTable, int $corePk): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `mod_copilot_extracted_fact` SET `committed_core_table` = ?, `committed_core_pk` = ? WHERE `id` = ?',
            [$coreTable, $corePk, $fieldId],
        );
    }

    /**
     * Flips the extraction to `locked`, stamps who/when, and records the
     * computed extraction-accuracy rate (PHI-free).
     */
    public function markLocked(int $extractionId, int $lockedBy, ?float $fieldAccuracy): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `mod_copilot_extraction`
                SET `status` = ?, `locked_by` = ?, `locked_at` = NOW(), `field_accuracy` = ?
             WHERE `id` = ? AND `status` = ?',
            [
                ExtractionStatus::Locked->value,
                $lockedBy,
                $fieldAccuracy,
                $extractionId,
                ExtractionStatus::Draft->value,
            ],
        );
    }

    /**
     * Elevated-only: reopen a locked extraction for correction. The caller
     * ({@see ExtractionReview}) enforces the ACL; this only moves the state.
     */
    public function markDraft(int $extractionId): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `mod_copilot_extraction` SET `status` = ?, `locked_by` = NULL, `locked_at` = NULL WHERE `id` = ?',
            [ExtractionStatus::Draft->value, $extractionId],
        );
    }

    /**
     * Records the lab-identity guard verdict on the header: did the document's
     * printed patient name/DOB match the chart it was uploaded onto. Written once
     * at ingest (labs only); read back to render the review-screen banner. The
     * detail carries PHI and stays in the module MySQL protection domain (T16).
     */
    public function setIdentity(int $extractionId, LabIdentityStatus $status, ?string $detail): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `mod_copilot_extraction` SET `identity_status` = ?, `identity_detail` = ? WHERE `id` = ?',
            [$status->value, $detail, $extractionId],
        );
    }

    /**
     * Links the stored source document to the extraction after the file is
     * saved (the extraction id must exist first, so the documents row can
     * foreign-reference it — hence this second step).
     */
    public function setSourceDocument(int $extractionId, int $documentId): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `mod_copilot_extraction` SET `source_document_id` = ? WHERE `id` = ?',
            [$documentId, $extractionId],
        );
    }

    public function findHeader(int $extractionId): ?ExtractionRow
    {
        $row = QueryUtils::querySingleRow(
            'SELECT * FROM `mod_copilot_extraction` WHERE `id` = ?',
            [$extractionId],
        );

        return is_array($row) ? self::hydrateHeader($row) : null;
    }

    /**
     * @return list<ExtractedFieldRow>
     */
    public function listFields(int $extractionId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM `mod_copilot_extracted_fact` WHERE `extraction_id` = ? ORDER BY `id` ASC',
            [$extractionId],
        );

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[] = self::hydrateField($row);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateHeader(array $row): ExtractionRow
    {
        return new ExtractionRow(
            id: (int)$row['id'],
            pid: (int)$row['pid'],
            docType: DocType::from((string)$row['doc_type']),
            sourceDocumentId: $row['source_document_id'] !== null ? (int)$row['source_document_id'] : null,
            status: ExtractionStatus::from((string)$row['status']),
            model: $row['model'] !== null ? (string)$row['model'] : null,
            correlationId: (string)$row['correlation_id'],
            fieldAccuracy: $row['field_accuracy'] !== null ? (float)$row['field_accuracy'] : null,
            createdBy: $row['created_by'] !== null ? (int)$row['created_by'] : null,
            lockedBy: $row['locked_by'] !== null ? (int)$row['locked_by'] : null,
            // `?? null` tolerates a DB installed before the identity columns
            // existed (the #IfMissingColumn upgrade may not have run yet).
            identityStatus: LabIdentityStatus::tryFromString(
                isset($row['identity_status']) && $row['identity_status'] !== null ? (string)$row['identity_status'] : null,
            ),
            identityDetail: isset($row['identity_detail']) && $row['identity_detail'] !== null ? (string)$row['identity_detail'] : null,
            collectionDate: isset($row['collection_date']) && $row['collection_date'] !== null ? (string)$row['collection_date'] : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateField(array $row): ExtractedFieldRow
    {
        $page = $row['page'] !== null ? (int)$row['page'] : null;
        $quote = $row['quote'] !== null ? (string)$row['quote'] : null;
        $bbox = BoundingBox::fromJson($row['bbox_json'] !== null ? (string)$row['bbox_json'] : null);

        $citation = ($quote !== null && $quote !== '')
            ? new SourceCitation(
                SourceType::Document,
                'extraction:' . (int)$row['extraction_id'],
                $page,
                (string)$row['field_key'],
                $quote,
                $bbox,
            )
            : null;

        $field = new ExtractedField(
            fieldKey: (string)$row['field_key'],
            vlmValue: $row['vlm_value'] !== null ? (string)$row['vlm_value'] : null,
            value: $row['value'] !== null ? (string)$row['value'] : null,
            unit: $row['unit'] !== null ? (string)$row['unit'] : null,
            refRange: $row['ref_range'] !== null ? (string)$row['ref_range'] : null,
            abnormalFlag: $row['abnormal_flag'] !== null ? (string)$row['abnormal_flag'] : null,
            citation: $citation,
            confidence: $row['confidence'] !== null ? (float)$row['confidence'] : null,
            editedByUser: (int)$row['edited_by_user'] === 1,
            committedCoreTable: $row['committed_core_table'] !== null ? (string)$row['committed_core_table'] : null,
            committedCorePk: $row['committed_core_pk'] !== null ? (int)$row['committed_core_pk'] : null,
        );

        return new ExtractedFieldRow((int)$row['id'], (int)$row['extraction_id'], $field);
    }
}
