<?php

/**
 * Guideline evidence for the pre-visit summary: topics from the patient's analytes, retrieved and cited.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Rag;

use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeQueryScrubber;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;

/**
 * The summarizer's RAG hookup. The synthesis doc's fact set already tells us
 * which analytes are in play (the {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\FactAnalyteResolver}
 * map the Chart Facts panel uses); this service maps those analyte keys onto
 * the SAME closed topic vocabulary the evidence tab uses
 * ({@see PatientEvidenceService::topicsForAnalyteKeys()}), retrieves cited
 * guideline snippets per topic through the shared retriever wiring
 * ({@see GuidelineRetrieverFactory} → pgvector store when configured, offline
 * hybrid corpus otherwise), and returns topic groups for the summary view's
 * deterministic "Guideline Evidence" section.
 *
 * Design invariants carried over from the rest of the module:
 *  - retrieval goes through {@see TracedGuidelineRetriever}, so every query is
 *    scrubbed before it leaves and every attempt records a `retrieve` span
 *    under the synthesis run's correlation id (dashboard waterfall +
 *    hit-rate tile);
 *  - the snippets are surfaced verbatim beside the narrative, never fed to the
 *    LLM and never entered into the fact/verifier pipeline (guideline evidence
 *    keeps its own SourceType::Guideline citations);
 *  - empty retrieval degrades to an empty list — the summary simply renders no
 *    evidence section, while the degraded `retrieve` spans stay visible to
 *    operators.
 */
final class SummaryGuidelineEvidence
{
    /** Snippets per topic — matches the evidence tab's default density. */
    private const SNIPPETS_PER_TOPIC = 2;

    public function __construct(
        private readonly RetrieverInterface $retriever,
        private readonly TraceRecorderInterface $tracer,
    ) {
    }

    public static function createDefault(): self
    {
        return new self(GuidelineRetrieverFactory::createDefault(), new TraceRecorder());
    }

    /**
     * @param array<string, array{key: string, label: string}> $analyteByFactId
     *        fact_id => analyte, as produced by FactAnalyteResolver for the
     *        summary's current fact set
     *
     * @return list<array{key: string, label: string, snippets: list<EvidenceSnippet>}>
     *         topic groups that actually retrieved evidence; [] when the
     *         patient has no mapped topics or nothing was retrieved
     */
    public function forSummary(string $correlationId, int $pid, ?int $userId, array $analyteByFactId): array
    {
        $analyteKeys = [];
        foreach ($analyteByFactId as $analyte) {
            if (!in_array($analyte['key'], $analyteKeys, true)) {
                $analyteKeys[] = $analyte['key'];
            }
        }

        $topicKeys = PatientEvidenceService::topicsForAnalyteKeys($analyteKeys);
        if ($topicKeys === []) {
            return [];
        }

        $service = new PatientEvidenceService(new TracedGuidelineRetriever(
            $this->retriever,
            new KnowledgeQueryScrubber(),
            $this->tracer,
            $correlationId,
            $pid,
            $userId,
        ));

        // Unlike the standalone evidence tab (which says "no matching
        // guideline" per topic), the summary view drops empty topics — a
        // degraded retrieval means no section, not an empty shell.
        $groups = [];
        foreach ($service->forTopics($topicKeys, self::SNIPPETS_PER_TOPIC) as $group) {
            if ($group['snippets'] !== []) {
                $groups[] = $group;
            }
        }

        return $groups;
    }
}
