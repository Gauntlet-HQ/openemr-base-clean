<?php

/**
 * Builds a LabTurnaroundConfig from `mod_copilot_cadence`'s `lab_turnaround` row.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability\Config;

use OpenEMR\Common\Database\QueryUtils;

final class DbLabTurnaroundConfigProvider implements LabTurnaroundConfigProviderInterface
{
    private const CODE_SET = 'lab_turnaround';
    private const FALLBACK_DEFAULT_DAYS = 3;
    private const FALLBACK_VERSION = 'unversioned';

    public function load(): LabTurnaroundConfig
    {
        $row = QueryUtils::querySingleRow(
            'SELECT `config_json`, `version` FROM `mod_copilot_cadence` WHERE `code_set` = ?',
            [self::CODE_SET],
        );

        if (!is_array($row)) {
            // Defensive fallback only -- table.sql seeds this row (U1); a
            // missing row means the module install is incomplete, not that
            // PendingResults should crash mid-extraction (I7-adjacent: a
            // config gap degrades the expected_result_date estimate, it must
            // never abort the whole capability).
            return new LabTurnaroundConfig(self::FALLBACK_DEFAULT_DAYS, [], self::FALLBACK_VERSION);
        }

        $decoded = $row['config_json'] !== null ? json_decode((string)$row['config_json'], true) : null;

        $defaultDays = self::FALLBACK_DEFAULT_DAYS;
        if (is_array($decoded) && is_numeric($decoded['default_days'] ?? null)) {
            $defaultDays = (int)$decoded['default_days'];
        }

        $perAnalyteDays = [];
        if (is_array($decoded) && is_array($decoded['per_analyte_days'] ?? null)) {
            foreach ($decoded['per_analyte_days'] as $bucket => $days) {
                if (is_string($bucket) && is_numeric($days)) {
                    $perAnalyteDays[$bucket] = (int)$days;
                }
            }
        }

        return new LabTurnaroundConfig($defaultDays, $perAnalyteDays, (string)$row['version']);
    }
}
