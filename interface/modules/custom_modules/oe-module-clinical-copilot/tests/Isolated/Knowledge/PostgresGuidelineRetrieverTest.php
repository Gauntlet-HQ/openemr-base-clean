<?php

/**
 * PostgresGuidelineRetriever — maps knowledge rows to cited evidence, PHI-safely.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Ingest\SourceType;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeQueryRunner;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeQueryScrubber;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\PostgresGuidelineRetriever;
use OpenEMR\Modules\ClinicalCopilot\Rag\PassthroughReranker;
use PHPUnit\Framework\TestCase;

/**
 * Bound to a fake {@see KnowledgeQueryRunner} so the whole retriever runs with no
 * database. Verifies: (1) it never queries when unavailable or when the query
 * scrubs empty, (2) rows become cited {@see \OpenEMR\Modules\ClinicalCopilot\Rag\EvidenceSnippet}s
 * with a Guideline source type carrying full provenance (source, section, chunk
 * id, quote, url), (3) the SQL it sends carries only the scrubbed, non-PHI query
 * text, and (4) the second-stage rerank: candidates are over-fetched from BOTH
 * SQL stages and reordered by cross-pair relevance before topK truncation.
 *
 * Failure modes guarded: production silently shipping raw ts_rank/cosine order
 * (no rerank — the exact audit finding), the over-fetch collapsing back to
 * LIMIT topK (reranker starved of candidates), and guideline citations losing
 * their section/url provenance on the way out of the store.
 */
final class PostgresGuidelineRetrieverTest extends TestCase
{
    public function testReturnsEmptyWhenStoreUnavailable(): void
    {
        $runner = new FakeKnowledgeRunner(available: false);
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber());

        self::assertSame([], $retriever->retrieve('a1c target', ['a1c']));
        self::assertNull($runner->lastSql, 'must not query an unavailable store');
    }

    public function testDoesNotQueryWhenNothingSafeToSearch(): void
    {
        // Query is pure PHI (name + number) and no tags => scrubs to empty.
        $runner = new FakeKnowledgeRunner(available: true, rows: []);
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber());

        self::assertSame([], $retriever->retrieve('John 55', []));
        self::assertNull($runner->lastSql);
    }

    public function testMapsRowsToCitedGuidelineEvidence(): void
    {
        $runner = new FakeKnowledgeRunner(available: true, rows: [
            [
                'id' => 'ada-a1c-target',
                'title' => 'A1c goal',
                'source' => 'ADA (summary)',
                'section' => 'Glycemic Targets',
                'body' => 'For most non-pregnant adults an A1c below 7% is reasonable.',
                'tags' => '{a1c,glycemic}',
                'url' => 'https://diabetes.org/standards',
                'score' => 0.87,
            ],
        ]);
        // PassthroughReranker: this test pins ROW→SNIPPET mapping fidelity
        // (including the raw first-stage score), not the rerank reordering,
        // which has its own tests below.
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber(), 'guideline_chunks', null, new PassthroughReranker());

        $snippets = $retriever->retrieve('a1c target', ['a1c']);

        self::assertCount(1, $snippets);
        $snippet = $snippets[0];
        self::assertSame('ada-a1c-target', $snippet->chunk->id);
        self::assertSame(['a1c', 'glycemic'], $snippet->chunk->tags);
        self::assertEqualsWithDelta(0.87, $snippet->score, 1e-9);
        self::assertSame(SourceType::Guideline, $snippet->citation->sourceType);
        self::assertSame('ADA (summary)', $snippet->citation->sourceId);
        // Full provenance rides on the CITATION itself (not just the chunk):
        // source + section + chunk id + quote + url.
        self::assertSame('Glycemic Targets', $snippet->citation->pageOrSection);
        self::assertSame('ada-a1c-target', $snippet->citation->fieldOrChunkId);
        self::assertSame('https://diabetes.org/standards', $snippet->citation->url);
        self::assertNotSame('', $snippet->citation->quoteOrValue);
    }

    public function testSendsOnlyScrubbedNonPhiQueryText(): void
    {
        $runner = new FakeKnowledgeRunner(available: true, rows: []);
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber());

        $retriever->retrieve("why is Jane's A1c 9.4 cholesterol", ['a1c']);

        self::assertIsString($runner->lastSql);
        self::assertStringContainsString('websearch_to_tsquery', (string)$runner->lastSql);
        $q = $runner->lastParams['q'] ?? '';
        self::assertIsString($q);
        self::assertStringContainsString('cholesterol', $q);
        self::assertStringNotContainsString('Jane', $q);
        self::assertStringNotContainsString('9.4', $q);
    }

    public function testUnsafeTagsNeverReachTheSqlBindParams(): void
    {
        // SECURITY.md finding #12: tags used to bypass the scrubber, so a
        // PHI-carrying tag ("jane") became a `tags &&` bind param sent to the
        // non-BAA store. Tags now pass the scrubber's allowlist first.
        $runner = new FakeKnowledgeRunner(available: true, rows: []);
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber());

        $retriever->retrieve('a1c target', ['jane', 'MRN12345678', 'a1c']);

        self::assertIsString($runner->lastSql);
        $flattened = json_encode($runner->lastParams, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('a1c', $flattened);
        self::assertStringNotContainsString('jane', $flattened);
        self::assertStringNotContainsString('12345678', strtolower($flattened));
    }

    public function testAllTagsUnsafeAndNoSafeTextMeansNoQueryAtAll(): void
    {
        // If NOTHING survives scrubbing — free text all PHI, tags all
        // unrecognized — the retriever must not touch the external store.
        $runner = new FakeKnowledgeRunner(available: true, rows: []);
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber());

        self::assertSame([], $retriever->retrieve('John 55', ['jane', 'MRN12345678']));
        self::assertNull($runner->lastSql);
    }

    public function testRejectsAnUnsafeTableName(): void
    {
        $this->expectException(\DomainException::class);
        new PostgresGuidelineRetriever(new FakeKnowledgeRunner(true), new KnowledgeQueryScrubber(), 'chunks; DROP TABLE x');
    }

    public function testUsesPgvectorSearchWhenEmbeddingsAreAvailable(): void
    {
        $runner = new FakeKnowledgeRunner(available: true, rows: [
            ['id' => 'ada-a1c', 'title' => 'A1c', 'source' => 'ADA', 'section' => 'T', 'body' => 'A1c below 7%.', 'tags' => '{a1c}', 'url' => null, 'score' => 0.9],
        ]);
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber(), 'guideline_chunks', new FakeEmbedder());

        $snippets = $retriever->retrieve('a1c target', ['a1c']);

        // Vector search runs first, ranking by pgvector cosine distance over
        // the embedded rows only — no full-text predicate in that query.
        self::assertNotSame([], $runner->queries);
        [$vectorSql, $vectorParams] = $runner->queries[0];
        self::assertStringContainsString('embedding <=> :qvec::vector', $vectorSql);
        self::assertStringNotContainsString('websearch_to_tsquery', $vectorSql);
        self::assertArrayHasKey('qvec', $vectorParams);
        self::assertStringStartsWith('[', (string)$vectorParams['qvec']);

        // Hybrid: one vector row < the over-fetched candidate pool, so the
        // remaining slots are topped up from full-text — a mixed store's
        // non-embedded rows stay reachable.
        self::assertCount(2, $runner->queries);
        self::assertStringContainsString('websearch_to_tsquery', $runner->queries[1][0]);

        // Over-fetch applies to BOTH stages: topK=4 ⇒ a 12-candidate pool for
        // the reranker, not LIMIT 4.
        self::assertStringContainsString('LIMIT 12', $runner->queries[0][0]);
        self::assertStringContainsString('LIMIT 12', $runner->queries[1][0]);

        // Both queries returned the same row; the merge dedupes it by id.
        self::assertCount(1, $snippets);
        self::assertSame('ada-a1c', $snippets[0]->chunk->id);
    }

    public function testVectorSearchAloneServesTheRequestWhenItFillsTheCandidatePool(): void
    {
        // topK=1 over-fetches a pool of 8 (the candidate floor); give the vector
        // stage exactly 8 rows so it fills the pool on its own.
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $rows[] = ['id' => "chunk-{$i}", 'title' => 'A1c', 'source' => 'ADA', 'section' => 'T', 'body' => 'A1c below 7%.', 'tags' => '{a1c}', 'url' => null, 'score' => 0.9 - $i / 100];
        }
        $runner = new FakeKnowledgeRunner(available: true, rows: $rows);
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber(), 'guideline_chunks', new FakeEmbedder());

        $snippets = $retriever->retrieve('a1c target', ['a1c'], topK: 1);

        // The vector rows fill the candidate pool, so no full-text top-up is
        // issued — and the rerank still truncates the 8 candidates to topK=1.
        self::assertCount(1, $snippets);
        self::assertCount(1, $runner->queries);
        self::assertStringContainsString('embedding <=> :qvec::vector', (string)$runner->lastSql);
        self::assertStringContainsString('LIMIT 8', (string)$runner->lastSql);
        self::assertStringNotContainsString('websearch_to_tsquery', (string)$runner->lastSql);
    }

    public function testFallsBackToFullTextWhenEmbeddingsUnavailable(): void
    {
        // Default constructor => UnavailableEmbeddingClient => no vector path.
        $runner = new FakeKnowledgeRunner(available: true, rows: []);
        (new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber()))->retrieve('a1c target', ['a1c']);

        self::assertStringContainsString('websearch_to_tsquery', (string)$runner->lastSql);
    }

    /**
     * The audit finding this workstream fixes: the deployed Postgres path used
     * to return raw SQL order. Failure mode guarded — a chunk with a high
     * first-stage score but no real relevance to the query outranking the
     * chunk that actually answers it.
     */
    public function testSparseOnlyPathOverFetchesAndReranks(): void
    {
        // Raw SQL order: the irrelevant lipid chunk FIRST (score 0.95), the
        // on-topic A1c chunk second (score 0.10).
        $runner = new FakeKnowledgeRunner(available: true, rows: [
            ['id' => 'ldl-chunk', 'title' => 'Statin therapy', 'source' => 'ADA', 'section' => 'Lipids', 'body' => 'Statin therapy reduces LDL cholesterol.', 'tags' => '{ldl}', 'url' => null, 'score' => 0.95],
            ['id' => 'a1c-chunk', 'title' => 'A1c target', 'source' => 'ADA', 'section' => 'Glycemic Targets', 'body' => 'An A1c target below 7% is recommended for most adults.', 'tags' => '{a1c}', 'url' => null, 'score' => 0.10],
        ]);
        // No embedder => sparse-only (full-text) path; default HeuristicReranker.
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber());

        $snippets = $retriever->retrieve('a1c target', ['a1c']);

        // The sparse-only stage over-fetches too (topK=4 ⇒ LIMIT 12) ...
        self::assertStringContainsString('LIMIT 12', (string)$runner->lastSql);
        // ... and the reranker's query-term coverage flips the raw SQL order:
        // the chunk covering "a1c target" wins despite its far lower ts_rank.
        self::assertCount(2, $snippets);
        self::assertSame('a1c-chunk', $snippets[0]->chunk->id);
        self::assertSame('ldl-chunk', $snippets[1]->chunk->id);
    }

    public function testVectorPathReranksMergedCandidatesAndKeepsDedupe(): void
    {
        $ldlRow = ['id' => 'ldl-chunk', 'title' => 'Statin therapy', 'source' => 'ADA', 'section' => 'Lipids', 'body' => 'Statin therapy reduces LDL cholesterol.', 'tags' => '{ldl}', 'url' => null, 'score' => 0.95];
        $a1cRow = ['id' => 'a1c-chunk', 'title' => 'A1c target', 'source' => 'ADA', 'section' => 'Glycemic Targets', 'body' => 'An A1c target below 7% is recommended for most adults.', 'tags' => '{a1c}', 'url' => null, 'score' => 0.10];

        // Vector stage: wrong order (irrelevant chunk first). Full-text top-up:
        // returns a row the merge must dedupe by id.
        $runner = new FakeKnowledgeRunner(available: true, rows: [], rowsPerQuery: [
            [$ldlRow, $a1cRow],
            [$ldlRow],
        ]);
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber(), 'guideline_chunks', new FakeEmbedder());

        $snippets = $retriever->retrieve('a1c target', ['a1c']);

        // Both stages ran (2 vector rows < the 12-candidate pool ⇒ top-up).
        self::assertCount(2, $runner->queries);
        // Dedupe by id survives the rerank: 3 rows in, 2 distinct snippets out,
        // reordered so the query-covering chunk beats the higher cosine score.
        self::assertCount(2, $snippets);
        self::assertSame('a1c-chunk', $snippets[0]->chunk->id);
        self::assertSame('ldl-chunk', $snippets[1]->chunk->id);
    }

    public function testTopKStillCapsTheRerankedResult(): void
    {
        $runner = new FakeKnowledgeRunner(available: true, rows: [
            ['id' => 'c1', 'title' => 'A1c target', 'source' => 'ADA', 'section' => 'S1', 'body' => 'An A1c target below 7%.', 'tags' => '{a1c}', 'url' => null, 'score' => 0.3],
            ['id' => 'c2', 'title' => 'A1c monitoring', 'source' => 'ADA', 'section' => 'S2', 'body' => 'Check A1c twice yearly at target.', 'tags' => '{a1c}', 'url' => null, 'score' => 0.2],
            ['id' => 'c3', 'title' => 'Lipids', 'source' => 'ADA', 'section' => 'S3', 'body' => 'Statin therapy for LDL.', 'tags' => '{ldl}', 'url' => null, 'score' => 0.1],
        ]);
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber());

        // Over-fetch brought back 3 candidates; topK=2 must still cap the output.
        self::assertCount(2, $retriever->retrieve('a1c target', ['a1c'], topK: 2));
    }
}

/**
 * A deterministic embedder that reports available and returns a fixed vector, so
 * the retriever's vector branch is exercised with no provider.
 */
final class FakeEmbedder implements \OpenEMR\Modules\ClinicalCopilot\Knowledge\EmbeddingClientInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function dimension(): int
    {
        return 3;
    }

    public function embed(string $text): ?array
    {
        return [0.1, 0.2, 0.3];
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn (string $t): array => [0.1, 0.2, 0.3], $texts);
    }
}

/**
 * A canned {@see KnowledgeQueryRunner} that records the SQL/params it was asked
 * to run and returns preset rows — no PDO, no Postgres.
 */
final class FakeKnowledgeRunner implements KnowledgeQueryRunner
{
    public ?string $lastSql = null;

    /** @var array<string, scalar|null> */
    public array $lastParams = [];

    /** @var list<array{0: string, 1: array<string, scalar|null>}> every select, in order */
    public array $queries = [];

    /**
     * @param list<array<string, mixed>>       $rows         returned by every select (when $rowsPerQuery is null/exhausted)
     * @param list<list<array<string, mixed>>> $rowsPerQuery per-select result sets, consumed in call order
     */
    public function __construct(
        private readonly bool $available,
        private readonly array $rows = [],
        private array $rowsPerQuery = [],
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function select(string $sql, array $params = []): array
    {
        $this->lastSql = $sql;
        $this->lastParams = $params;
        $this->queries[] = [$sql, $params];

        if ($this->rowsPerQuery !== []) {
            return array_shift($this->rowsPerQuery);
        }

        return $this->rows;
    }
}
