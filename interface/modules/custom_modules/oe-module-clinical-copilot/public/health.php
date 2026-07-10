<?php

/**
 * Clinical Co-Pilot -- unauthenticated liveness endpoint (ARCHITECTURE.md §3.4).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Page bootstrap contract (docs/build-notes.md): flags set BEFORE globals.php.
// Deliberately UNAUTHENTICATED (ARCHITECTURE.md §3.4: "a DB outage must not
// fail liveness and get the app pointlessly restarted by an orchestrator") --
// this endpoint checks NO dependencies at all, not even the session/ACL
// checks every other module page performs. Module-standalone: NOT registered
// with the host's `meta/health` prober (no extension point exists, §3.4's
// "Scope correction").
$ignoreAuth = true;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Modules\ClinicalCopilot\Observability\HealthCheck;

header('Content-Type: application/json');
echo json_encode((new HealthCheck())->check(), JSON_UNESCAPED_SLASHES);
