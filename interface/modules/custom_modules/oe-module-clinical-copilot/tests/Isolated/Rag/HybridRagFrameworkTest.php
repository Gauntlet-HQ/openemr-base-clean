<?php

/**
 * The hybrid RAG framework: local dense retrieval + heuristic rerank, offline.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Rag;

use OpenEMR\Modules\ClinicalCopilot\Rag\EvidenceSnippet;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineChunk;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineCorpus;
use OpenEMR\Modules\ClinicalCopilot\Rag\HeuristicReranker;
use OpenEMR\Modules\ClinicalCopilot\Rag\HybridRetriever;
use OpenEMR\Modules\ClinicalCopilot\Rag\LocalDenseRetriever;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: the summarizer's guideline-evidence section falling back
 * to keyword-only (dense/rerank being inert seams) or the offline dense/rerank
 * pass scrambling the right evidence to the top. These pin the framework
 * outline: dense retrieval returns cited, deterministic hits with genuine
 * sub-word overlap; the reranker reorders by cross-pair relevance and truncates;
 * and the default hybrid pipeline (keyword + dense + rerank) still surfaces the
 * correct guideline for each topic.
 */
final class HybridRagFrameworkTest extends TestCase
{
    private function corpus(): GuidelineCorpus
    {
        return new GuidelineCorpus(dirname(__DIR__, 3) . '/src/Rag/corpus');
    }

    public function testDenseRetrieverReturnsCitedDeterministicHits(): void
    {
        $dense = new LocalDenseRetriever($this->corpus());

        $a = $dense->retrieve('what is the A1c goal', ['a1c'], 3);
        $b = $dense->retrieve('what is the A1c goal', ['a1c'], 3);

        self::assertNotSame([], $a);
        self::assertSame(
            array_map(static fn ($h) => $h->chunk->id, $a),
            array_map(static fn ($h) => $h->chunk->id, $b),
            'dense retrieval must be deterministic',
        );
        self::assertSame('ada-a1c-target', $a[0]->chunk->id);
    }

    public function testDenseCatchesSubWordOverlapSparseKeywordMatchWouldMiss(): void
    {
        // "glycaemic" (British spelling) shares no whole token with the corpus'
        // "glycemic"/"a1c" text, but the character-trigram embedding still pulls
        // the A1c guidance — the signal the dense half exists to add.
        $dense = new LocalDenseRetriever($this->corpus());

        $hits = $dense->retrieve('glycaemic control target', ['a1c'], 3);

        self::assertNotSame([], $hits);
        self::assertContains('ada-a1c-target', array_map(static fn ($h) => $h->chunk->id, $hits));
    }

    public function testRerankerReordersByCoverageAndTruncates(): void
    {
        $strong = EvidenceSnippet::forChunk(
            new GuidelineChunk('strong', 'Blood pressure target', 'ADA', '10.x', 'The blood pressure target for most adults with diabetes is below 130/80.', ['blood_pressure']),
            0.1, // low first-stage score...
        );
        $weak = EvidenceSnippet::forChunk(
            new GuidelineChunk('weak', 'Hypoglycemia', 'ADA', '6.x', 'Recognize and treat low blood sugar promptly.', ['hypoglycemia']),
            0.9, // ...but high first-stage score
        );

        $out = (new HeuristicReranker())->rerank('blood pressure target', [$weak, $strong], 1);

        self::assertCount(1, $out, 'reranker truncates to topK');
        self::assertSame('strong', $out[0]->chunk->id, 'full query-term coverage outranks a high raw score');
    }

    /**
     * Failure mode guarded: `forChunk()` dropping the chunk's section/url on
     * the citation (the pre-fix behaviour passed `pageOrSection: null`), which
     * left guideline citations without the spec's source+section+chunk-id+quote
     * provenance for any consumer that only sees the citation.
     */
    public function testForChunkCarriesSectionAndUrlIntoTheCitation(): void
    {
        $snippet = EvidenceSnippet::forChunk(
            new GuidelineChunk('ada-a1c-target', 'A1c goal', 'ADA (summary)', 'Glycemic Targets', 'An A1c below 7% is reasonable.', ['a1c'], 'https://diabetes.org/standards'),
            0.5,
        );

        self::assertSame('ADA (summary)', $snippet->citation->sourceId);
        self::assertSame('Glycemic Targets', $snippet->citation->pageOrSection);
        self::assertSame('ada-a1c-target', $snippet->citation->fieldOrChunkId);
        self::assertSame('https://diabetes.org/standards', $snippet->citation->url);

        // A chunk with no section and no url degrades to nulls, never ''.
        $bare = EvidenceSnippet::forChunk(new GuidelineChunk('x', 'T', 'SRC', '', 'Body text.'), 0.1);
        self::assertNull($bare->citation->pageOrSection);
        self::assertNull($bare->citation->url);
    }

    public function testDefaultHybridPipelineSurfacesTheRightGuidelinePerTopic(): void
    {
        $hybrid = HybridRetriever::createDefault();

        self::assertSame('ada-a1c-target', $hybrid->retrieve('A1c goal', ['a1c'], 3)[0]->chunk->id);
        self::assertSame('ada-lipid-statin', $hybrid->retrieve('statin therapy', ['lipids'], 3)[0]->chunk->id);
        self::assertSame('ada-uacr-screening', $hybrid->retrieve('annual kidney screening', ['acr'], 3)[0]->chunk->id);
        self::assertSame('ada-blood-pressure', $hybrid->retrieve('blood pressure target', ['blood_pressure'], 3)[0]->chunk->id);
    }
}
