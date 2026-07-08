<?php

/**
 * Clinical Co-Pilot -- unauthenticated, redacted readiness endpoint (ARCHITECTURE.md §3.4).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Page bootstrap contract (docs/build-notes.md): flags set BEFORE globals.php.
// Unauthenticated (ARCHITECTURE.md §3.4: "external uptime probes point
// here"), but REDACTED -- status enums only, no latencies/config/PHI -- and
// per-IP rate-limited (ReadyCheck performs the real dependency checks;
// IpRateLimiter is this file's own concern since it's about the HTTP
// request, not a dependency check).
$ignoreAuth = true;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Modules\ClinicalCopilot\Observability\IpRateLimiter;
use OpenEMR\Modules\ClinicalCopilot\Observability\ReadyCheck;

$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!(new IpRateLimiter())->allow($clientIp)) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'rate_limited'], JSON_UNESCAPED_SLASHES);
    exit;
}

$result = (new ReadyCheck())->check();

// Unauthenticated + redacted (ARCHITECTURE.md §3.4): the response body is
// ALREADY nothing but status enums (ReadyCheck's own contract) -- the HTTP
// status code additionally reflects genuine failure (503) vs. a degraded-
// but-serving state (200, I6: "degraded-but-serving states are reported
// honestly").
http_response_code($result['status'] === 'error' ? 503 : 200);
header('Content-Type: application/json');
echo json_encode($result, JSON_UNESCAPED_SLASHES);
