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
        self::assertCount(1, $viewModel['narrative'][0]['citations']);
        self::assertSame('Lab result #501.result', $viewModel['narrative'][0]['citations'][0]['label']);
        // procedure_result has no verified deep-link route (ChartLinkResolver's
        // own documented scope) -- tooltip fallback, url is null.
        self::assertNull($viewModel['narrative'][0]['citations'][0]['url']);
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

    public function testWithoutAnAnalyteMapLabsStayGroupedByCapability(): void
    {
        // Back-compat: no map => the trend labs remain a single control_proxy
        // group (the caller simply did not resolve analytes).
        $viewModel = DocViewModel::build(self::servedResult([self::trendPointFact()], null), 'https://example.test');

        self::assertNotNull(self::group($viewModel, 'control_proxy'));
        self::assertNull(self::group($viewModel, 'lab:a1c'));
    }

    /**
     * @param array{narrative: mixed, fact_groups: list<array{key: string, label: string, total: int, shown: int, facts: list<array<string, mixed>>}>} $viewModel
     * @return array{key: string, label: string, total: int, shown: int, facts: list<array<string, mixed>>}|null
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
