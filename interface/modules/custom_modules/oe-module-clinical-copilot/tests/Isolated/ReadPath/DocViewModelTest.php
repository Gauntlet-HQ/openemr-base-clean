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

        $inFlightKinds = array_column($viewModel['in_flight'], 'kind');
        self::assertEqualsCanonicalizing(['preliminary_result', 'pending_order'], $inFlightKinds);

        $controlProxyKinds = array_column($viewModel['facts_by_capability']['control_proxy'] ?? [], 'kind');
        self::assertSame(['trend_point'], $controlProxyKinds, 'the trend bucket must contain ONLY the trend_point fact -- never the preliminary result');

        self::assertArrayNotHasKey('pending_results', $viewModel['facts_by_capability'], 'a capability whose only facts are in-flight must not also appear in the plain facts table');
    }

    public function testExclusionRoutesToExclusionsBucketOnly(): void
    {
        $exclusion = self::exclusionFact();

        $result = self::servedResult([$exclusion], null);
        $viewModel = DocViewModel::build($result, 'https://example.test');

        self::assertCount(1, $viewModel['exclusions']);
        self::assertSame([], $viewModel['in_flight']);
        self::assertArrayNotHasKey('control_proxy', $viewModel['facts_by_capability'], 'an excluded fact must never also appear in the plain facts table');
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
        self::assertSame(['trend_point'], array_column($viewModel['facts_by_capability']['control_proxy'], 'kind'));
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
