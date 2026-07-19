<?php

/**
 * The Week 2 document-native citation contract: round-trip and invariants.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Ingest;

use OpenEMR\Modules\ClinicalCopilot\Ingest\BoundingBox;
use OpenEMR\Modules\ClinicalCopilot\Ingest\SourceCitation;
use OpenEMR\Modules\ClinicalCopilot\Ingest\SourceType;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a "click-to-source" citation that cannot be
 * reconstructed from its stored form (page/bbox/quote lost or corrupted), or a
 * citation with no evidence at all (empty quote / source) sneaking past — which
 * is exactly the "uncited extracted fact" the spec forbids.
 */
final class SourceCitationTest extends TestCase
{
    public function testRoundTripPreservesEveryField(): void
    {
        $citation = new SourceCitation(
            SourceType::Document,
            'extraction:42',
            2,
            'a1c',
            '7.2 %',
            new BoundingBox(100, 200, 400, 260),
        );

        $restored = SourceCitation::fromArray($citation->toArray());

        self::assertSame(SourceType::Document, $restored->sourceType);
        self::assertSame('extraction:42', $restored->sourceId);
        self::assertSame(2, $restored->pageOrSection);
        self::assertSame('a1c', $restored->fieldOrChunkId);
        self::assertSame('7.2 %', $restored->quoteOrValue);
        self::assertNotNull($restored->bbox);
        self::assertSame([100, 200, 400, 260], $restored->bbox->toArray());
    }

    /**
     * Failure mode guarded: a guideline citation losing its section/url
     * provenance on serialization — `page_or_section` must carry a section
     * LABEL (string) for guideline sources just as it carries a page NUMBER
     * (int) for documents, and `url` must survive the round trip.
     */
    public function testGuidelineRoundTripPreservesSectionLabelAndUrl(): void
    {
        $citation = new SourceCitation(
            SourceType::Guideline,
            'ADA (summary)',
            'Glycemic Targets',
            'ada-a1c-target',
            'For most non-pregnant adults an A1c below 7% is reasonable.',
            null,
            'https://diabetes.org/standards',
        );

        $restored = SourceCitation::fromArray($citation->toArray());

        self::assertSame(SourceType::Guideline, $restored->sourceType);
        self::assertSame('ADA (summary)', $restored->sourceId);
        self::assertSame('Glycemic Targets', $restored->pageOrSection);
        self::assertSame('ada-a1c-target', $restored->fieldOrChunkId);
        self::assertSame('https://diabetes.org/standards', $restored->url);
        self::assertNull($restored->bbox);
    }

    public function testEmptyQuoteIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        new SourceCitation(SourceType::Document, 'extraction:1', 1, 'a1c', '');
    }

    public function testEmptySourceIdIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        new SourceCitation(SourceType::Document, '', 1, 'a1c', '7.2');
    }

    public function testUnrecognizedSourceTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SourceCitation::fromArray([
            'source_type' => 'made_up',
            'source_id' => 'extraction:1',
            'page_or_section' => 1,
            'field_or_chunk_id' => 'a1c',
            'quote_or_value' => '7.2',
        ]);
    }

    public function testBoundingBoxRejectsOutOfRangeCoordinate(): void
    {
        $this->expectException(\DomainException::class);
        new BoundingBox(0, 0, 1200, 100);
    }

    public function testBoundingBoxRejectsInvertedCorners(): void
    {
        $this->expectException(\DomainException::class);
        new BoundingBox(400, 100, 100, 400);
    }

    public function testBoundingBoxJsonRoundTrip(): void
    {
        $box = new BoundingBox(10, 20, 30, 40);
        self::assertSame([10, 20, 30, 40], BoundingBox::fromJson($box->toJson())?->toArray());
        self::assertNull(BoundingBox::fromJson(null));
        self::assertNull(BoundingBox::fromJson('[1,2,3]'));
    }
}
