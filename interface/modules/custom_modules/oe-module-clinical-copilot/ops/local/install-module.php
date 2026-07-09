<?php

/**
 * Non-interactive installer/enabler for the Clinical Co-Pilot module.
 *
 * Runs INSIDE the openemr container (it bootstraps the host `globals.php`, so it
 * needs the container's DB + vendor/). It replicates what the Module Manager UI
 * does on Register -> Install -> Enable, but headless and idempotent:
 *   1. registers the `modules` row (custom type=0, active) if absent;
 *   2. runs `table.sql` via OpenEMR's directive-aware `upgradeFromSqlFile()`
 *      (honours #IfNotTable/#IfNotRow, so re-running is safe) -- creates the
 *      mod_copilot_* tables and seeds the cadence/threshold/rate-limit config;
 *   3. activates the `clinical_copilot_worker` background_services row.
 *
 * Invoked by ops/local/setup.sh. Safe to re-run.
 *
 * If this ever fails to make the module load, the UI fallback is:
 * Admin -> Modules -> Manage Modules -> register + install + enable
 * "Clinical Co-Pilot" (steps 2-3 above are what that UI performs).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "install-module.php is CLI-only\n");
    exit(1);
}

// Bootstrap OpenEMR (write access needed to create tables / register the module).
$ignoreAuth = true;
$sessionAllowWrite = true;
$_GET['site'] = $_GET['site'] ?? 'default';
require_once __DIR__ . '/../../../../../globals.php';

use OpenEMR\Common\Database\QueryUtils;

$dir = 'oe-module-clinical-copilot';
$moduleFsDir = $GLOBALS['fileroot'] . '/interface/modules/custom_modules/' . $dir;
$workerRequireOnce = '/interface/modules/custom_modules/' . $dir . '/src/worker_entry.php';

echo "== Clinical Co-Pilot module install ==\n";

try {
    // 1. modules row (custom module: type = InstModuleTable::MODULE_TYPE_CUSTOM = 0).
    $existing = QueryUtils::fetchRecords("SELECT mod_id FROM `modules` WHERE `mod_directory` = ?", [$dir]);
    if (count($existing) === 0) {
        QueryUtils::sqlInsert(
            "INSERT INTO `modules`
                SET `mod_name` = ?, `mod_active` = 1, `mod_ui_active` = 1, `mod_ui_name` = ?,
                    `mod_relative_link` = ?, `mod_directory` = ?, `type` = 0, `date` = NOW()",
            ['Clinical Co-Pilot', 'Clinical Co-Pilot', strtolower($dir . '/public/doc.php'), $dir]
        );
        echo "  [ok] registered modules row (active)\n";
    } else {
        QueryUtils::sqlStatementThrowException(
            "UPDATE `modules` SET `mod_active` = 1, `mod_ui_active` = 1 WHERE `mod_directory` = ?",
            [$dir]
        );
        echo "  [ok] modules row already present -- ensured active\n";
    }

    // 2. table.sql -- directive-aware + idempotent (creates tables, seeds config).
    require_once $GLOBALS['fileroot'] . '/library/sql_upgrade_fx.php';
    upgradeFromSqlFile('table.sql', $moduleFsDir);
    echo "  [ok] ran table.sql (mod_copilot_* tables + cadence/threshold/limit config)\n";

    // 3. background_services worker row (mirrors ModuleManagerListener::enable()).
    $svc = QueryUtils::fetchRecords(
        "SELECT `name` FROM `background_services` WHERE `name` = 'clinical_copilot_worker'"
    );
    if (count($svc) === 0) {
        QueryUtils::sqlInsert(
            "INSERT INTO `background_services`
                (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`)
             VALUES ('clinical_copilot_worker', 'Clinical Co-Pilot Warm Worker', 1, 0, NOW(), 5, 'clinicalCopilotWorkerRun', ?, 100)",
            [$workerRequireOnce]
        );
        echo "  [ok] registered + activated background_services worker (every 5 min)\n";
    } else {
        QueryUtils::sqlStatementThrowException(
            "UPDATE `background_services` SET `active` = 1 WHERE `name` = 'clinical_copilot_worker'"
        );
        echo "  [ok] background_services worker already present -- ensured active\n";
    }

    echo "== DONE: module installed + enabled ==\n";
    echo "Open: https://localhost:9300  (login admin / pass) -> the Clinical Co-Pilot\n";
    echo "menu item, or go straight to a seeded patient's doc page:\n";
    echo "  /interface/modules/custom_modules/$dir/public/doc.php?pid=<pid>\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "INSTALL FAILED: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Fallback: enable via Admin -> Modules -> Manage Modules (see this file's header).\n");
    exit(1);
}
