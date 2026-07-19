<?php

/**
 * Resolves the analyte (lab type) each lab Fact belongs to, for the Chart Facts panel.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\AnalyteCodeSets;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

/**
 * A Fact carries its capability and value but NOT which analyte it is -- the
 * LOINC is dropped after extraction ({@see \OpenEMR\Modules\ClinicalCopilot\Lab\LabRowProcessor}
 * knows it, the Fact does not, because analyte is not part of fact identity).
 * That is fine for verification/digest but leaves the Chart Facts panel unable
 * to tell A1c (%) from glucose/LDL/HDL (mg/dL), so a single "labs" group mixes
 * units and is hard to read.
 *
 * This is the ONE small, read-only DB hop that recovers the analyte for
 * display: it resolves each lab Fact's LOINC from the row it cites
 * (`procedure_result.result_code`, or `procedure_order_code.procedure_code`
 * for a drawn-but-unresulted order) in two bounded `IN (...)` queries over the
 * pks already present in the current fact set, then maps the LOINC to a
 * display key + label via {@see AnalyteCodeSets}. Kept out of the pure
 * {@see DocViewModel} so that presenter stays DB-free; the resulting map is
 * passed in.
 */
final class FactAnalyteResolver
{
    /**
     * @param list<Fact> $facts
     * @return array<string, array{key: string, label: string}> fact_id => analyte (only lab facts appear)
     */
    public function labelByFactId(array $facts): array
    {
        /** @var array<int, list<string>> $resultPkToFactIds */
        $resultPkToFactIds = [];
        /** @var array<int, list<string>> $orderPkToFactIds */
        $orderPkToFactIds = [];

        foreach ($facts as $fact) {
            foreach ($fact->citations as $citation) {
                if ($citation->table === 'procedure_result') {
                    $resultPkToFactIds[$citation->pk][] = $fact->factId;
                } elseif ($citation->table === 'procedure_order') {
                    $orderPkToFactIds[$citation->pk][] = $fact->factId;
                }
            }
        }

        /** @var array<string, string> $codeByFactId fact_id => LOINC (first citation wins) */
        $codeByFactId = [];
        $this->resolveCodes(
            $resultPkToFactIds,
            'SELECT `procedure_result_id` AS `pk`, `result_code` AS `code` FROM `procedure_result` WHERE `procedure_result_id` IN',
            $codeByFactId,
        );
        $this->resolveCodes(
            $orderPkToFactIds,
            'SELECT `procedure_order_id` AS `pk`, `procedure_code` AS `code` FROM `procedure_order_code` WHERE `procedure_order_id` IN',
            $codeByFactId,
        );

        $labelByFactId = [];
        foreach ($codeByFactId as $factId => $loinc) {
            $key = AnalyteCodeSets::analyteKeyForLoinc($loinc);
            $label = AnalyteCodeSets::analyteLabelForLoinc($loinc);
            if ($key !== null && $label !== null) {
                $labelByFactId[$factId] = ['key' => $key, 'label' => $label];
            }
        }

        return $labelByFactId;
    }

    /**
     * @param array<int, list<string>> $pkToFactIds
     * @param array<string, string> $codeByFactId modified in place: fact_id => LOINC
     */
    private function resolveCodes(array $pkToFactIds, string $sqlPrefix, array &$codeByFactId): void
    {
        if ($pkToFactIds === []) {
            return;
        }

        $pks = array_keys($pkToFactIds);
        $placeholders = implode(',', array_fill(0, count($pks), '?'));
        $rows = QueryUtils::fetchRecords("{$sqlPrefix} ($placeholders)", $pks);

        foreach ($rows as $row) {
            $pk = (int) $row['pk'];
            $code = (string) $row['code'];
            foreach ($pkToFactIds[$pk] ?? [] as $factId) {
                $codeByFactId[$factId] ??= $code;
            }
        }
    }
}
