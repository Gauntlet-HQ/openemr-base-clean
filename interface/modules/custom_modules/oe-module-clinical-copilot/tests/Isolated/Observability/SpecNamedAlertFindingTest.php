<?php

/**
 * Decision logic of the three Week-2 spec-named alerts (extraction failure rate, RAG retrieval latency, eval regression) plus the ingestion-latency SLO alert.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Observability;

use OpenEMR\Modules\ClinicalCopilot\Observability\Alert\AlertEvaluator;
use OpenEMR\Modules\ClinicalCopilot\Observability\Alert\AlertName;
use PHPUnit\Framework\TestCase;

/**
 * Failure modes guarded, per alert (the DB half -- the SQL that gathers these
 * inputs -- is covered by tests/Db/Observability/AlertEvaluatorTest.php; these
 * tests pin the pure threshold semantics that decide firing):
 *
 *  - extraction failure rate: firing on an EMPTY window (no uploads reads as
 *    an incident and pages someone at 3am), or the inverse -- a 100% failure
 *    rate staying quiet because the comparison drifted to >=/<=.
 *  - RAG retrieval latency: the alert silently measuring the wrong thing --
 *    it must be the retrieval-stage p95, must not fire with zero retrievals,
 *    and must fire when the p95 (not the mean, which a majority of fast
 *    requests can drag under threshold) crosses the line.
 *  - eval regression: the alert firing before any eval run has ever been
 *    recorded (false alarm on a fresh install), staying quiet when the last
 *    recorded run had regressions, or crashing on a malformed stored row.
 *  - ingestion latency: same failure modes as RAG retrieval latency, against
 *    the documented upload->draft SLO (ops/cost-analysis.md: p95 < ~8 s) --
 *    must not fire on a window with no ingestions, and must fire on the p95
 *    tail even when the mean sits under threshold.
 */
final class SpecNamedAlertFindingTest extends TestCase
{
    public function testExtractionFailureNeverFiresOnAnEmptyWindow(): void
    {
        $finding = AlertEvaluator::extractionFailureFinding(0, 0, 10.0);

        self::assertSame(AlertName::ExtractionFailureRate, $finding->name);
        self::assertFalse($finding->fired, 'no uploads in the window is not a failure signal');
    }

    public function testExtractionFailureStaysQuietAtOrBelowThreshold(): void
    {
        // 1/10 = exactly 10.0% -- the threshold is strictly exceeded, so a
        // rate AT threshold does not fire (mirrors every other rate alert).
        self::assertFalse(AlertEvaluator::extractionFailureFinding(10, 1, 10.0)->fired);
    }

    public function testExtractionFailureFiresAboveThresholdWithTheRateAsMetric(): void
    {
        $finding = AlertEvaluator::extractionFailureFinding(10, 3, 10.0);

        self::assertTrue($finding->fired);
        self::assertSame(30.0, $finding->metricValue);
        self::assertSame(10.0, $finding->threshold);
        self::assertStringContainsString('3 of 10', $finding->message);
    }

    public function testRagLatencyNeverFiresWithNoRetrievalsInTheWindow(): void
    {
        $finding = AlertEvaluator::ragRetrievalLatencyFinding([], 2000.0, 15);

        self::assertSame(AlertName::RagRetrievalLatency, $finding->name);
        self::assertFalse($finding->fired, 'zero retrieve spans must read as quiet, not as an incident');
    }

    public function testRagLatencyStaysQuietWhenP95IsWithinThreshold(): void
    {
        self::assertFalse(AlertEvaluator::ragRetrievalLatencyFinding([100, 150, 200, 400], 2000.0, 15)->fired);
    }

    public function testRagLatencyFiresOnTheTailNotTheMean(): void
    {
        // 9 fast retrievals and one 15s outlier: the mean (~1.6s) sits under
        // the 2s threshold but the nearest-rank p95 catches the tail --
        // exactly the "slow knowledge store hiding inside the chat-turn
        // budget" case this alert exists for.
        $durations = array_merge(array_fill(0, 9, 100), [15000]);
        $finding = AlertEvaluator::ragRetrievalLatencyFinding($durations, 2000.0, 15);

        self::assertTrue($finding->fired);
        self::assertGreaterThan(2000.0, $finding->metricValue);
    }

    public function testIngestionLatencyNeverFiresWithNoIngestionsInTheWindow(): void
    {
        $finding = AlertEvaluator::ingestionLatencyFinding([], 8000.0, 15);

        self::assertSame(AlertName::IngestionLatency, $finding->name);
        self::assertFalse($finding->fired, 'zero ingest/preview spans must read as quiet, not as an incident');
    }

    public function testIngestionLatencyStaysQuietWhenP95IsWithinTheSlo(): void
    {
        // Healthy uploads: a few seconds each, all under the 8s SLO target.
        self::assertFalse(AlertEvaluator::ingestionLatencyFinding([2500, 3200, 4100, 6800], 8000.0, 15)->fired);
    }

    public function testIngestionLatencyFiresOnTheTailNotTheMean(): void
    {
        // 9 healthy ~3s ingests and one 40s outlier: the mean (~6.7s) sits
        // under the 8s SLO but the nearest-rank p95 catches the tail -- a
        // provider-side vision slowdown showing up as a dead upload queue.
        $durations = array_merge(array_fill(0, 9, 3000), [40000]);
        $finding = AlertEvaluator::ingestionLatencyFinding($durations, 8000.0, 15);

        self::assertTrue($finding->fired);
        self::assertGreaterThan(8000.0, $finding->metricValue);
        self::assertSame(8000.0, $finding->threshold);
        self::assertStringContainsString('document ingestion p95', $finding->message);
    }

    public function testEvalRegressionIsQuietWhenNoRunWasEverRecorded(): void
    {
        $finding = AlertEvaluator::evalRegressionFinding([]);

        self::assertSame(AlertName::EvalRegression, $finding->name);
        self::assertFalse($finding->fired, 'a fresh install with no recorded eval run must not page anyone');
        self::assertStringContainsString('no eval run has been recorded', $finding->message);
    }

    public function testEvalRegressionIsQuietAfterACleanRun(): void
    {
        $finding = AlertEvaluator::evalRegressionFinding([
            'ran_at' => '2026-07-18T09:00:00+00:00',
            'passed' => true,
            'regression_count' => 0,
            'regressions' => [],
            'case_count' => 50,
        ]);

        self::assertFalse($finding->fired);
        self::assertStringContainsString('passed with no regressions', $finding->message);
    }

    public function testEvalRegressionFiresWhileTheLastRecordedRunHadRegressions(): void
    {
        $finding = AlertEvaluator::evalRegressionFinding([
            'ran_at' => '2026-07-18T09:00:00+00:00',
            'passed' => false,
            'regression_count' => 2,
            'regressions' => [
                'citation_present regressed: 0.820 < baseline 0.960 - 0.05',
                'schema_valid below floor: 0.880 < 0.90',
            ],
            'case_count' => 50,
        ]);

        self::assertTrue($finding->fired);
        self::assertSame(2.0, $finding->metricValue);
        self::assertStringContainsString('citation_present regressed', $finding->message, 'the first regression is surfaced so the banner reads as an incident, not a count');
    }

    public function testEvalRegressionSurvivesAMalformedStoredRow(): void
    {
        // A hand-edited or truncated config row must degrade to a non-crash:
        // unparseable regression_count reads as 0 => quiet but honest.
        $finding = AlertEvaluator::evalRegressionFinding([
            'ran_at' => '2026-07-18T09:00:00+00:00',
            'regression_count' => 'not-a-number',
            'regressions' => 'also-not-a-list',
        ]);

        self::assertFalse($finding->fired);
    }
}
