<?php

/**
 * Reads the four direct identifiers ARCHITECTURE.md §4 requires be tokenized before egress.
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
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;

/**
 * The ONLY place this read path reads `patient_data` directly (read-only,
 * I9) -- exclusively to build the four fields {@see PatientIdentifiers}
 * needs so U7's {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor} has
 * something concrete to tokenize before any Vertex call (ARCHITECTURE.md
 * §4). Never used to authorize access to a pid -- that's
 * {@see self::exists()}'s narrower job, called separately by the controller
 * before this class is ever asked for identifiers.
 */
final class PatientIdentifierLookup
{
    /**
     * Existence check only -- deliberately does not decide ACL, only
     * whether `pid` resolves to a real `patient_data` row at all. The
     * controller pairs this with `AclMain::aclCheckCore('patients','med')`
     * (a chart-wide grant in this fork, not a per-patient one -- see the
     * U8 report) before ever calling {@see self::forPid()}.
     */
    public function exists(int $pid): bool
    {
        $row = QueryUtils::fetchSingleValue('SELECT `pid` FROM `patient_data` WHERE `pid` = ?', 'pid', [$pid]);

        return $row !== null;
    }

    public function forPid(int $pid): ?PatientIdentifiers
    {
        $row = QueryUtils::querySingleRow(
            'SELECT `fname`, `mname`, `lname`, `DOB`, `street`, `city`, `state`, `postal_code`, `pubpid`
             FROM `patient_data` WHERE `pid` = ?',
            [$pid],
        );

        if (!is_array($row)) {
            return null;
        }

        $name = TextNormalizer::collapseSpaces(
            (string)($row['fname'] ?? '') . ' ' . (string)($row['mname'] ?? '') . ' ' . (string)($row['lname'] ?? '')
        );

        $mrn = trim((string)($row['pubpid'] ?? ''));
        if ($mrn === '') {
            $mrn = "PID-{$pid}";
        }

        $dob = (string)($row['DOB'] ?? '');

        $cityStateZip = TextNormalizer::collapseSpaces(
            (string)($row['city'] ?? '') . ' ' . (string)($row['state'] ?? '') . ' ' . (string)($row['postal_code'] ?? '')
        );
        $address = trim(implode(', ', array_filter([trim((string)($row['street'] ?? '')), $cityStateZip])));

        return new PatientIdentifiers($name, $mrn, $dob, $address);
    }
}
