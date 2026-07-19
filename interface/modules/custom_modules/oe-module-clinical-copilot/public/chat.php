<?php

/**
 * Clinical Co-Pilot -- chat endpoint (U11: session bootstrap, turn execution, SSE + polling).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Page bootstrap contract (docs/build-notes.md): flags set BEFORE globals.php.
// This page is contractually read-only-session (ARCHITECTURE.md §1.3) -- it
// never sets $sessionAllowWrite.
$ignoreAuth = false;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Controller\ChatController;

$session = SessionWrapperFactory::getInstance()->getActiveSession();

// Every request to this endpoint is a POST -- there is no GET view (that is
// public/status.php's job, read-only polling). CSRF applies to all of them.
CsrfUtils::checkCsrfInput(INPUT_POST, $session, dieOnFail: true);

// Gate on both the chart-wide 'patients'/'med' section AND this module's own
// 'clinical_copilot' section (ARCHITECTURE.md §4), exactly like doc.php.
if (!AclMain::aclCheckCore('patients', 'med') || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'access denied']);
    exit;
}

$authUserId = (int)($session->get('authUserID') ?? 0);

$action = (string)($_POST['action'] ?? 'turn');
$rawPid = $_POST['pid'] ?? null;
$pid = is_numeric($rawPid) ? (int)$rawPid : 0;
$rawSessionId = $_POST['session_id'] ?? null;
$sessionId = is_numeric($rawSessionId) ? (int)$rawSessionId : 0;
$message = (string)($_POST['message'] ?? '');
$wantsStream = ($_POST['stream'] ?? '') === '1';

$controller = ChatController::createDefault();

if ($action === 'start') {
    $result = $pid > 0 ? $controller->startSession($pid, $authUserId) : ['ok' => false, 'session_id' => null, 'reason' => 'invalid pid'];
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

if ($action === 'reseed') {
    $result = $pid > 0 ? $controller->reseed($pid, $authUserId) : ['ok' => false, 'session_id' => null, 'reason' => 'invalid pid'];
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

if ($action === 'release') {
    // Manual escape hatch: free the caller's active-session slots so the
    // per-user cap stops rejecting turns. Keeps the session the panel is
    // currently in (if any) so the open conversation is not lost.
    $keepSessionId = $sessionId > 0 ? $sessionId : null;
    $result = $controller->releaseSessions($authUserId, $keepSessionId);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

if ($sessionId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'session_id is required']);
    exit;
}

// A turn runs the LLM synchronously and the client waits on this one request.
// The LLM HTTP client allows up to 90s per call; without a matching PHP limit,
// php.ini's max_execution_time (often 30s) would kill the process mid-call,
// returning a non-JSON fatal ("network error" in the client) INSTEAD of the
// clean JSON degrade the timeout path produces. Give PHP enough headroom to
// let a slow call finish or time out gracefully. A closed tab never aborts the
// turn (ignore_user_abort below / ARCHITECTURE.md §1.3).
if (function_exists('set_time_limit')) {
    @set_time_limit(150);
}

// Execution model (ARCHITECTURE.md §1.3): the turn executes synchronously
// inside this request either way -- SSE only changes how progress is
// OBSERVED, never when the turn actually finishes. `$onStatus` is a no-op
// unless the client asked for a streamed response.
if ($wantsStream) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    // Turn survives a closed browser tab regardless (ARCHITECTURE.md §1.3);
    // this only stops PHP from tearing down the script if the client goes
    // away mid-stream, matching that guarantee for the SSE view specifically.
    ignore_user_abort(true);

    $emit = static function (string $event, array $data): void {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data) . "\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    };

    $onStatus = static function (string $message) use ($emit): void {
        $emit('status', ['message' => $message]);
    };

    $result = $controller->submitTurn($sessionId, $authUserId, $message, $onStatus);
    $emit('done', $result);
    exit;
}

$result = $controller->submitTurn($sessionId, $authUserId, $message);
if (($result['ok'] ?? false) === false && isset($result['http_status'])) {
    http_response_code((int)$result['http_status']);
}
header('Content-Type: application/json');
echo json_encode($result);
