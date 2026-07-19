<?php

/**
 * Resolves the drug name each medication Fact names, for the reduce prompt.
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
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

/**
 * A `med_event` Fact carries `value: null` and only cites the source row
 * (`prescriptions.drug` for in-house orders, `lists.title` for outside /
 * reconciled meds) -- the drug name is dropped after extraction because, like
 * the analyte on a lab Fact, it is not part of fact identity. So the canonical
 * JSON handed to the model names a date but not a drug, and the narrative can
 * only say "a medication was prescribed on X".
 *
 * This is the small, read-only DB hop that recovers the drug name for the
 * prompt (mirroring {@see FactAnalyteResolver} for labs): it resolves each
 * medication Fact's name from the row it cites, in two bounded `IN (...)`
 * queries over the pks already present in the fact set, and returns a
 * `fact_id => {key: 'medication', label: <drug>}` map shaped to merge directly
 * with {@see FactAnalyteResolver::labelByFactId()}'s output.
 */
final class MedNameResolver
{
    private const MEDICATION_KEY = 'medication';

    /**
     * @param list<Fact> $facts
     * @return array<string, array{key: string, label: string}> fact_id => {key: 'medication', label: drug name}
     */
    public function labelByFactId(array $facts): array
    {
        /** @var array<int, list<string>> $prescriptionPkToFactIds */
        $prescriptionPkToFactIds = [];
        /** @var array<int, list<string>> $listPkToFactIds */
        $listPkToFactIds = [];

        foreach ($facts as $fact) {
            foreach ($fact->citations as $citation) {
                if ($citation->table === 'prescriptions') {
                    $prescriptionPkToFactIds[$citation->pk][] = $fact->factId;
                } elseif ($citation->table === 'lists') {
                    $listPkToFactIds[$citation->pk][] = $fact->factId;
                }
            }
        }

        /** @var array<string, string> $nameByFactId fact_id => drug name (first citation wins) */
        $nameByFactId = [];
        $this->resolveNames(
            $prescriptionPkToFactIds,
            'SELECT `id` AS `pk`, `drug` AS `name` FROM `prescriptions` WHERE `id` IN',
            $nameByFactId,
        );
        $this->resolveNames(
            $listPkToFactIds,
            'SELECT `id` AS `pk`, `title` AS `name` FROM `lists` WHERE `id` IN',
            $nameByFactId,
        );

        $labelByFactId = [];
        foreach ($nameByFactId as $factId => $name) {
            $trimmed = trim($name);
            if ($trimmed !== '') {
                $labelByFactId[$factId] = ['key' => self::MEDICATION_KEY, 'label' => $trimmed];
            }
        }

        return $labelByFactId;
    }

    /**
     * @param array<int, list<string>> $pkToFactIds
     * @param array<string, string> $nameByFactId modified in place: fact_id => drug name
     */
    private function resolveNames(array $pkToFactIds, string $sqlPrefix, array &$nameByFactId): void
    {
        if ($pkToFactIds === []) {
            return;
        }

        $pks = array_keys($pkToFactIds);
        $placeholders = implode(',', array_fill(0, count($pks), '?'));
        $rows = QueryUtils::fetchRecords("{$sqlPrefix} ($placeholders)", $pks);

        foreach ($rows as $row) {
            $pk = (int) $row['pk'];
            $name = (string) $row['name'];
            foreach ($pkToFactIds[$pk] ?? [] as $factId) {
                $nameByFactId[$factId] ??= $name;
            }
        }
    }
}
