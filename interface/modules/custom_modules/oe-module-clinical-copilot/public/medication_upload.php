<?php

/**
 * Clinical Co-Pilot -- Week 2 medication list: upload for extraction + review.
 *
 * The third patient-attached document type. Upload a medication list (discharge
 * med list, pharmacy printout, patient-carried list) onto an EXISTING chart ->
 * vision extraction against the strict medication_list schema -> a draft in
 * module staging -> the shared review screen to verify/edit -> lock, which
 * freezes the verified transcription and records extraction accuracy but
 * deliberately writes NOTHING to the chart's medication/prescription tables:
 * medication chart reconciliation is a clinical-safety-sensitive step this
 * module defers (see ExtractionReview::lock()), matching how intake deferred
 * create-at-upload. With no model configured the upload degrades to a blank
 * draft skeleton the reviewer hand-fills — the flow never dead-ends.
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
    echo xlt('A patient is required for medication list upload.');
    exit;
}

// Keep the chart session in sync (same as lab_upload.php / doc.php) — the
// review page authorizes per patient against the session pid.
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
        renderMedicationForm($moduleBase, $webRoot, $pid, error: UploadedDocument::describeRejection($entry));
        exit;
    }

    $result = $controller->ingestMedicationList($pid, $upload->bytes, $upload->filename, $upload->mimeType, $authUserId);
    header('Location: ' . $moduleBase . '/extraction_review.php?extraction_id=' . $result->extractionId);
    exit;
}

renderMedicationForm($moduleBase, $webRoot, $pid);

function renderMedicationForm(string $moduleBase, string $webRoot, int $pid, string $error = ''): void
{
    $twig = (new TwigContainer(dirname(__DIR__) . '/templates', OEGlobalsBag::getInstance()->getKernel()))->getTwig();
    echo $twig->render('oe-module-clinical-copilot/medication_upload.html.twig', [
        'post_url' => $moduleBase . '/medication_upload.php',
        'doc_url' => $moduleBase . '/doc.php?pid=' . $pid,
        'web_root' => $webRoot,
        'pid' => $pid,
        'error' => $error,
        'llm_configured' => LlmRuntimeConfig::llmConfigured(),
    ]);
}
