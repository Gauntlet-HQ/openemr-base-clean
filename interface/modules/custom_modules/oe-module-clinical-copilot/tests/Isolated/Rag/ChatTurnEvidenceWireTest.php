<?php

/**
 * The chat turn's evidence payload: cited wire shape on hits, clean absence on none.
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
use OpenEMR\Modules\ClinicalCopilot\Rag\EvidenceSnippetPresenter;
use OpenEMR\Modules\ClinicalCopilot\Rag\TracedGuidelineRetriever;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Agent\RecordingTraceRecorder;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the exact pipeline {@see \OpenEMR\Modules\ClinicalCopilot\Controller\ChatController::retrieveGuidelineEvidence()}
 * runs for a turn (TracedGuidelineRetriever over the raw physician message,
 * then EvidenceSnippetPresenter::toWire), against a spy retriever — the
 * DB-free equivalent of asserting on the `evidence` key of the turn response.
 *
 * Failure modes guarded: a chat turn surfacing evidence without full
 * provenance (missing citation contract fields); a hit-less turn carrying an
 * evidence stub instead of an empty list; the raw message reaching the
 * retriever unscrubbed.
 */
final class ChatTurnEvidenceWireTest extends TestCase
{
    /**
     * @return list<array{title: string, source: string, section: string, quote: string, url: ?string, score: float, citation: array<string, mixed>}>
     */
    private function runTurnRetrieval(SpyRetriever $spy, RecordingTraceRecorder $tracer, string $message): array
    {
        $retriever = new TracedGuidelineRetriever(
            $spy,
            new KnowledgeQueryScrubber(),
            $tracer,
            'corr-chat-1',
            42,
            7,
        );

        return EvidenceSnippetPresenter::toWire($retriever->retrieve($message, [], 3));
    }

    public function testTurnWithGuidelineHitsSurfacesFullyCitedEvidence(): void
    {
        $spy = new SpyRetriever([SpyRetriever::snippet()]);
        $tracer = new RecordingTraceRecorder();

        $evidence = $this->runTurnRetrieval($spy, $tracer, 'is her A1c at goal?');

        self::assertCount(1, $evidence);
        $entry = $evidence[0];
        self::assertSame('Glycemic targets', $entry['title']);
        self::assertSame('ADA Standards of Care', $entry['source']);
        self::assertSame('Glycemic Targets', $entry['section']);
        self::assertNotSame('', $entry['quote']);
        self::assertSame('https://example.org/ada', $entry['url']);

        // The full SourceCitation contract rides along — provenance enough to
        // attribute the snippet from the wire payload alone.
        $citation = $entry['citation'];
        self::assertSame('guideline', $citation['source_type']);
        self::assertSame('ADA Standards of Care', $citation['source_id']);
        self::assertSame('Glycemic Targets', $citation['page_or_section']);
        self::assertSame('ada-a1c-target', $citation['field_or_chunk_id']);
        self::assertNotSame('', $citation['quote_or_value']);
        self::assertSame('https://example.org/ada', $citation['url']);

        // The outbound query was scrubbed: clinical keywords only.
        self::assertSame('a1c goal', $spy->calls[0]['query']);

        // And the retrieval is dashboard-visible under the turn's correlation id.
        $spans = $tracer->spansOfKind('retrieve');
        self::assertCount(1, $spans);
        self::assertSame('corr-chat-1', $spans[0]->correlationId);
        self::assertSame('ok', $spans[0]->status);
    }

    public function testTurnWithNoHitsDegradesToAnEmptyEvidenceList(): void
    {
        $spy = new SpyRetriever([]);
        $tracer = new RecordingTraceRecorder();

        $evidence = $this->runTurnRetrieval($spy, $tracer, 'what is the a1c guideline target?');

        self::assertSame([], $evidence);
        $spans = $tracer->spansOfKind('retrieve');
        self::assertCount(1, $spans);
        self::assertSame('degraded', $spans[0]->status);
        self::assertSame('EmptyRetrieval', $spans[0]->errorClass);
    }

    public function testNonClinicalTurnCarriesNoEvidenceAndNoSpan(): void
    {
        $spy = new SpyRetriever([SpyRetriever::snippet()]);
        $tracer = new RecordingTraceRecorder();

        $evidence = $this->runTurnRetrieval($spy, $tracer, 'thanks, see you tomorrow!');

        self::assertSame([], $evidence);
        self::assertSame([], $spy->calls);
        self::assertSame([], $tracer->spans());
    }
}
