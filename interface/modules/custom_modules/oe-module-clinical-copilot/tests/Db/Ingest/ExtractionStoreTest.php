<?php

/**
 * DB-backed evals: the extraction staging store (draft persist, edit, lock, lineage).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Ingest;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Ingest\BoundingBox;
use OpenEMR\Modules\ClinicalCopilot\Ingest\DocType;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractedField;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionStatus;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionStore;
use OpenEMR\Modules\ClinicalCopilot\Ingest\SourceCitation;
use OpenEMR\Modules\ClinicalCopilot\Ingest\SourceType;
use PHPUnit\Framework\TestCase;

/**
 * `mod_copilot_extraction` / `mod_copilot_extracted_fact` carry no FK against
 * core tables (their `pid`/`source_document_id` are read-only references by
 * convention), so these evals use a synthetic pid and no seed. Each test wraps
 * in a transaction rolled back on teardown — nothing persists.
 *
 * Failure mode guarded: a draft that can't be read back verbatim, an edit that
 * doesn't record the accuracy signal, a lock that doesn't freeze state or store
 * accuracy, or lineage that doesn't survive a round-trip.
 */
final class ExtractionStoreTest extends TestCase
{
    private const SYNTHETIC_PID = 999042;

    private ExtractionStore $store;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->store = new ExtractionStore();
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    private function newDraft(DocType $docType = DocType::LabPdf): int
    {
        return $this->store->insertHeader(
            self::SYNTHETIC_PID,
            $docType,
            null,
            'corr-test-1',
            'gemini-2.5-pro',
            'ingest-extract-v1',
            850,
            1200,
            40,
            null,
            777,
        );
    }

    public function testInsertAndReadBackHeaderAndFields(): void
    {
        $id = $this->newDraft();
        $this->store->insertField($id, new ExtractedField(
            fieldKey: 'Hemoglobin A1c',
            vlmValue: '7.8',
            value: '7.8',
            unit: '%',
            refRange: '4.0-5.6',
            abnormalFlag: 'H',
            citation: new SourceCitation(SourceType::Document, 'extraction:' . $id, 1, 'Hemoglobin A1c', 'A1c 7.8 %', new BoundingBox(60, 300, 520, 330)),
            confidence: 0.94,
        ));

        $header = $this->store->findHeader($id);
        self::assertNotNull($header);
        self::assertSame(self::SYNTHETIC_PID, $header->pid);
        self::assertSame(DocType::LabPdf, $header->docType);
        self::assertSame(ExtractionStatus::Draft, $header->status);

        $fields = $this->store->listFields($id);
        self::assertCount(1, $fields);
        $field = $fields[0]->field;
        self::assertSame('Hemoglobin A1c', $field->fieldKey);
        self::assertSame('7.8', $field->vlmValue);
        self::assertSame('%', $field->unit);
        self::assertNotNull($field->citation);
        self::assertSame(1, $field->citation->pageOrSection);
        self::assertNotNull($field->citation->bbox);
        self::assertSame([60, 300, 520, 330], $field->citation->bbox->toArray());
        self::assertFalse($field->editedByUser);
    }

    public function testEditRecordsTheAccuracySignal(): void
    {
        $id = $this->newDraft();
        $fieldId = $this->store->insertField($id, new ExtractedField('LDL', '100', '100'));

        // Human corrects the model's value.
        $this->store->updateFieldValue($fieldId, '108', true);

        $field = $this->store->listFields($id)[0]->field;
        self::assertSame('108', $field->value);
        self::assertSame('100', $field->vlmValue, 'the model value is preserved as ground truth');
        self::assertTrue($field->editedByUser);
    }

    public function testMarkLockedFreezesStateAndStoresAccuracy(): void
    {
        $id = $this->newDraft();
        $this->store->insertField($id, new ExtractedField('A1c', '7.8', '7.8'));

        $this->store->markLocked($id, 777, 0.8333);

        $header = $this->store->findHeader($id);
        self::assertNotNull($header);
        self::assertSame(ExtractionStatus::Locked, $header->status);
        self::assertSame(777, $header->lockedBy);
        self::assertNotNull($header->fieldAccuracy);
        self::assertEqualsWithDelta(0.8333, $header->fieldAccuracy, 0.0001);
    }

    public function testMarkLockedIsGuardedToDraftOnly(): void
    {
        $id = $this->newDraft();
        $this->store->markLocked($id, 777, 1.0);
        // A second lock (WHERE status='draft') must not overwrite locked_by.
        $this->store->markLocked($id, 999, 0.5);

        $header = $this->store->findHeader($id);
        self::assertNotNull($header);
        self::assertSame(777, $header->lockedBy, 'the guarded update does not re-lock an already-locked row');
    }

    public function testSetFieldLineageRoundTrips(): void
    {
        $id = $this->newDraft();
        $fieldId = $this->store->insertField($id, new ExtractedField('A1c', '7.8', '7.8'));

        $this->store->setFieldLineage($fieldId, 'procedure_result', 55123);

        $field = $this->store->listFields($id)[0]->field;
        self::assertTrue($field->isCommitted());
        self::assertSame('procedure_result', $field->committedCoreTable);
        self::assertSame(55123, $field->committedCorePk);
    }

    public function testUniqueKeyMakesFieldInsertIdempotentPerExtraction(): void
    {
        $id = $this->newDraft();
        $this->store->insertField($id, new ExtractedField('A1c', '7.8', '7.8'));

        // Same (extraction_id, field_key) violates the UNIQUE index.
        $this->expectException(\Throwable::class);
        $this->store->insertField($id, new ExtractedField('A1c', '9.9', '9.9'));
    }
}
