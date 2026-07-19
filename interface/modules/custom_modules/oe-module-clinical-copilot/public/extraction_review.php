<?php

/**
 * Clinical Co-Pilot -- Week 2 extraction review: verify, edit, and lock.
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
use OpenEMR\Modules\ClinicalCopilot\Controller\IngestController;

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
// Post-lock edits / unlock require an elevated administrator (ARCHITECTURE.md
// §4 admin gating, same posture as the observability dashboard).
$isElevated = AclMain::aclCheckCore('admin', 'super') || AclMain::aclCheckCore('admin', 'users');

$webRoot = OEGlobalsBag::getInstance()->getWebRoot();
$moduleBase = $webRoot . '/interface/modules/custom_modules/oe-module-clinical-copilot/public';

$rawId = ($isPost ? ($_POST['extraction_id'] ?? null) : ($_GET['extraction_id'] ?? null));
$extractionId = is_numeric($rawId) ? (int)$rawId : 0;
if ($extractionId <= 0) {
    http_response_code(400);
    echo xlt('An extraction id is required.');
    exit;
}

$controller = IngestController::createDefault();
$reviewUrl = $moduleBase . '/extraction_review.php?extraction_id=' . $extractionId;

// Per-patient authorization. The module ACL check above is chart-wide; without
// this, extraction_id is an IDOR handle — any user with copilot access could
// view/edit/LOCK (commit to the chart) another patient's staged extraction by
// enumerating ids. Bind to the caller's active patient context (the session
// pid, set by the lab/intake entry points), and refuse a mismatch.
$sessionPid = (int)($session->get('pid') ?? 0);
$extractionPid = $controller->extractionPatientId($extractionId);
if ($extractionPid === null || $sessionPid <= 0 || $extractionPid !== $sessionPid) {
    (new \OpenEMR\Common\Logging\SystemLogger())->warning(
        'ClinicalCopilot: extraction access denied (patient-context mismatch)',
        ['extraction_id' => $extractionId, 'session_pid' => $sessionPid, 'extraction_pid' => $extractionPid],
    );
    http_response_code(403);
    echo xlt('Access denied');
    exit;
}

if ($isPost) {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'edit':
                $fieldId = (int)($_POST['field_id'] ?? 0);
                $value = isset($_POST['value']) && $_POST['value'] !== '' ? (string)$_POST['value'] : null;
                $controller->editField($extractionId, $fieldId, $value, $isElevated);
                break;
            case 'add_field':
                $controller->addManualLabField(
                    $extractionId,
                    trim((string)($_POST['field_key'] ?? '')),
                    nullableString($_POST['value'] ?? null),
                    nullableString($_POST['unit'] ?? null),
                    nullableString($_POST['ref_range'] ?? null),
                    nullableString($_POST['abnormal_flag'] ?? null),
                    $isElevated,
                );
                break;
            case 'lock':
                // Specimen draw/collection date entered on the review screen
                // (labs). Accept only a strict Y-m-d; anything else → null, and
                // the commit falls back to today.
                $rawDate = trim((string)($_POST['collection_date'] ?? ''));
                $collectionDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) === 1 ? $rawDate : null;
                $controller->lock($extractionId, $authUserId, $collectionDate);
                break;
            case 'unlock':
                $controller->unlock($extractionId, $isElevated);
                break;
        }
    } catch (\Throwable $e) {
        (new \OpenEMR\Common\Logging\SystemLogger())->error('ClinicalCopilot: review action failed', ['exception' => $e]);
        header('Location: ' . $reviewUrl . '&err=1');
        exit;
    }

    // Post/Redirect/Get so a refresh never re-submits an edit or a lock.
    header('Location: ' . $reviewUrl);
    exit;
}

$vm = $controller->reviewViewModel($extractionId);
$vm['post_url'] = $moduleBase . '/extraction_review.php';
$vm['review_url'] = $reviewUrl;
$vm['is_elevated'] = $isElevated;
$vm['error'] = isset($_GET['err']);
$vm['patient_url'] = isset($vm['pid']) ? $moduleBase . '/doc.php?pid=' . $vm['pid'] : $moduleBase . '/doc.php';
// as_file=false makes the document controller serve the file with
// `Content-Disposition: inline` (not `attachment`), so the browser renders
// the PDF inside the review iframe instead of downloading it. Without it the
// retrieve action defaults to as_file=true: the iframe stays blank and the
// browser fires a "save file" prompt whenever the pane loads. The parameter
// order matters — controller.php dispatches positionally, so as_file must
// follow document_id to land on retrieve_action()'s third argument.
$vm['source_view_url'] = (isset($vm['source_document_id'], $vm['pid']) && $vm['source_document_id'])
    ? $webRoot . '/controller.php?document&retrieve&patient_id=' . $vm['pid'] . '&document_id=' . $vm['source_document_id'] . '&as_file=false'
    : '';
// Vendored pdf.js (public/assets/, see public/assets/README.md) powering the
// citation overlay: the review page renders the source PDF to canvases and
// draws each field's bounding box on the real page. The template loads these
// as ES modules; when they fail (old browser, non-PDF source), the native
// iframe viewer below stays in place untouched.
$vm['pdfjs_url'] = $moduleBase . '/assets/pdf.min.mjs';
$vm['pdfjs_worker_url'] = $moduleBase . '/assets/pdf.worker.min.mjs';
// Draw/collection date field default (labs). Prefer the specimen date the VLM
// read off the printed report (parsed + persisted at ingest, W5); fall back to
// today when none was printed/parseable. The reviewer can always override
// before locking.
$parsedCollectionDate = $vm['collection_date'] ?? null;
$vm['collection_date'] = is_string($parsedCollectionDate) && $parsedCollectionDate !== ''
    ? $parsedCollectionDate
    : date('Y-m-d');

$twig = (new TwigContainer(dirname(__DIR__) . '/templates', OEGlobalsBag::getInstance()->getKernel()))->getTwig();
echo $twig->render('oe-module-clinical-copilot/extraction_review.html.twig', $vm);

function nullableString(mixed $v): ?string
{
    return is_string($v) && trim($v) !== '' ? trim($v) : null;
}
