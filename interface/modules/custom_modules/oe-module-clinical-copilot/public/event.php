<?php

/**
 * Clinical Co-Pilot -- minimal client ping endpoint for the two over-reliance indicators (ARCHITECTURE.md §2.5).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Page bootstrap contract (docs/build-notes.md): flags set BEFORE globals.php.
// Read-only-session -- never sets $sessionAllowWrite; the only write this
// page performs is one row in the module's own mod_copilot_ui_event ledger,
// never a core table.
$ignoreAuth = false;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Observability\UiEvent\UiEventStore;
use OpenEMR\Modules\ClinicalCopilot\Observability\UiEvent\UiEventType;

$session = SessionWrapperFactory::getInstance()->getActiveSession();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'reason' => 'POST only']);
    exit;
}

CsrfUtils::checkCsrfInput(INPUT_POST, $session, dieOnFail: true);

if (!AclMain::aclCheckCore('patients', 'med') || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'reason' => 'access denied']);
    exit;
}

$authUserId = (int)($session->get('authUserID') ?? 0);

$rawPid = $_POST['pid'] ?? null;
$pid = is_numeric($rawPid) ? (int)$rawPid : 0;
$correlationId = (string)($_POST['correlation_id'] ?? '');
$eventType = UiEventType::tryFrom((string)($_POST['event_type'] ?? ''));

// No PHI leaves this endpoint and nothing here is audit-worthy on its own
// (it is a UI telemetry ping, not a chart-data view) -- a malformed request
// is simply refused, never logged with any request content.
if ($pid <= 0 || $correlationId === '' || $eventType === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'reason' => 'pid, correlation_id, and a valid event_type are required']);
    exit;
}

(new UiEventStore())->record($eventType, $correlationId, $pid, $authUserId);

echo json_encode(['ok' => true]);
