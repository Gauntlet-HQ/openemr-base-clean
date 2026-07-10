<?php

/**
 * PHPUnit bootstrap for this module's DB-backed tests (tests/Db/).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Isolated tests (tests/Isolated/) need no bootstrap at all -- they are pure
// PHP with no OpenEMR runtime dependency and run via the host project's
// `composer phpunit-isolated` / `openemr-cmd phpunit-isolated`. This file is
// only for tests/Db/, which needs a live OpenEMR database connection
// (globals.php), following the same pattern as
// oe-module-comlink-telehealth/tests/bootstrap.php.
if (PHP_SAPI !== 'cli') {
    exit;
}

$_GET['site'] = 'default';
$ignoreAuth = true;
require_once __DIR__ . '/../../../../globals.php';
