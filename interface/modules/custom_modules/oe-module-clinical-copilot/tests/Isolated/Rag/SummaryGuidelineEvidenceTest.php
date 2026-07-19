<?php

/**
 * The summarizer's RAG hookup: analyte-derived topics, cited groups, traced and degradable.
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
use OpenEMR\Modules\ClinicalCopilot\Rag\SummaryGuidelineEvidence;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Agent\RecordingTraceRecorder;
use PHPUnit\Framework\TestCase;

/**
 * Failure modes guarded: the summary section rendering evidence for topics the
 * patient's chart never raised; retrievals bypassing the scrub boundary or
 * leaving no `retrieve` span under the synthesis correlation id; an empty
 * retrieval producing empty group shells instead of no section at all.
 */
final class SummaryGuidelineEvidenceTest extends TestCase
{
    /** fact_id => analyte, in the FactAnalyteResolver map shape. */
    private const ANALYTES = [
        'fact-1' => ['key' => 'a1c', 'label' => 'A1c'],
        'fact-2' => ['key' => 'glucose', 'label' => 'Glucose'],
        'fact-3' => ['key' => 'ldl', 'label' => 'LDL Cholesterol'],
    ];

    public function testTopicsDerivedFromAnalytesYieldCitedGroups(): void
    {
        $spy = new SpyRetriever([SpyRetriever::snippet()]);
        $tracer = new RecordingTraceRecorder();
        $service = new SummaryGuidelineEvidence($spy, $tracer);

        $groups = $service->forSummary('corr-sum-1', 42, 7, self::ANALYTES);

        // a1c + glucose fold into the a1c topic; ldl maps to lipids.
        self::assertSame(['a1c', 'lipids'], array_map(static fn (array $g): string => $g['key'], $groups));
        foreach ($groups as $group) {
            self::assertNotSame([], $group['snippets']);
            foreach ($group['snippets'] as $snippet) {
                self::assertSame(SourceType::Guideline, $snippet->citation->sourceType);
                self::assertNotSame('', $snippet->citation->quoteOrValue);
            }
        }

        // One retrieval per topic, each with a scrubbed outbound query: only
        // allowlisted clinical keywords, no stopwords, all lowercase.
        self::assertCount(2, $spy->calls);
        foreach ($spy->calls as $call) {
            self::assertNotSame('', $call['query']);
            self::assertStringNotContainsString(' and ', " {$call['query']} ");
            self::assertSame(strtolower($call['query']), $call['query']);
        }
        self::assertStringContainsString('a1c', $spy->calls[0]['query']);
        self::assertContains('a1c', $spy->calls[0]['tags']);

        // Both retrievals traced under the summary's correlation id.
        $spans = $tracer->spansOfKind('retrieve');
        self::assertCount(2, $spans);
        foreach ($spans as $span) {
            self::assertSame('corr-sum-1', $span->correlationId);
            self::assertSame('ok', $span->status);
            self::assertSame(42, $span->pid);
        }
    }

    public function testEmptyRetrievalDegradesToNoGroupsWithDegradedSpans(): void
    {
        $spy = new SpyRetriever([]);
        $tracer = new RecordingTraceRecorder();
        $service = new SummaryGuidelineEvidence($spy, $tracer);

        $groups = $service->forSummary('corr-sum-2', 42, null, self::ANALYTES);

        self::assertSame([], $groups);
        $spans = $tracer->spansOfKind('retrieve');
        self::assertCount(2, $spans);
        foreach ($spans as $span) {
            self::assertSame('degraded', $span->status);
            self::assertSame('EmptyRetrieval', $span->errorClass);
            self::assertMatchesRegularExpression('/^query_terms=\d+ top_k=\d+ hits=0$/', (string)$span->errorDetail);
        }
    }

    public function testNoMappedAnalytesMeansNoRetrievalAtAll(): void
    {
        $spy = new SpyRetriever([SpyRetriever::snippet()]);
        $tracer = new RecordingTraceRecorder();
        $service = new SummaryGuidelineEvidence($spy, $tracer);

        self::assertSame([], $service->forSummary('corr-sum-3', 42, 7, []));
        self::assertSame([], $service->forSummary('corr-sum-4', 42, 7, [
            'fact-9' => ['key' => 'not-an-analyte', 'label' => 'Mystery'],
        ]));
        self::assertSame([], $spy->calls);
        self::assertSame([], $tracer->spans());
    }
}
