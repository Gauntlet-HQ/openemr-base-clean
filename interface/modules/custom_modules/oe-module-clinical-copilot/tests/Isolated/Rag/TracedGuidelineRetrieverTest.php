<?php

/**
 * The shared chat/summarizer retrieval seam: scrub-before-retrieve, retrieve spans, graceful degrade.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Rag;

use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeQueryScrubber;
use OpenEMR\Modules\ClinicalCopilot\Rag\TracedGuidelineRetriever;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Agent\RecordingTraceRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Failure modes guarded: a raw physician message (PHI-bearing free text)
 * reaching the retriever seam unscrubbed; a retrieval that leaves no
 * `retrieve` span for the dashboard waterfall / hit-rate tile; an empty
 * retrieval that fails the turn instead of degrading to "no evidence
 * section"; span detail text carrying the query (PHI) instead of counts.
 */
final class TracedGuidelineRetrieverTest extends TestCase
{
    private function retriever(SpyRetriever $spy, RecordingTraceRecorder $tracer, ?int $userId = 7): TracedGuidelineRetriever
    {
        return new TracedGuidelineRetriever(
            $spy,
            new KnowledgeQueryScrubber(),
            $tracer,
            'corr-traced-1',
            42,
            $userId,
        );
    }

    public function testOutboundQueryAndTagsAreScrubbedBeforeTheRetriever(): void
    {
        $spy = new SpyRetriever([SpyRetriever::snippet()]);
        $tracer = new RecordingTraceRecorder();

        $snippets = $this->retriever($spy, $tracer)->retrieve(
            "Why is Jane's A1c 9.4 on 3/2?",
            ['a1c', 'Jane Doe'],
            3,
        );

        self::assertCount(1, $snippets);
        self::assertCount(1, $spy->calls);
        // Only the recognized clinical term survives; the name and every
        // numeric token (value, date) are gone before the retriever seam.
        self::assertSame('a1c', $spy->calls[0]['query']);
        self::assertSame(['a1c'], $spy->calls[0]['tags']);
        self::assertSame(3, $spy->calls[0]['topK']);
    }

    public function testSuccessfulRetrievalRecordsAnOkRetrieveSpan(): void
    {
        $spy = new SpyRetriever([SpyRetriever::snippet()]);
        $tracer = new RecordingTraceRecorder();

        $this->retriever($spy, $tracer)->retrieve('a1c target', [], 3);

        $spans = $tracer->spansOfKind('retrieve');
        self::assertCount(1, $spans);
        self::assertSame('corr-traced-1', $spans[0]->correlationId);
        self::assertSame('ok', $spans[0]->status);
        self::assertSame(42, $spans[0]->pid);
        self::assertSame(7, $spans[0]->userId);
        self::assertNull($spans[0]->errorClass);
        self::assertNull($spans[0]->errorDetail);
    }

    public function testEmptyRetrievalDegradesWithPhiFreeCountsOnly(): void
    {
        $spy = new SpyRetriever([]);
        $tracer = new RecordingTraceRecorder();

        $snippets = $this->retriever($spy, $tracer)->retrieve('a1c target guideline', [], 3);

        self::assertSame([], $snippets);
        $spans = $tracer->spansOfKind('retrieve');
        self::assertCount(1, $spans);
        self::assertSame('degraded', $spans[0]->status);
        self::assertSame('EmptyRetrieval', $spans[0]->errorClass);
        // Counts only — never the query text.
        self::assertSame('query_terms=3 top_k=3 hits=0', $spans[0]->errorDetail);
    }

    /**
     * @param list<string> $tags
     */
    #[DataProvider('nothingClinicalProvider')]
    public function testNothingClinicalSkipsRetrievalAndRecordsNoSpan(string $query, array $tags): void
    {
        $spy = new SpyRetriever([SpyRetriever::snippet()]);
        $tracer = new RecordingTraceRecorder();

        $snippets = $this->retriever($spy, $tracer)->retrieve($query, $tags);

        self::assertSame([], $snippets);
        self::assertSame([], $spy->calls);
        self::assertSame([], $tracer->spans());
    }

    /**
     * @return array<string, array{string, list<string>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function nothingClinicalProvider(): array
    {
        return [
            'small talk' => ['thanks, that is all for now!', []],
            'empty message' => ['', []],
            'name and numbers only' => ['Jane Doe 555-0123 on 3/2', []],
            'unrecognized tags only' => ['', ['Jane Doe', 'not-a-topic']],
        ];
    }
}
