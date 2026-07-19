<?php

/**
 * Sparse guideline retrieval: relevant evidence surfaces, cited, deterministically.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Rag;

use OpenEMR\Modules\ClinicalCopilot\Ingest\SourceType;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineCorpus;
use OpenEMR\Modules\ClinicalCopilot\Rag\HybridRetriever;
use OpenEMR\Modules\ClinicalCopilot\Rag\PassthroughReranker;
use OpenEMR\Modules\ClinicalCopilot\Rag\SparseRetriever;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: the summarizer/chat augmentation surfacing the wrong
 * guideline (or none) for an out-of-range fact, or returning evidence with no
 * citation — which would make a recommendation ungrounded, exactly what the
 * doc's citation contract forbids.
 */
final class SparseRetrieverTest extends TestCase
{
    private function retriever(): SparseRetriever
    {
        // The committed corpus ships in src/Rag/corpus/.
        return new SparseRetriever(new GuidelineCorpus(dirname(__DIR__, 3) . '/src/Rag/corpus'));
    }

    public function testCorpusLoads(): void
    {
        self::assertNotSame([], (new GuidelineCorpus(dirname(__DIR__, 3) . '/src/Rag/corpus'))->all());
    }

    public function testRetrievesTheA1cTargetForAnA1cQuestion(): void
    {
        $hits = $this->retriever()->retrieve('what is the A1c goal for this patient', ['a1c'], 3);

        self::assertNotSame([], $hits);
        self::assertSame('ada-a1c-target', $hits[0]->chunk->id);
    }

    public function testEveryHitCarriesAGuidelineCitation(): void
    {
        $hits = $this->retriever()->retrieve('lipid statin therapy', ['lipids'], 3);

        self::assertNotSame([], $hits);
        foreach ($hits as $hit) {
            self::assertSame(SourceType::Guideline, $hit->citation->sourceType);
            self::assertNotSame('', $hit->citation->quoteOrValue, 'evidence must be cited, never bare');
            self::assertSame($hit->chunk->id, $hit->citation->fieldOrChunkId);
        }
    }

    public function testTagBoostDisambiguatesAcrossTopics(): void
    {
        // "screening" alone is generic; the acr tag should pull kidney screening.
        $hits = $this->retriever()->retrieve('annual screening', ['acr'], 1);

        self::assertNotSame([], $hits);
        self::assertSame('ada-uacr-screening', $hits[0]->chunk->id);
    }

    public function testRetrievalIsDeterministic(): void
    {
        $a = $this->retriever()->retrieve('blood pressure target', ['blood_pressure'], 4);
        $b = $this->retriever()->retrieve('blood pressure target', ['blood_pressure'], 4);

        self::assertSame(
            array_map(static fn ($h) => $h->chunk->id, $a),
            array_map(static fn ($h) => $h->chunk->id, $b),
        );
    }

    public function testUnrelatedQueryReturnsNothingRatherThanNoise(): void
    {
        $hits = $this->retriever()->retrieve('xylophone spacecraft velocity', [], 4);
        self::assertSame([], $hits);
    }

    public function testHybridDegradesToSparseOnlyWithNoDenseOrRerank(): void
    {
        $hybrid = new HybridRetriever($this->retriever(), new PassthroughReranker(), null);
        $hits = $hybrid->retrieve('A1c goal', ['a1c'], 2);

        self::assertNotSame([], $hits);
        self::assertLessThanOrEqual(2, count($hits));
        self::assertSame('ada-a1c-target', $hits[0]->chunk->id);
    }
}
