<?php

/**
 * Clinical Co-Pilot -- polling fallback for chat turn and synthesis regenerate progress (ARCHITECTURE.md §1.3).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Page bootstrap contract (docs/build-notes.md): flags set BEFORE globals.php.
// Read-only GET -- never sets $sessionAllowWrite, and (unlike chat.php) needs
// no CSRF check since it performs no state change of any kind.
$ignoreAuth = false;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnStore;
use OpenEMR\Modules\ClinicalCopilot\Controller\ChatController;
use OpenEMR\Modules\ClinicalCopilot\Controller\DocController;

$session = SessionWrapperFactory::getInstance()->getActiveSession();

if (!AclMain::aclCheckCore('patients', 'med') || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'access denied']);
    exit;
}

$authUserId = (int)($session->get('authUserID') ?? 0);
$correlationId = (string)($_GET['cid'] ?? '');

if ($correlationId === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'cid is required']);
    exit;
}

$turnStore = new ChatTurnStore();
if ($turnStore->findByCorrelationId($correlationId) !== []) {
    $controller = ChatController::createDefault();
    $result = $controller->pollStatus($correlationId, $authUserId);
} else {
    $webRoot = OEGlobalsBag::getInstance()->getWebRoot();
    $controller = DocController::createDefault();
    $result = $controller->pollRegenerateStatus($correlationId, $authUserId, $webRoot);
}

if (($result['ok'] ?? false) === false && isset($result['http_status'])) {
    http_response_code((int)$result['http_status']);
}

header('Content-Type: application/json');
echo json_encode($result);
