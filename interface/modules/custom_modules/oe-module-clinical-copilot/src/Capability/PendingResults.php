<?php

/**
 * PendingResults capability: active orders without final results, and preliminary values.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Modules\ClinicalCopilot\Capability\Config\AnalyteCodeSets;
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\LabTurnaroundConfig;
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\LabTurnaroundConfigProviderInterface;
use OpenEMR\Modules\ClinicalCopilot\Capability\Support\FactRekind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use OpenEMR\Modules\ClinicalCopilot\Lab\PendingOrderRow;

/**
 * UC1, UC4, UC5 ("What's in flight?").
 *
 * Code set: {@see AnalyteCodeSets::pendingCodes()} -- the union of every
 * code any other capability monitors (ACR, A1c, glucose, the lipid panel):
 * a drawn-but-unresulted or preliminary result matters for ANY of them, not
 * one analyte domain.
 *
 * Slice: two independent sources per code, both from U4's
 * {@see LabSliceReader}:
 * - {@see LabSliceReader::readPendingOrders()} -- an active `procedure_order`
 *   (activity=1) with NO `procedure_report` row at all (T10:
 *   drawn-but-unresulted). Becomes a `pending_order` Fact. A terminal
 *   `order_status` (canceled/cancelled) is excluded from presentation: an
 *   explicitly cancelled order proves nothing is actually in flight, so no
 *   reorder-suppression claim would be honest.
 * - {@see LabSliceReader::read()} -- rows U4 marks `inFlight` (C2:
 *   preliminary) are re-kinded (via {@see FactRekind}, preserving value/
 *   status/citations/date unchanged) into `preliminary_result`.
 *
 * Also emits `expected_result_date` -- a derived Fact from the versioned
 * `mod_copilot_cadence` `lab_turnaround` config (via the injected
 * {@see LabTurnaroundConfigProviderInterface}), citing the same pending
 * order it is estimating a date for.
 *
 * Invariant: never counts as a result (both `pending_order` and
 * `preliminary_result` are distinct FactKinds from `result`/`trend_point` --
 * ControlProxy never emits either), never resets the OverdueTests clock
 * (OverdueTests only ever reads `resetsClock`-true rows from `read()`,
 * which is false for both of these; readPendingOrders() rows are structurally
 * invisible to OverdueTests' own read() call since they have no
 * `procedure_report` row to join through, T10).
 */
final class PendingResults implements CapabilityInterface
{
    private const CAPABILITY = Capability::PendingResults;
    private const CAPABILITY_VERSION = '1';

    /** @var list<string> */
    private const TERMINAL_ORDER_STATUSES = ['canceled', 'cancelled'];

    public function __construct(
        private readonly LabSliceReader $labSliceReader,
        private readonly LabTurnaroundConfigProviderInterface $turnaroundConfigProvider,
    ) {
    }

    public function capability(): Capability
    {
        return self::CAPABILITY;
    }

    public function capabilityVersion(): string
    {
        return self::CAPABILITY_VERSION;
    }

    public function extract(int $pid): CapabilityResult
    {
        $turnaround = $this->turnaroundConfigProvider->load();

        $presented = [];
        $exclusions = [];
        $rawInputCount = 0;
        $accountedCount = 0;

        foreach (AnalyteCodeSets::pendingCodes() as $loincCode) {
            $pendingOrders = $this->labSliceReader->readPendingOrders($pid, [$loincCode]);
            // I14: every pending-order row this loop sees must end up EITHER
            // a `pending_order` Fact (+ its `expected_result_date`) OR a
            // visible `exclusion` Fact (terminal status) -- never a bare
            // `continue` with no accounting, which is exactly the "row
            // vanished before classification" regression I14 guards against.
            $rawInputCount += count($pendingOrders);

            foreach ($pendingOrders as $order) {
                if (self::isTerminalStatus($order->orderStatus)) {
                    $exclusions[] = $this->buildTerminalOrderExclusion($pid, $order);
                    $accountedCount++;
                    continue;
                }

                $presented[] = $this->buildPendingOrder($pid, $order);

                $expected = $this->buildExpectedResultDate($pid, $order, $loincCode, $turnaround);
                if ($expected !== null) {
                    $presented[] = $expected;
                }
                $accountedCount++;
            }

            $slice = $this->labSliceReader->read($pid, [$loincCode], self::CAPABILITY, self::CAPABILITY_VERSION);
            $exclusions = [...$exclusions, ...$slice->exclusions];
            $rawInputCount += $slice->rawInputCount;
            $accountedCount += $slice->accountedCount;

            foreach ($slice->presented as $presentedLabFact) {
                if ($presentedLabFact->inFlight) {
                    $presented[] = FactRekind::withKind($presentedLabFact->fact, FactKind::PreliminaryResult);
                }
            }
        }

        return new CapabilityResult($presented, $exclusions, $rawInputCount, $accountedCount);
    }

    private static function isTerminalStatus(string $orderStatus): bool
    {
        return in_array(strtolower(trim($orderStatus)), self::TERMINAL_ORDER_STATUSES, true);
    }

    /**
     * I5/I14: an explicitly cancelled order proves nothing is actually in
     * flight, so it is never presented as a `pending_order` -- but it must
     * still be a VISIBLE, accounted exclusion (never a bare skip). Reuses
     * `ExclusionReason::UnresultedStatus` (the same reason U4 uses for
     * unperformed lab statuses like "cannot be done") -- a cancelled order
     * is the order-level analogue of the same idea: a status that proves
     * the order can no longer produce a result.
     */
    private function buildTerminalOrderExclusion(int $pid, PendingOrderRow $order): Fact
    {
        $dateSource = $order->dateCollected !== null ? DateSource::Collected : DateSource::Fallback;
        $citations = [new Citation('procedure_order', $order->procedureOrderId, 'order_status', $dateSource)];
        $factId = FactId::compute(self::CAPABILITY, FactKind::Exclusion, $citations, null);

        return new Fact(
            $factId,
            self::CAPABILITY,
            self::CAPABILITY_VERSION,
            FactKind::Exclusion,
            $pid,
            $order->dateCollected ?? $order->dateOrdered,
            $dateSource,
            null,
            FactStatus::Excluded,
            [Flag::excludedReason(ExclusionReason::UnresultedStatus)],
            $citations,
        );
    }

    private function buildPendingOrder(int $pid, PendingOrderRow $order): Fact
    {
        $clinicalDate = $order->dateCollected ?? $order->dateOrdered;
        $dateSource = $order->dateCollected !== null ? DateSource::Collected : DateSource::Fallback;
        $citations = [new Citation('procedure_order', $order->procedureOrderId, 'order_status', $dateSource)];
        $factId = FactId::compute(self::CAPABILITY, FactKind::PendingOrder, $citations, null);

        return new Fact(
            $factId,
            self::CAPABILITY,
            self::CAPABILITY_VERSION,
            FactKind::PendingOrder,
            $pid,
            $clinicalDate,
            $dateSource,
            null,
            FactStatus::Unstated,
            [],
            $citations,
        );
    }

    private function buildExpectedResultDate(
        int $pid,
        PendingOrderRow $order,
        string $loincCode,
        LabTurnaroundConfig $turnaround,
    ): ?Fact {
        $clinicalDate = $order->dateCollected ?? $order->dateOrdered;
        if ($clinicalDate === null) {
            return null;
        }

        $bucket = AnalyteCodeSets::cadenceBucketForLoinc($loincCode);
        $days = $turnaround->daysForBucket($bucket);
        $expectedDate = $clinicalDate->add(new \DateInterval("P{$days}D"));
        $dateSource = $order->dateCollected !== null ? DateSource::Collected : DateSource::Fallback;
        $citations = [new Citation('procedure_order', $order->procedureOrderId, 'order_status', $dateSource)];
        $factId = FactId::compute(self::CAPABILITY, FactKind::ExpectedResultDate, $citations, null);

        return new Fact(
            $factId,
            self::CAPABILITY,
            self::CAPABILITY_VERSION,
            FactKind::ExpectedResultDate,
            $pid,
            $expectedDate,
            $dateSource,
            null,
            FactStatus::Unstated,
            [],
            $citations,
        );
    }
}
