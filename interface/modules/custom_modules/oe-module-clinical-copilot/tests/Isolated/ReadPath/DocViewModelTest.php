<?php

/**
 * DocViewModel: facts-first bucketing (in-flight vs trend vs exclusion) and narrative ordering.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Capability\Support\DerivedFacts;
use OpenEMR\Modules\ClinicalCopilot\Doc\DocRow;
use OpenEMR\Modules\ClinicalCopilot\Doc\QaStatus;
use OpenEMR\Modules\ClinicalCopilot\Doc\RegenReason;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ClaimType;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\DocViewModel;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisDocPayload;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadResult;
use PHPUnit\Framework\TestCase;

/**
 * Pure, DB-free -- exercises the presenter with hand-built Facts/Claims,
 * never touching a database or the DocStore/VerifiedGeneration pipeline.
 * Guards the U8 acceptance criterion "preliminary renders in-flight and is
 * absent from the trend" at the presentation-routing layer (the deeper
 * "ControlProxy never emits a preliminary as trend_point" guarantee is U5's
 * own contract, exercised in tests/Db/Capability/ControlProxyTest.php).
 */
final class DocViewModelTest extends TestCase
{
    public function testPreliminaryAndPendingOrderRouteToInFlightNeverToCapabilityBucket(): void
    {
        $trend = self::trendPointFact();
        $preliminary = self::preliminaryResultFact();
        $pendingOrder = self::pendingOrderFact();

        $result = self::servedResult([$trend, $preliminary, $pendingOrder], null);
        $viewModel = DocViewModel::build($result, 'https://example.test');

        $inFlightKinds = array_column(self::group($viewModel, 'in_flight')['facts'], 'kind');
        self::assertEqualsCanonicalizing(['preliminary_result', 'pending_order'], $inFlightKinds);

        $controlProxyKinds = array_column(self::group($viewModel, 'control_proxy')['facts'], 'kind');
        self::assertSame(['trend_point'], $controlProxyKinds, 'the trend bucket must contain ONLY the trend_point fact -- never the preliminary result');

        self::assertNull(self::group($viewModel, 'pending_results'), 'a capability whose only facts are in-flight must not also appear as its own facts tab');
    }

    public function testExclusionRoutesToExclusionsBucketOnly(): void
    {
        $exclusion = self::exclusionFact();

        $result = self::servedResult([$exclusion], null);
        $viewModel = DocViewModel::build($result, 'https://example.test');

        self::assertCount(1, self::group($viewModel, 'exclusions')['facts']);
        self::assertNull(self::group($viewModel, 'in_flight'));
        self::assertNull(self::group($viewModel, 'control_proxy'), 'an excluded fact must never also appear as a capability facts tab');
    }

    public function testNarrativeIsOrderedByClaimOrderRegardlessOfInputOrder(): void
    {
        $trend = self::trendPointFact();

        $claimSecond = new Claim('second claim', ClaimType::Trend, [$trend->factId], [7.2], [], 1);
        $claimFirst = new Claim('first claim', ClaimType::Greeting, [], [], [], 0);

        // Deliberately fed out of order.
        $result = self::servedResult([$trend], [$claimSecond, $claimFirst]);
        $viewModel = DocViewModel::build($result, 'https://example.test');

        self::assertSame(['first claim', 'second claim'], array_column($viewModel['narrative'], 'text'));
    }

    public function testNarrativeCitationResolvesToTheCitedFactsChartLink(): void
    {
        $trend = self::trendPointFact();
        $claim = new Claim('A1c is rising.', ClaimType::Trend, [$trend->factId], [7.2], [], 0);

        $result = self::servedResult([$trend], [$claim]);
        $viewModel = DocViewModel::build($result, 'https://example.test');

        self::assertCount(1, $viewModel['narrative']);
        // Consolidated provenance: one group for the source table, its pk listed
        // beneath (the "#501.result" chip noise is gone from what the doctor sees).
        self::assertCount(1, $viewModel['narrative'][0]['citations']);
        self::assertSame('Lab result', $viewModel['narrative'][0]['citations'][0]['label']);
        self::assertSame(501, $viewModel['narrative'][0]['citations'][0]['refs'][0]['pk']);
        // procedure_result has no verified deep-link route (ChartLinkResolver's
        // own documented scope) -- tooltip fallback, url is null.
        self::assertNull($viewModel['narrative'][0]['citations'][0]['refs'][0]['url']);
    }

    public function testNarrativeConsolidatesAndDedupesCitationsByTableAndPk(): void
    {
        // Two facts citing the SAME physical lab row (pk 501) under different
        // fields, plus a distinct row (pk 502): the doctor should see one
        // "Lab result" group with 501 then 502, never the row repeated.
        $factA = self::trendPointFact();
        $factB = self::trendPointOn('2026-02-01', 502, 7.5);
        $claim = new Claim('A1c is rising.', ClaimType::Trend, [$factA->factId, $factB->factId], [7.2], [], 0);

        $result = self::servedResult([$factA, $factB], [$claim]);
        $viewModel = DocViewModel::build($result, 'https://example.test');

        $citations = $viewModel['narrative'][0]['citations'];
        self::assertCount(1, $citations, 'both facts share one source table -> one group');
        self::assertSame('Lab result', $citations[0]['label']);
        self::assertSame([501, 502], array_column($citations[0]['refs'], 'pk'));
    }

    public function testNarrativeStripsInlineFactIdMarkersFromDoctorFacingText(): void
    {
        $trend = self::trendPointFact();
        $hash = str_repeat('a', 64);
        $claim = new Claim(
            "A1c was 9.4% (fact_id: {$hash}), which is out of range.",
            ClaimType::Trend,
            [$trend->factId],
            [9.4],
            [],
            0,
        );

        $result = self::servedResult([$trend], [$claim]);
        $viewModel = DocViewModel::build($result, 'https://example.test');

        self::assertSame('A1c was 9.4%, which is out of range.', $viewModel['narrative'][0]['text']);
    }

    public function testCapabilityCrashResultStillPresentsSurvivingFactsThroughTheSameBuckets(): void
    {
        $trend = self::trendPointFact();
        $result = SynthesisReadResult::capabilityCrash('corr-1', 42, [$trend], 'VitalsTrend unavailable -- synthesis paused');

        $viewModel = DocViewModel::build($result, 'https://example.test');

        self::assertSame([], $viewModel['narrative'], 'a capability-crash result never carries claims (no digest/reduce ever ran)');
        self::assertSame(['trend_point'], array_column(self::group($viewModel, 'control_proxy')['facts'], 'kind'));
    }

    public function testCapabilityGroupIsSortedMostRecentFirstAndClampedToTwentyWithTotal(): void
    {
        // 25 trend points across 25 distinct months, fed oldest-first.
        $facts = [];
        for ($i = 1; $i <= 25; $i++) {
            $date = sprintf('%04d-%02d-01', 2023 + intdiv($i, 12), ($i % 12) + 1);
            $facts[] = self::trendPointOn($date, $i, (float) $i);
        }

        $viewModel = DocViewModel::build(self::servedResult($facts, null), 'https://example.test');
        $group = self::group($viewModel, 'control_proxy');

        self::assertNotNull($group);
        self::assertSame(25, $group['total'], 'the pre-clamp total must be carried through for the "N of TOTAL" caption');
        self::assertSame(20, $group['shown']);
        self::assertCount(20, $group['facts']);

        $dates = array_column($group['facts'], 'clinical_date');
        $sortedDesc = $dates;
        rsort($sortedDesc);
        self::assertSame($sortedDesc, $dates, 'facts must be ordered most-recent-first');
    }

    public function testControlProxyLabsSplitPerAnalyteWhenAnalyteMapProvided(): void
    {
        $a1c = self::trendPointOn('2026-06-01', 1, 7.2);
        $ldl = self::trendPointOn('2026-05-01', 2, 98.0);

        $map = [
            $a1c->factId => ['key' => 'a1c', 'label' => 'A1c'],
            $ldl->factId => ['key' => 'ldl', 'label' => 'LDL Cholesterol'],
        ];

        $viewModel = DocViewModel::build(self::servedResult([$a1c, $ldl], null), 'https://example.test', $map);

        $a1cGroup = self::group($viewModel, 'lab:a1c');
        $ldlGroup = self::group($viewModel, 'lab:ldl');
        self::assertNotNull($a1cGroup);
        self::assertNotNull($ldlGroup);
        self::assertSame('A1c', $a1cGroup['label']);
        self::assertSame('LDL Cholesterol', $ldlGroup['label']);
        self::assertNull(
            self::group($viewModel, 'control_proxy'),
            'labs must split per analyte, not fall back to one control_proxy group, when analytes are known'
        );
        self::assertSame('A1c', $a1cGroup['facts'][0]['analyte_label'], 'each lab row carries its analyte label');
        self::assertSame('lab:a1c', $viewModel['fact_groups'][0]['key'], 'A1c is the headline tab and sorts first');
    }

    public function testLabGroupConsolidatesDrawsIntoOneRowWithChangeColumnAndSeriesSummary(): void
    {
        // Two A1c draws plus the derived facts ControlProxy emits for the
        // series: the change ending at the later draw, and the series-level span
        // and count. The panel must show ONE row per draw (not one per fact),
        // the change as a column on the draw it lands on, and span/count lifted
        // into the group summary.
        $early = self::trendPointOn('2026-01-01', 1, 6.9);
        $late = self::trendPointOn('2026-06-01', 2, 7.2);
        $change = self::derivedOn('2026-06-01', FactKind::DerivedDelta, '+0.30', 0.3, '%');
        $span = self::derivedOn('2026-06-01', FactKind::DerivedSpan, '+0.30', 0.3, '%');
        $count = self::derivedOn('2026-06-01', FactKind::DerivedCount, '2', 2.0, '');

        $facts = [$early, $late, $change, $span, $count];
        $map = [];
        foreach ($facts as $fact) {
            $map[$fact->factId] = ['key' => 'a1c', 'label' => 'A1c'];
        }

        $viewModel = DocViewModel::build(self::servedResult($facts, null), 'https://example.test', $map);
        $group = self::group($viewModel, 'lab:a1c');

        self::assertNotNull($group);
        self::assertTrue($group['consolidated']);
        self::assertCount(2, $group['facts'], 'one row per draw -- derived facts are not their own rows');

        // Most recent draw first, carrying the change-from-prior in its column.
        self::assertSame('2026-06-01', $group['facts'][0]['clinical_date']);
        self::assertSame('+0.30 %', $group['facts'][0]['change_display']);
        // The earliest draw has no prior, so no change.
        self::assertSame('2026-01-01', $group['facts'][1]['clinical_date']);
        self::assertNull($group['facts'][1]['change_display']);

        // Span and count are the one-line series summary, not rows.
        self::assertNotNull($group['summary']);
        self::assertSame('2', $group['summary']['count']);
        self::assertSame('+0.30 %', $group['summary']['span']);
    }

    public function testConsolidatedLabGroupCarriesSparklineAndOtherGroupsDoNot(): void
    {
        // The per-analyte sparkline is computed over the same consolidated
        // draw rows the table shows; non-consolidated groups (vitals, meds,
        // in-flight, ...) never carry one.
        $early = self::trendPointOn('2026-01-01', 1, 6.9);
        $late = self::trendPointOn('2026-06-01', 2, 7.2);
        $map = [
            $early->factId => ['key' => 'a1c', 'label' => 'A1c'],
            $late->factId => ['key' => 'a1c', 'label' => 'A1c'],
        ];

        $facts = [$early, $late, self::weightFact('2026-06-01', 9, 175.0)];
        $viewModel = DocViewModel::build(self::servedResult($facts, null), 'https://example.test', $map);

        $labGroup = self::group($viewModel, 'lab:a1c');
        self::assertNotNull($labGroup);
        self::assertNotNull($labGroup['sparkline']);
        self::assertCount(2, $labGroup['sparkline']['points'], 'one sparkline point per draw');
        self::assertSame(
            '2026-06-01: 7.2 %',
            $labGroup['sparkline']['points'][1]['label'],
            'points are chronological -- the newest draw plots last even though the table lists it first'
        );
        self::assertTrue($labGroup['sparkline']['points'][1]['latest']);

        $vitalsGroup = self::group($viewModel, 'vitals_trend');
        self::assertNotNull($vitalsGroup);
        self::assertNull($vitalsGroup['sparkline'], 'only consolidated lab groups render the widget');
    }

    public function testWithoutAnAnalyteMapLabsStayGroupedByCapability(): void
    {
        // Back-compat: no map => the trend labs remain a single control_proxy
        // group (the caller simply did not resolve analytes).
        $viewModel = DocViewModel::build(self::servedResult([self::trendPointFact()], null), 'https://example.test');

        self::assertNotNull(self::group($viewModel, 'control_proxy'));
        self::assertNull(self::group($viewModel, 'lab:a1c'));
    }

    public function testRecentNarrativesExcludesTheCurrentlyServedRowAndSkipsDegradedAttempts(): void
    {
        $fact = self::trendPointFact();
        $claim = new Claim('A1c is stable.', ClaimType::Trend, [$fact->factId], [7.2], [], 0);
        $passedDoc = SynthesisDocPayload::build([$fact], [$claim], VerifyStatus::Passed, null, null, [], 1);
        $degradedDoc = SynthesisDocPayload::build([$fact], null, VerifyStatus::Degraded, 'llm_unavailable', 'no narrative', [], 1);

        $current = self::docRow(3, '2026-07-08 09:00:00', $passedDoc);
        $degraded = self::docRow(2, '2026-07-05 09:00:00', $degradedDoc);
        $past = self::docRow(1, '2026-07-01 09:00:00', $passedDoc);

        $recent = DocViewModel::recentNarratives([$current, $degraded, $past], 'https://example.test', excludeDocId: 3);

        self::assertCount(1, $recent, 'the served row is excluded and the degraded attempt has no claims to show');
        self::assertSame(1, $recent[0]['id']);
        self::assertSame('A1c is stable.', $recent[0]['narrative'][0]['text']);
    }

    public function testRecentNarrativesLimitsToRequestedCount(): void
    {
        $fact = self::trendPointFact();
        $claim = new Claim('note', ClaimType::Trend, [], [], [], 0);
        $doc = SynthesisDocPayload::build([$fact], [$claim], VerifyStatus::Passed, null, null, [], 1);

        $history = [
            self::docRow(4, '2026-07-08 09:00:00', $doc),
            self::docRow(3, '2026-07-07 09:00:00', $doc),
            self::docRow(2, '2026-07-06 09:00:00', $doc),
            self::docRow(1, '2026-07-05 09:00:00', $doc),
        ];

        $recent = DocViewModel::recentNarratives($history, 'https://example.test', excludeDocId: null, limit: 3);

        self::assertSame([4, 3, 2], array_column($recent, 'id'));
    }

    public function testVisitRowsSummarizesEachHistoryRowWithoutFullyDecodingFacts(): void
    {
        $fact = self::trendPointFact();
        $claimA = new Claim('a', ClaimType::Trend, [], [], [], 0);
        $claimB = new Claim('b', ClaimType::Trend, [], [], [], 1);
        $twoClaimDoc = SynthesisDocPayload::build([$fact], [$claimA, $claimB], VerifyStatus::Passed, null, null, [], 1);
        $degradedDoc = SynthesisDocPayload::build([$fact], null, VerifyStatus::Degraded, 'llm_unavailable', 'no narrative', [], 1);

        $history = [
            self::docRow(2, '2026-07-08 09:00:00', $twoClaimDoc, apptId: 501, verifyStatus: VerifyStatus::Passed),
            self::docRow(1, '2026-07-01 09:00:00', $degradedDoc, apptId: null, verifyStatus: VerifyStatus::Degraded),
        ];

        $rows = DocViewModel::visitRows($history);

        // The passed attempt is summarized from the cheap claim count; the
        // degraded attempt (no served narrative) is hidden from history.
        self::assertCount(1, $rows);
        self::assertSame(
            ['id' => 2, 'computed_at' => '2026-07-08 09:00', 'appt_id' => 501, 'verify_status' => 'passed', 'claim_count' => 2],
            $rows[0],
        );
    }

    public function testVisitRowsExcludesTheCurrentlyServedRow(): void
    {
        $fact = self::trendPointFact();
        $doc = SynthesisDocPayload::build([$fact], null, VerifyStatus::Degraded, 'llm_unavailable', 'no narrative', [], 1);

        $history = [
            self::docRow(2, '2026-07-08 09:00:00', $doc),
            self::docRow(1, '2026-07-01 09:00:00', $doc),
        ];

        $rows = DocViewModel::visitRows($history, excludeDocId: 2);

        self::assertSame([1], array_column($rows, 'id'));
    }

    public function testVarianceRowsKeepsSameDayWeightAndBmiDeltasSeparate(): void
    {
        // Same visit (same form_vitals row date) records both weight and BMI
        // -- the exact shape that would collide in consolidateLabRows' bare
        // date-keyed lookup if the two series were not isolated first.
        $weightEarly = self::weightFact('2026-01-01', 1, 180.0);
        $weightLate = self::weightFact('2026-06-01', 2, 175.0);
        $bmiEarly = self::bmiFact('2026-01-01', 1, 28.0);
        $bmiLate = self::bmiFact('2026-06-01', 2, 26.0);

        $weightDeltas = DerivedFacts::deltas(Capability::VitalsTrend, '1', [$weightEarly, $weightLate]);
        $bmiDeltas = DerivedFacts::deltas(Capability::VitalsTrend, '1', [$bmiEarly, $bmiLate]);

        $facts = [$weightEarly, $weightLate, $bmiEarly, $bmiLate, ...$weightDeltas, ...$bmiDeltas];

        $rows = DocViewModel::varianceRows([], $facts, 'https://example.test', []);

        $weightRow = self::rowFor($rows, 'Weight', '2026-06-01');
        $bmiRow = self::rowFor($rows, 'BMI', '2026-06-01');

        self::assertSame('-5.00 lb', $weightRow['change_display'], 'the weight delta must never pick up the same-day BMI delta');
        self::assertSame('-2.00', $bmiRow['change_display'], 'the BMI delta must never pick up the same-day weight delta');
    }

    public function testVarianceRowsShowsBloodPressureRowsWithNoChangeColumn(): void
    {
        $bp = self::bpFact('2026-06-01', 1, '132', '84');

        $rows = DocViewModel::varianceRows([], [$bp], 'https://example.test', []);

        self::assertCount(1, $rows);
        self::assertSame('Blood pressure', $rows[0]['group_label']);
        self::assertNull($rows[0]['change_display'], 'VitalsTrend never computes a delta over a composite BP reading');
        self::assertSame('132/84', $rows[0]['raw']);
    }

    public function testVarianceRowsSplitsLabAnalytesSoSameDayDeltasNeverCollide(): void
    {
        $a1cEarly = self::trendPointOn('2026-01-01', 1, 6.9);
        $a1cLate = self::trendPointOn('2026-06-01', 2, 7.2);
        $ldlEarly = self::trendPointOn('2026-01-01', 3, 110.0);
        $ldlLate = self::trendPointOn('2026-06-01', 4, 90.0);
        $a1cDelta = self::derivedOn('2026-06-01', FactKind::DerivedDelta, '+0.30', 0.3, '%');
        $ldlDelta = self::derivedOn('2026-06-01', FactKind::DerivedDelta, '-20.00', -20.0, '');

        $map = [
            $a1cEarly->factId => ['key' => 'a1c', 'label' => 'A1c'],
            $a1cLate->factId => ['key' => 'a1c', 'label' => 'A1c'],
            $a1cDelta->factId => ['key' => 'a1c', 'label' => 'A1c'],
            $ldlEarly->factId => ['key' => 'ldl', 'label' => 'LDL Cholesterol'],
            $ldlLate->factId => ['key' => 'ldl', 'label' => 'LDL Cholesterol'],
            $ldlDelta->factId => ['key' => 'ldl', 'label' => 'LDL Cholesterol'],
        ];
        $facts = [$a1cEarly, $a1cLate, $ldlEarly, $ldlLate, $a1cDelta, $ldlDelta];

        $viewModel = DocViewModel::build(self::servedResult($facts, null), 'https://example.test', $map);
        $rows = DocViewModel::varianceRows($viewModel['fact_groups'], $facts, 'https://example.test', $map);

        self::assertSame('+0.30 %', self::rowFor($rows, 'A1c', '2026-06-01')['change_display']);
        self::assertSame('-20.00', self::rowFor($rows, 'LDL Cholesterol', '2026-06-01')['change_display']);
    }

    public function testVarianceRowsNeverIncludesExcludedLabFacts(): void
    {
        // Sourced from the already-consolidated `lab:*` fact_groups (see
        // buildFactGroups' own exclusion filter), so an excluded/invalid lab
        // reading must never surface here looking like a valid trend point --
        // the same guarantee testExclusionRoutesToExclusionsBucketOnly()
        // checks for the Chart Facts tab.
        $exclusion = self::exclusionFact();

        $viewModel = DocViewModel::build(self::servedResult([$exclusion], null), 'https://example.test');
        $rows = DocViewModel::varianceRows($viewModel['fact_groups'], [$exclusion], 'https://example.test', []);

        self::assertSame([], $rows);
    }

    public function testVarianceRowsSortsMostRecentFirstAcrossLabsAndVitals(): void
    {
        $lab = self::trendPointOn('2026-03-01', 1, 7.0);
        $weight = self::weightFact('2026-06-01', 2, 180.0);
        $bp = self::bpFact('2026-01-01', 3, '120', '80');
        $map = [$lab->factId => ['key' => 'a1c', 'label' => 'A1c']];
        $facts = [$lab, $weight, $bp];

        $viewModel = DocViewModel::build(self::servedResult($facts, null), 'https://example.test', $map);
        $rows = DocViewModel::varianceRows($viewModel['fact_groups'], $facts, 'https://example.test', $map);

        self::assertSame(['2026-06-01', '2026-03-01', '2026-01-01'], array_column($rows, 'clinical_date'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private static function rowFor(array $rows, string $groupLabel, string $clinicalDate): array
    {
        foreach ($rows as $row) {
            if ($row['group_label'] === $groupLabel && $row['clinical_date'] === $clinicalDate) {
                return $row;
            }
        }

        self::fail("no variance row found for group '{$groupLabel}' on {$clinicalDate}");
    }

    /**
     * @param array<string, mixed> $doc a SynthesisDocPayload::build() array
     */
    private static function docRow(
        int $id,
        string $computedAt,
        array $doc,
        ?int $apptId = null,
        VerifyStatus $verifyStatus = VerifyStatus::Passed,
    ): DocRow {
        return new DocRow(
            $id,
            42,
            'digest-' . $id,
            'pre_visit',
            $apptId,
            $doc,
            [],
            'v1',
            new \DateTimeImmutable($computedAt),
            'corr-' . $id,
            null,
            null,
            null,
            null,
            null,
            QaStatus::Pending,
            null,
            RegenReason::None,
            $verifyStatus,
        );
    }

    private static function weightFact(string $date, int $formVitalsId, float $value): Fact
    {
        return self::numericVitalFact($date, $formVitalsId, 'weight', $value, 'lb');
    }

    private static function bmiFact(string $date, int $formVitalsId, float $value): Fact
    {
        return self::numericVitalFact($date, $formVitalsId, 'BMI', $value, '');
    }

    private static function numericVitalFact(string $date, int $formVitalsId, string $field, float $value, string $unit): Fact
    {
        $citations = [new Citation('form_vitals', $formVitalsId, $field, DateSource::Collected)];
        $factValue = new FactValue((string) $value, $value, Comparator::None, $unit, $unit !== '' ? $unit : null, null);
        $factId = FactId::compute(Capability::VitalsTrend, FactKind::Vital, $citations, $factValue);

        return new Fact(
            $factId,
            Capability::VitalsTrend,
            '1',
            FactKind::Vital,
            42,
            new \DateTimeImmutable($date),
            DateSource::Collected,
            $factValue,
            FactStatus::Final,
            [],
            $citations,
        );
    }

    private static function bpFact(string $date, int $formVitalsId, string $bps, string $bpd): Fact
    {
        $citations = [
            new Citation('form_vitals', $formVitalsId, 'bps', DateSource::Collected),
            new Citation('form_vitals', $formVitalsId, 'bpd', DateSource::Collected),
        ];
        $value = new FactValue("{$bps}/{$bpd}", null, Comparator::None, 'mmHg', 'mmHg', null);
        $factId = FactId::compute(Capability::VitalsTrend, FactKind::Vital, $citations, $value);

        return new Fact(
            $factId,
            Capability::VitalsTrend,
            '1',
            FactKind::Vital,
            42,
            new \DateTimeImmutable($date),
            DateSource::Collected,
            $value,
            FactStatus::Final,
            [],
            $citations,
        );
    }

    /**
     * @param array{narrative: mixed, fact_groups: list<array{key: string, label: string, total: int, shown: int, consolidated: bool, summary: array<string, mixed>|null, sparkline: array<string, mixed>|null, facts: list<array<string, mixed>>}>} $viewModel
     * @return array{key: string, label: string, total: int, shown: int, consolidated: bool, summary: array<string, mixed>|null, sparkline: array<string, mixed>|null, facts: list<array<string, mixed>>}|null
     */
    private static function group(array $viewModel, string $key): ?array
    {
        foreach ($viewModel['fact_groups'] as $group) {
            if ($group['key'] === $key) {
                return $group;
            }
        }

        return null;
    }

    private static function trendPointOn(string $date, int $pk, float $value): Fact
    {
        $citations = [new Citation('procedure_result', $pk, 'result', DateSource::Collected)];
        $factValue = new FactValue((string) $value, $value, Comparator::None, '%', '%', null);
        $factId = FactId::compute(Capability::ControlProxy, FactKind::TrendPoint, $citations, $factValue);

        return new Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            FactKind::TrendPoint,
            42,
            new \DateTimeImmutable($date),
            DateSource::Collected,
            $factValue,
            FactStatus::Final,
            [],
            $citations,
        );
    }

    private static function derivedOn(string $date, FactKind $kind, string $raw, float $parsed, string $unit): Fact
    {
        $citations = [
            new Citation('procedure_result', 1, 'result', DateSource::Collected),
            new Citation('procedure_result', 2, 'result', DateSource::Collected),
        ];
        $unitCanonical = $unit !== '' ? $unit : null;
        $factValue = new FactValue($raw, $parsed, Comparator::None, $unit, $unitCanonical, null);
        $factId = FactId::compute(Capability::ControlProxy, $kind, $citations, $factValue);

        return new Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            $kind,
            42,
            new \DateTimeImmutable($date),
            DateSource::Collected,
            $factValue,
            FactStatus::Final,
            [],
            $citations,
        );
    }

    /**
     * @param list<Fact> $facts
     * @param list<Claim>|null $claims
     */
    private static function servedResult(array $facts, ?array $claims): SynthesisReadResult
    {
        return SynthesisReadResult::served(
            'corr-1',
            42,
            $facts,
            'digest-abc',
            VerifyStatus::Passed,
            RegenReason::None,
            $claims,
            null,
            null,
            [],
            1,
            true,
            new \DateTimeImmutable('2026-07-07 08:00:00'),
            QaStatus::Pending,
            null,
            1,
        );
    }

    private static function trendPointFact(): Fact
    {
        $citations = [new Citation('procedure_result', 501, 'result', DateSource::Collected)];
        $value = new FactValue('7.2', 7.2, Comparator::None, '%', '%', null);
        $factId = FactId::compute(Capability::ControlProxy, FactKind::TrendPoint, $citations, $value);

        return new Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            FactKind::TrendPoint,
            42,
            new \DateTimeImmutable('2026-06-01'),
            DateSource::Collected,
            $value,
            FactStatus::Final,
            [],
            $citations,
        );
    }

    private static function preliminaryResultFact(): Fact
    {
        $citations = [new Citation('procedure_result', 502, 'result', DateSource::Collected)];
        $value = new FactValue('8.1', 8.1, Comparator::None, '%', '%', null);
        $factId = FactId::compute(Capability::PendingResults, FactKind::PreliminaryResult, $citations, $value);

        return new Fact(
            $factId,
            Capability::PendingResults,
            '1',
            FactKind::PreliminaryResult,
            42,
            new \DateTimeImmutable('2026-07-01'),
            DateSource::Collected,
            $value,
            FactStatus::Preliminary,
            [],
            $citations,
        );
    }

    private static function pendingOrderFact(): Fact
    {
        $citations = [new Citation('procedure_order', 503, null, DateSource::Collected)];
        $factId = FactId::compute(Capability::PendingResults, FactKind::PendingOrder, $citations, null);

        return new Fact(
            $factId,
            Capability::PendingResults,
            '1',
            FactKind::PendingOrder,
            42,
            new \DateTimeImmutable('2026-07-02'),
            DateSource::Collected,
            null,
            FactStatus::Unstated,
            [],
            $citations,
        );
    }

    private static function exclusionFact(): Fact
    {
        $citations = [new Citation('procedure_result', 504, 'result_status', DateSource::Collected)];
        $value = new FactValue('pending', null, Comparator::None, '', null, null);
        $factId = FactId::compute(Capability::ControlProxy, FactKind::Exclusion, $citations, $value);

        return new Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            FactKind::Exclusion,
            42,
            new \DateTimeImmutable('2026-06-15'),
            DateSource::Collected,
            $value,
            FactStatus::Excluded,
            [Flag::excludedReason(ExclusionReason::UnresultedStatus)],
            $citations,
        );
    }
}
