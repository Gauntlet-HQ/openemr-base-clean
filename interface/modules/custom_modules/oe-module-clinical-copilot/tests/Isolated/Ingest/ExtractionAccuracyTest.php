<?php

/**
 * The observability insight: the human's edits ARE the extraction-accuracy metric.
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
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractedField;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ParsedExtraction;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: reporting extraction accuracy as 100% because edits
 * were not detected, or penalizing accuracy for whitespace-only "edits" and for
 * hand-entered fields the model never proposed.
 */
final class ExtractionAccuracyTest extends TestCase
{
    public function testAcceptingTheModelValueLeavesFieldUnedited(): void
    {
        $field = (new ExtractedField('a1c', '7.2', '7.2'))->withHumanValue('7.2');
        self::assertFalse($field->editedByUser);
    }

    public function testChangingTheModelValueMarksFieldEdited(): void
    {
        $field = (new ExtractedField('a1c', '7.2', '7.2'))->withHumanValue('8.1');
        self::assertTrue($field->editedByUser);
        self::assertSame('8.1', $field->value);
    }

    public function testWhitespaceOnlyDifferenceIsNotAnEdit(): void
    {
        $field = (new ExtractedField('a1c', '7.2', '7.2'))->withHumanValue('  7.2 ');
        self::assertFalse($field->editedByUser, 'trimming is not a model miss');
        self::assertSame('7.2', $field->value);
    }

    public function testFieldAccuracyCountsOnlyModelProposedFields(): void
    {
        $extraction = new ParsedExtraction(DocType::LabPdf, [
            (new ExtractedField('a1c', '7.2', '7.2'))->withHumanValue('7.2'),      // accepted
            (new ExtractedField('ldl', '100', '100'))->withHumanValue('102'),      // edited (miss)
            (new ExtractedField('hdl', '55', '55'))->withHumanValue('55'),         // accepted
            new ExtractedField('manual_note', null, 'added by hand'),              // no model claim
        ]);

        // 2 accepted of 3 proposed; the hand-entered field is excluded.
        self::assertEqualsWithDelta(2 / 3, $extraction->fieldAccuracy(), 0.0001);
        self::assertCount(1, $extraction->editedFields());
    }

    public function testFullyManualEntryHasNoAccuracyToReport(): void
    {
        $extraction = new ParsedExtraction(DocType::LabPdf, [
            new ExtractedField('glucose', null, '95'),
        ]);

        self::assertNull($extraction->fieldAccuracy());
    }
}
