<?php

/**
 * Clinical Co-Pilot -- Week 2 Labs tab: upload a lab PDF or start manual entry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$ignoreAuth = false;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use OpenEMR\Modules\ClinicalCopilot\Controller\IngestController;
use OpenEMR\Modules\ClinicalCopilot\Ingest\UploadedDocument;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($isPost) {
    CsrfUtils::checkCsrfInput(INPUT_POST, $session, dieOnFail: true);
}

if (!AclMain::aclCheckCore('patients', 'med') || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    echo xlt('Access denied');
    exit;
}

$authUserId = (int)($session->get('authUserID') ?? 0);
$webRoot = OEGlobalsBag::getInstance()->getWebRoot();
$moduleBase = $webRoot . '/interface/modules/custom_modules/oe-module-clinical-copilot/public';

$rawPid = ($isPost ? ($_POST['pid'] ?? null) : ($_GET['pid'] ?? null));
$pid = is_numeric($rawPid) ? (int)$rawPid : 0;

if ($pid <= 0) {
    http_response_code(400);
    echo xlt('A patient is required for lab entry.');
    exit;
}

// Keep the chart session in sync (same as doc.php).
if ((int)($session->get('pid') ?? 0) !== $pid) {
    require_once OEGlobalsBag::getInstance()->getIncludeRoot() . '/../library/pid.inc.php';
    setpid($pid);
}

$controller = IngestController::createDefault();
$action = $isPost ? ($_POST['action'] ?? '') : '';

if ($action === 'upload') {
    $entry = $_FILES['document'] ?? null;
    $upload = UploadedDocument::fromFilesEntry($entry);
    if ($upload === null) {
        renderLabForm($moduleBase, $webRoot, $pid, error: UploadedDocument::describeRejection($entry));
        exit;
    }

    $result = $controller->ingestLab($pid, $upload->bytes, $upload->filename, $upload->mimeType, $authUserId);
    header('Location: ' . $moduleBase . '/extraction_review.php?extraction_id=' . $result->extractionId);
    exit;
}

if ($action === 'manual') {
    $result = $controller->startManualLab($pid, $authUserId);
    header('Location: ' . $moduleBase . '/extraction_review.php?extraction_id=' . $result->extractionId);
    exit;
}

renderLabForm($moduleBase, $webRoot, $pid);

function renderLabForm(string $moduleBase, string $webRoot, int $pid, string $error = ''): void
{
    $twig = (new TwigContainer(dirname(__DIR__) . '/templates', OEGlobalsBag::getInstance()->getKernel()))->getTwig();
    echo $twig->render('oe-module-clinical-copilot/lab_upload.html.twig', [
        'post_url' => $moduleBase . '/lab_upload.php',
        'doc_url' => $moduleBase . '/doc.php?pid=' . $pid,
        'web_root' => $webRoot,
        'pid' => $pid,
        'error' => $error,
        'llm_configured' => LlmRuntimeConfig::llmConfigured(),
    ]);
}
