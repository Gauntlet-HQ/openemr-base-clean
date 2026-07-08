<?php

/**
 * ai_history_summary.php
 *
 * AJAX endpoint that returns an AI-generated summary of the current
 * patient's most recent history, or a "new patient" result when no history
 * is on record. Responds with JSON.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");

$session = \OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession();
$pid = (int) $session->get('pid', 0);

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Services\AI\OpenAiException;
use OpenEMR\Services\AI\PatientHistorySummaryService;

CsrfUtils::checkCsrfInput(INPUT_POST, dieOnFail: true);

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('patients', 'demo')) {
    http_response_code(403);
    echo json_encode(['error' => xl('Access denied.')]);
    exit;
}

if ($pid <= 0) {
    http_response_code(400);
    echo json_encode(['error' => xl('No patient is currently selected.')]);
    exit;
}

try {
    $result = (new PatientHistorySummaryService())->summarizeByPid($pid);
} catch (OpenAiException) {
    http_response_code(502);
    echo json_encode(['error' => xl('The AI summary service is currently unavailable.')]);
    exit;
} catch (\Throwable) {
    http_response_code(500);
    echo json_encode(['error' => xl('Unable to generate the summary.')]);
    exit;
}

echo json_encode($result->toArray());
