<?php

/**
 * Clinical Co-Pilot -- Week 2 multi-agent endpoint (supervisor -> workers -> critic).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Page bootstrap contract (docs/build-notes.md): flags set BEFORE globals.php.
// Like chat.php, this page is contractually read-only-session (ARCHITECTURE.md
// §1.3) -- it never sets $sessionAllowWrite. The supervisor gathers, it does
// not write to the chart (write-back stays behind the human-gated lock flow).
$ignoreAuth = false;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Agent\AgentAskRequest;
use OpenEMR\Modules\ClinicalCopilot\Agent\InvalidAgentAskException;
use OpenEMR\Modules\ClinicalCopilot\Controller\AgentController;
use OpenEMR\Modules\ClinicalCopilot\Ingest\UploadedDocument;

$session = SessionWrapperFactory::getInstance()->getActiveSession();

// Every real request to this endpoint is a POST (an agent run gathers,
// composes, and verifies -- it is not idempotent-cacheable page content).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'POST only -- see ops/api/openapi.yaml']);
    exit;
}

CsrfUtils::checkCsrfInput(INPUT_POST, $session, dieOnFail: true);

// Gate on both the chart-wide 'patients'/'med' section AND this module's own
// 'clinical_copilot' section (ARCHITECTURE.md §4), exactly like chat.php.
if (!AclMain::aclCheckCore('patients', 'med') || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'access denied']);
    exit;
}

$authUserId = (int)($session->get('authUserID') ?? 0);

// Parse, don't validate: the superglobals are read exactly once, here, and
// turned into one typed boundary object (or a user-safe 400).
$upload = UploadedDocument::fromFilesEntry($_FILES['document'] ?? null);
try {
    $ask = AgentAskRequest::fromPost(
        $_POST['pid'] ?? null,
        $_POST['question'] ?? null,
        $_POST['tags'] ?? null,
        $_POST['doc_type'] ?? null,
        $upload,
    );
} catch (InvalidAgentAskException $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    // InvalidAgentAskException messages are written to be user-safe (see the
    // class docblock) -- this is the module's sanctioned getMessage() echo.
    echo json_encode(['ok' => false, 'reason' => $e->getMessage()]);
    exit;
}

// The run executes the composer LLM call (and possibly a vision extraction)
// synchronously inside this request -- same headroom rationale as chat.php:
// without a matching PHP limit, php.ini's max_execution_time would kill the
// process mid-call and return a non-JSON fatal instead of a clean degrade.
if (function_exists('set_time_limit')) {
    @set_time_limit(150);
}

$result = AgentController::createDefault()->ask($ask, $authUserId);
if (($result['ok'] ?? false) === false && (int)($result['http_status'] ?? 0) === 404) {
    http_response_code(404);
}
header('Content-Type: application/json');
echo json_encode($result);
