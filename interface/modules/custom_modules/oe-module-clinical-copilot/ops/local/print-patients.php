<?php

/**
 * Prints the seeded Clinical Co-Pilot demo patients and their doc-page URLs.
 * Runs inside the openemr container (bootstraps globals for DB access).
 * Invoked by ops/local/setup.sh after seeding.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$ignoreAuth = true;
$_GET['site'] = $_GET['site'] ?? 'default';
require_once __DIR__ . '/../../../../../globals.php';

use OpenEMR\Common\Database\QueryUtils;

$base = '/interface/modules/custom_modules/oe-module-clinical-copilot/public';
$rows = QueryUtils::fetchRecords(
    "SELECT `pid`, `pubpid`, `fname`, `lname` FROM `patient_data` WHERE `pubpid` LIKE 'CCP-%' ORDER BY `pubpid`"
);

if ($rows === []) {
    echo "  (no seeded CCP-* patients found)\n";
    exit(0);
}

echo "  Seeded demo patients (open the synthesis doc page for each):\n";
foreach ($rows as $r) {
    $pid = (int) $r['pid'];
    $label = trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? ''));
    echo sprintf("    - %s  %-22s  https://localhost:9300%s/doc.php?pid=%d\n", $r['pubpid'], $label, $base, $pid);
}
echo "  (chat panel + observability dashboard are linked from the doc page / Modules menu)\n";
