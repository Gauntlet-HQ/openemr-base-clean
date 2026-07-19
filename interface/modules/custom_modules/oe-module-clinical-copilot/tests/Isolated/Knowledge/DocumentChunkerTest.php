<?php

/**
 * DocumentChunker — boundary-aware chunking with per-document sizing.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Knowledge\ChunkOptions;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\DocumentChunker;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\DocumentMetadata;
use PHPUnit\Framework\TestCase;

final class DocumentChunkerTest extends TestCase
{
    private DocumentChunker $chunker;

    private DocumentMetadata $meta;

    protected function setUp(): void
    {
        $this->chunker = new DocumentChunker();
        $this->meta = new DocumentMetadata(title: 'A1c Guideline', source: 'ADA 2026');
    }

    public function testEmptyTextYieldsNoChunks(): void
    {
        self::assertSame([], $this->chunker->chunk('   ', $this->meta));
    }

    public function testHeadingsBecomeSectionsAndIdsAreSlugIndexed(): void
    {
        $text = "Glycemic Targets\n\nAn A1c below 7% is reasonable for most adults.\n\n"
            . "Blood Pressure\n\nTarget below 130 over 80 when safely achievable.";

        $chunks = $this->chunker->chunk($text, $this->meta, [], new ChunkOptions(300, 0));

        self::assertGreaterThanOrEqual(2, count($chunks));
        self::assertSame('ada-2026-000', $chunks[0]->id);
        self::assertSame('Glycemic Targets', $chunks[0]->section);
        $sections = array_map(static fn ($c): string => $c->section, $chunks);
        self::assertContains('Blood Pressure', $sections);
    }

    public function testBaseTagsAppliedAndAnalyteTagsDetected(): void
    {
        $chunks = $this->chunker->chunk('LDL cholesterol and statin therapy reduce cardiovascular risk.', $this->meta, ['ada']);

        self::assertNotSame([], $chunks);
        self::assertContains('ada', $chunks[0]->tags);
        self::assertContains('ldl', $chunks[0]->tags);
        self::assertContains('statin', $chunks[0]->tags);
    }

    public function testSmallerTargetProducesMoreChunksThanLarger(): void
    {
        $paragraph = str_repeat('This is a clinical sentence about glycemic control and therapy. ', 40);

        $small = $this->chunker->chunk($paragraph, $this->meta, [], new ChunkOptions(400, 0));
        $large = $this->chunker->chunk($paragraph, $this->meta, [], new ChunkOptions(2000, 0));

        self::assertGreaterThan(count($large), count($small), 'chunk size is per-document, chosen at upload');
    }

    public function testNoChunkVastlyExceedsTheChosenTarget(): void
    {
        $paragraph = str_repeat('Sentence about A1c and lipids management here. ', 60);
        $target = 500;

        foreach ($this->chunker->chunk($paragraph, $this->meta, [], new ChunkOptions($target, 0)) as $chunk) {
            // Allow one sentence of spillover past the target, never unbounded.
            self::assertLessThan($target + 120, mb_strlen($chunk->text), "chunk '{$chunk->id}' overshot the target");
        }
    }

    public function testOverlapCarriesTrailingTextIntoTheNextChunk(): void
    {
        // Six ~60-char sentences (~380 chars total) in ONE paragraph, so the
        // block genuinely exceeds the 300-char target (ChunkOptions::MIN_TARGET
        // clamps anything smaller) and must split with an 80-char overlap.
        $sentences = [
            'Alpha sentence about hypoglycemia risk in insulin therapy.',
            'Bravo sentence about sulfonylurea dose reduction guidance.',
            'Charlie sentence about monitoring cadence for stable patients.',
            'Delta sentence about renal dosing thresholds for metformin users.',
            'Echo sentence about titration of basal insulin at bedtime.',
            'Foxtrot sentence about follow-up intervals after regimen changes.',
        ];
        $text = implode(' ', $sentences);

        $chunks = $this->chunker->chunk($text, $this->meta, [], new ChunkOptions(300, 80));

        self::assertGreaterThanOrEqual(2, count($chunks));
        // Overlap: the second chunk opens with the trailing sentence of the
        // first, so a boundary-straddling passage is retrievable from either side.
        $overlapSentence = mb_substr($chunks[1]->text, 0, (int)mb_strpos($chunks[1]->text, '.') + 1);
        self::assertNotSame('', $overlapSentence);
        self::assertContains($overlapSentence, $sentences);
        self::assertStringEndsWith($overlapSentence, $chunks[0]->text);
    }
}
