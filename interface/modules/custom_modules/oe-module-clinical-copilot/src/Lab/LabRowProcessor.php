<?php

/**
 * The pure engine implementing the full lab contract (C1-C4) + exclusion accounting (I5).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\LabContractConfig;

/**
 * Pure and DB-independent: takes in-memory {@see RawLabRow} rows plus an
 * injected {@see LabContractConfig} and returns {@see LabSliceResult}. No
 * QueryUtils call, no clock read, no I/O anywhere in this class -- this is
 * what makes C1-C4 unit-testable without a database ({@see LabSliceReader}
 * is the thin DB-facing orchestrator that feeds this class real rows).
 *
 * Processing order per row: C1 date -> C2 status -> C3 parse -> C4 units ->
 * (if presented) group for supersession -> resolve winners -> attach C3's
 * out-of-range flags to winners. Every row that does not end up presented
 * becomes a visible `exclusion` Fact (I5) -- nothing is ever silently
 * dropped between the raw rows and this result.
 *
 * Key ambiguity resolved here (see the U4 report for the full reasoning):
 * C4's "no unit, no math" is strict for the four analytes it names (A1c,
 * glucose, cholesterol, triglycerides) via {@see LabContractConfig}'s
 * canonical-unit registry. An analyte this registry has no opinion on (e.g.
 * ACR) is NOT subject to conversion-whitelist strictness -- it is presented
 * with its unit verbatim, uncoverted, as long as the unit is non-empty. An
 * EMPTY unit is always excluded regardless of governance: presenting a bare
 * number with no unit at all is exactly the kind of implicit unit-guess this
 * contract forbids, named-analyte or not.
 */
final class LabRowProcessor
{
    private function __construct()
    {
        // static-only
    }

    /**
     * @param list<RawLabRow> $rows
     */
    public static function process(
        array $rows,
        LabContractConfig $config,
        Capability $capability,
        string $capabilityVersion,
    ): LabSliceResult {
        $exclusions = [];
        /** @var array<string, list<array{row: RawLabRow, clinicalDate: ClinicalDate, statusClassification: StatusClassification, parsedValue: ParsedValue, unitConversion: UnitConversionResult}>> $groups */
        $groups = [];

        // I14 (extraction conservation): tallied independently per row, NOT
        // derived from count($rows) -- a future branch that neither excludes
        // nor groups a row would leave $accountedRows short, which is
        // exactly the "silently dropped before classification" regression
        // this counter exists to catch (see LabSliceResult's docblock).
        $accountedRows = 0;

        foreach ($rows as $row) {
            $clinicalDate = ClinicalDateResolver::resolve($row);
            $statusClassification = ResultStatusClassifier::classify($row->resultStatus);
            $parsedValue = ValueParser::parse($row->result, $row->resultDataType);
            $unitConversion = self::resolveUnitConversion($row, $parsedValue, $config);

            if (!$statusClassification->presented) {
                $exclusions[] = self::buildExclusionFact(
                    $row,
                    $clinicalDate,
                    $capability,
                    $capabilityVersion,
                    $parsedValue,
                    $unitConversion,
                    $statusClassification->exclusionReason ?? throw new \LogicException('Excluded StatusClassification must carry a reason'),
                    'result_status',
                );
                $accountedRows++;
                continue;
            }

            if ($unitConversion->excluded) {
                $exclusions[] = self::buildExclusionFact(
                    $row,
                    $clinicalDate,
                    $capability,
                    $capabilityVersion,
                    $parsedValue,
                    $unitConversion,
                    ExclusionReason::Unitless,
                    'units',
                );
                $accountedRows++;
                continue;
            }

            $groupKey = self::groupKey($row->patientId, $row->resultCode, $clinicalDate);
            $groups[$groupKey][] = [
                'row' => $row,
                'clinicalDate' => $clinicalDate,
                'statusClassification' => $statusClassification,
                'parsedValue' => $parsedValue,
                'unitConversion' => $unitConversion,
            ];
            $accountedRows++;
        }

        $presented = [];
        foreach ($groups as $group) {
            $presented[] = self::resolveGroup($group, $config, $capability, $capabilityVersion);
        }

        return new LabSliceResult($presented, $exclusions, count($rows), $accountedRows);
    }

    private static function resolveUnitConversion(RawLabRow $row, ParsedValue $parsedValue, LabContractConfig $config): UnitConversionResult
    {
        if ($row->units === '') {
            // Empty unit is always excluded, regardless of whether this
            // analyte is governed by the C4 whitelist at all.
            return new UnitConversionResult('', null, null, null, true);
        }

        $analyte = $config->analyteForLoinc($row->resultCode);
        if ($analyte === null) {
            // Not governed by C4's conversion whitelist (e.g. ACR): a real,
            // non-empty unit is presented verbatim, uncoverted.
            return new UnitConversionResult($row->units, $row->units, $parsedValue->parsed, null, false);
        }

        return UnitConverter::convert($analyte, $row->units, $parsedValue->parsed, $config);
    }

    /**
     * @param list<array{row: RawLabRow, clinicalDate: ClinicalDate, statusClassification: StatusClassification, parsedValue: ParsedValue, unitConversion: UnitConversionResult}> $group
     */
    private static function resolveGroup(
        array $group,
        LabContractConfig $config,
        Capability $capability,
        string $capabilityVersion,
    ): PresentedLabFact {
        $candidates = array_map(
            static fn (array $entry): SupersessionCandidate => new SupersessionCandidate(
                $entry['row']->procedureResultId,
                $entry['statusClassification']->supersessionRank,
            ),
            $group,
        );
        $supersession = SupersessionResolver::resolve($candidates);

        $winnerEntry = null;
        $entryById = [];
        foreach ($group as $entry) {
            $entryById[$entry['row']->procedureResultId] = $entry;
            if ($entry['row']->procedureResultId === $supersession->winnerProcedureResultId) {
                $winnerEntry = $entry;
            }
        }
        if ($winnerEntry === null) {
            throw new \LogicException('Supersession winner not found among its own group');
        }

        /** @var RawLabRow $winnerRow */
        $winnerRow = $winnerEntry['row'];
        /** @var ClinicalDate $clinicalDate */
        $clinicalDate = $winnerEntry['clinicalDate'];
        /** @var StatusClassification $statusClassification */
        $statusClassification = $winnerEntry['statusClassification'];
        /** @var ParsedValue $parsedValue */
        $parsedValue = $winnerEntry['parsedValue'];
        /** @var UnitConversionResult $unitConversion */
        $unitConversion = $winnerEntry['unitConversion'];

        $citations = [new Citation('procedure_result', $winnerRow->procedureResultId, 'result', $clinicalDate->source)];
        foreach ($supersession->supersededProcedureResultIds as $supersededId) {
            $citations[] = new Citation('procedure_result', $supersededId, 'result', $entryById[$supersededId]['clinicalDate']->source);
        }

        $flags = [];
        if ($supersession->supersededCount() > 0) {
            $flags[] = Flag::supersededCount($supersession->supersededCount());
        }
        if ($parsedValue->comparator->isCensored()) {
            $flags[] = Flag::censored();
        }

        $analyte = $config->analyteForLoinc($winnerRow->resultCode);
        $threshold = $analyte !== null ? $config->thresholdFor($analyte) : null;
        $outOfRange = OutOfRangeEvaluator::evaluate(
            $parsedValue->parsed,
            $parsedValue->comparator,
            $threshold,
            $winnerRow->abnormal,
            $winnerRow->range,
        );
        if ($outOfRange->conflict) {
            $flags[] = Flag::conflict();
        }
        if ($outOfRange->isOutOfRangeByValue()) {
            $flags[] = Flag::outOfRangeByValue();
        }
        if ($outOfRange->isOutOfRangeByLabFlag()) {
            $flags[] = Flag::outOfRangeByLabFlag();
        }

        $value = self::buildFactValue($parsedValue, $unitConversion);
        $factId = FactId::compute($capability, FactKind::Result, $citations, $value);

        $fact = new Fact(
            $factId,
            $capability,
            $capabilityVersion,
            FactKind::Result,
            $winnerRow->patientId,
            $clinicalDate->date,
            $clinicalDate->source,
            $value,
            $statusClassification->factStatus,
            $flags,
            $citations,
        );

        return new PresentedLabFact($fact, $statusClassification->resetsClock, $statusClassification->inFlight, $outOfRange);
    }

    private static function buildFactValue(ParsedValue $parsedValue, UnitConversionResult $unitConversion): FactValue
    {
        if ($unitConversion->excluded) {
            // "No unit, no math": once a unit is unusable, no numeric claim
            // survives, even if the raw text happened to parse.
            return new FactValue($parsedValue->raw, null, Comparator::None, $unitConversion->unitOriginal, null, null);
        }

        return new FactValue(
            $parsedValue->raw,
            $unitConversion->convertedValue,
            $parsedValue->comparator,
            $unitConversion->unitOriginal,
            $unitConversion->unitCanonical,
            $unitConversion->conversionVersion,
        );
    }

    private static function buildExclusionFact(
        RawLabRow $row,
        ClinicalDate $clinicalDate,
        Capability $capability,
        string $capabilityVersion,
        ParsedValue $parsedValue,
        UnitConversionResult $unitConversion,
        ExclusionReason $reason,
        string $citationField,
    ): Fact {
        $citations = [new Citation('procedure_result', $row->procedureResultId, $citationField, $clinicalDate->source)];
        $value = self::buildFactValue($parsedValue, $unitConversion);
        $factId = FactId::compute($capability, FactKind::Exclusion, $citations, $value);

        return new Fact(
            $factId,
            $capability,
            $capabilityVersion,
            FactKind::Exclusion,
            $row->patientId,
            $clinicalDate->date,
            $clinicalDate->source,
            $value,
            FactStatus::Excluded,
            [Flag::excludedReason($reason)],
            $citations,
        );
    }

    private static function groupKey(int $patientId, string $resultCode, ClinicalDate $clinicalDate): string
    {
        $dateKey = $clinicalDate->date?->format('Y-m-d') ?? 'no-date';

        return "{$patientId}|{$resultCode}|{$dateKey}";
    }
}
