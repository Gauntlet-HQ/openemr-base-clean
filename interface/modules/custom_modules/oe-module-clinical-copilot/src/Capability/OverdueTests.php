<?php

/**
 * OverdueTests capability: monitoring gaps per the cadence table.
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
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\LabContractConfigProviderInterface;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use OpenEMR\Modules\ClinicalCopilot\Lab\PendingOrderRow;
use OpenEMR\Modules\ClinicalCopilot\Lab\PresentedLabFact;
use Psr\Clock\ClockInterface;

/**
 * UC1, UC4 ("What's overdue -- and what's already handled?").
 *
 * Code set: {@see AnalyteCodeSets::overdueCodes()} -- every LOINC code that
 * has a `mod_copilot_cadence` `cadence:*` row (ACR, A1c, the lipid panel).
 * Glucose is excluded (no cadence row -- see that method's docblock).
 *
 * Slice: U4's {@see LabSliceReader}, one `read()` per code (same reasoning
 * as {@see ControlProxy}: per-code series identity has to come from the call
 * boundary). Threshold here is the cadence interval, not a numeric
 * threshold: `mod_copilot_cadence`'s `cadenceIntervalByLoinc`/
 * `cadenceVersionByLoinc` (via the injected
 * {@see LabContractConfigProviderInterface}, the same config seam U4 uses).
 *
 * Invariant (ARCHITECTURE_COMPLETE.md): "overdue only if last-draw + interval
 * prove it." Concretely: among a code's presented, non-excluded rows, the
 * last row whose status RESETS the clock (`resetsClock`, C2: final/
 * corrected/unstated -- never preliminary or excluded) is the proof; its
 * `clinical_date + interval` is the due date. If there is no such row at
 * all, OverdueTests cannot construct the proof and emits NOTHING for that
 * code -- this is not a silent exclusion (I5 governs rows a slice read
 * filtered; here there is no row to filter, just insufficient evidence to
 * derive a claim from).
 *
 * Composes with PendingResults (reorder suppression): when a code is
 * overdue, this capability separately checks
 * {@see LabSliceReader::readPendingOrders()} for an ACTIVE order on that
 * same code. If one exists, its citation is ADDED to the `overdue_item`
 * Fact's citations (alongside the last-draw citation) -- this is the "cite
 * BOTH sides" mechanism (Fact schema's `citations` is a list; nothing about
 * the schema restricts it to one physical table). A downstream narrator can
 * therefore only write "do not reorder" when the `overdue_item` Fact itself
 * carries a `procedure_order` citation proving one is active; absent that,
 * the Fact carries only the lab-result citation and no suppression claim is
 * citable. No new Flag/FactKind is needed for this -- FactKind is a closed
 * enum (ARCHITECTURE_COMPLETE.md "Fact object") and citations already carry
 * exactly the provenance a suppression claim needs.
 */
final class OverdueTests implements CapabilityInterface
{
    private const CAPABILITY = Capability::OverdueTests;
    private const CAPABILITY_VERSION = '1';

    /** @var list<string> */
    private const TERMINAL_ORDER_STATUSES = ['canceled', 'cancelled'];

    public function __construct(
        private readonly LabSliceReader $labSliceReader,
        private readonly LabContractConfigProviderInterface $configProvider,
        private readonly ClockInterface $clock,
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
        $config = $this->configProvider->load();
        $now = $this->clock->now();

        $presented = [];
        $exclusions = [];
        $rawInputCount = 0;
        $accountedCount = 0;

        foreach (AnalyteCodeSets::overdueCodes() as $loincCode) {
            $interval = $config->cadenceIntervalByLoinc[$loincCode] ?? null;
            if ($interval === null) {
                continue;
            }

            $slice = $this->labSliceReader->read($pid, [$loincCode], self::CAPABILITY, self::CAPABILITY_VERSION);
            $exclusions = [...$exclusions, ...$slice->exclusions];
            // I14: scoped to this capability's own lab-slice reads only --
            // the pending-order lookup a few lines below is a side query for
            // reorder-suppression evidence, not a source PendingResults'
            // OWN conservation accounting; double-counting it here would
            // make the same raw row contribute to two different
            // capabilities' rawInputCount for two different reasons.
            $rawInputCount += $slice->rawInputCount;
            $accountedCount += $slice->accountedCount;

            $lastDraw = self::findLastClockResetting($slice->presented);
            if ($lastDraw === null || $lastDraw->clinicalDate === null) {
                continue;
            }

            $dueDate = $lastDraw->clinicalDate->add(new \DateInterval($interval));
            if ($dueDate >= $now) {
                continue;
            }

            $pendingOrder = self::findActivePendingOrder($this->labSliceReader->readPendingOrders($pid, [$loincCode]));

            $presented[] = $this->buildOverdueItem($pid, $lastDraw, $pendingOrder);
        }

        return new CapabilityResult($presented, $exclusions, $rawInputCount, $accountedCount);
    }

    /**
     * @param list<PresentedLabFact> $presentedFacts
     */
    private static function findLastClockResetting(array $presentedFacts): ?Fact
    {
        $best = null;
        foreach ($presentedFacts as $presentedLabFact) {
            if (!$presentedLabFact->resetsClock || $presentedLabFact->fact->clinicalDate === null) {
                continue;
            }

            if ($best === null || $presentedLabFact->fact->clinicalDate > $best->clinicalDate) {
                $best = $presentedLabFact->fact;
            }
        }

        return $best;
    }

    /**
     * @param list<PendingOrderRow> $pendingOrders
     */
    private static function findActivePendingOrder(array $pendingOrders): ?PendingOrderRow
    {
        foreach ($pendingOrders as $order) {
            $status = strtolower(trim($order->orderStatus));
            if (!in_array($status, self::TERMINAL_ORDER_STATUSES, true)) {
                return $order;
            }
        }

        return null;
    }

    private function buildOverdueItem(int $pid, Fact $lastDraw, ?PendingOrderRow $pendingOrder): Fact
    {
        $citations = $lastDraw->citations;
        if ($pendingOrder !== null) {
            $dateSource = $pendingOrder->dateCollected !== null ? DateSource::Collected : DateSource::Fallback;
            $citations = [...$citations, new Citation('procedure_order', $pendingOrder->procedureOrderId, 'order_status', $dateSource)];
        }

        $factId = FactId::compute(self::CAPABILITY, FactKind::OverdueItem, $citations, null);

        return new Fact(
            $factId,
            self::CAPABILITY,
            self::CAPABILITY_VERSION,
            FactKind::OverdueItem,
            $pid,
            $lastDraw->clinicalDate,
            $lastDraw->dateSource,
            null,
            $lastDraw->status,
            [],
            $citations,
        );
    }
}
