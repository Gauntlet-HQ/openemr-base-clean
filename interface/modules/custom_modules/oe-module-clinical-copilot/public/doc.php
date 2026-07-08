<?php

/**
 * Clinical Co-Pilot -- pre-visit synthesis doc page (U8: read path, facts-first
 * synthesis, history view, manual Regenerate).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Page bootstrap contract (docs/build-notes.md): flags set BEFORE globals.php.
// This page is read-only-session -- it never sets $sessionAllowWrite.
$ignoreAuth = false;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Menu\PatientMenuRole;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use OpenEMR\Modules\ClinicalCopilot\Controller\DocController;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\DocViewModel;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\ChartLinkResolver;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\ScheduledPatientRow;
use OpenEMR\OeUI\OemrUI;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// CSRF -> ACL -> session identity, in that order (build-notes.md's page
// bootstrap contract). CSRF only applies to the state-changing Regenerate
// POST; a plain GET view performs no write and needs no token.
if ($isPost) {
    CsrfUtils::checkCsrfInput(INPUT_POST, $session, dieOnFail: true);
}

// Gate on both the chart-wide 'patients'/'med' section AND this module's
// own 'clinical_copilot' section (ARCHITECTURE.md §4) -- a site can deny
// the copilot independently of chart access, and a denial here is a clean
// ACL deny, not obscurity.
if (!AclMain::aclCheckCore('patients', 'med') || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    echo xlt('Access denied');
    exit;
}

$authUserId = (int)($session->get('authUserID') ?? 0);

// pid is an int chart identifier, never PHI itself (docs/build-notes.md /
// ARCHITECTURE.md §4: "pid in GET is fine"); it stays in the query string on
// both the view GET and the Regenerate form's POST target URL.
$rawPid = $_GET['pid'] ?? null;
$pid = is_numeric($rawPid) ? (int)$rawPid : 0;

// Keep the chart session in sync when opened from the patient nav (pid=true menu item).
if ($pid > 0 && (int)($session->get('pid') ?? 0) !== $pid) {
    require_once OEGlobalsBag::getInstance()->getIncludeRoot() . '/../library/pid.inc.php';
    setpid($pid);
}

$controller = DocController::createDefault();

$webRoot = OEGlobalsBag::getInstance()->getWebRoot();
$moduleBase = $webRoot . '/interface/modules/custom_modules/oe-module-clinical-copilot/public';
$docLandingUrl = $moduleBase . '/doc.php';

if ($pid <= 0) {
    $templateVars = [
        'landing' => true,
        'found' => false,
        'invalid_pid' => false,
        'scheduled_patients' => $controller->scheduledPatientsToday(),
        'doc_landing_url' => $docLandingUrl,
        'pid' => 0,
        'doc_url' => $docLandingUrl,
        'chat_url' => $moduleBase . '/chat.php',
        'status_url' => $moduleBase . '/status.php',
        'patient' => null,
        'history_rows' => [],
        'doc' => ['capability_crash' => false],
        'view_model' => ['narrative' => [], 'facts_by_capability' => [], 'in_flight' => [], 'exclusions' => []],
        'visit_label' => null,
    ];
} elseif ($isPost && ($_POST['action'] ?? '') === 'regenerate') {
    $wantsStream = ($_POST['stream'] ?? '') === '1';

    if ($wantsStream) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
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

        $viewData = $controller->regenerate($pid, $authUserId, $onStatus);
        if (!$viewData['found']) {
            $emit('done', ['ok' => false, 'reason' => 'patient not found']);
            exit;
        }

        $emit('done', $controller->formatRegenerateJson($viewData['result'], $webRoot));
        exit;
    }

    $viewData = $controller->regenerate($pid, $authUserId);
    if (docWantsJsonResponse()) {
        header('Content-Type: application/json');
        if (!$viewData['found']) {
            echo json_encode(['ok' => false, 'reason' => 'patient not found']);
            exit;
        }
        echo json_encode($controller->formatRegenerateJson($viewData['result'], $webRoot));
        exit;
    }
    $todayVisit = $controller->todayAppointmentForPatient($pid);
    $templateVars = buildDocTemplateVars($viewData, $pid, $moduleBase, $docLandingUrl, $webRoot, invalidPid: false, todayVisit: $todayVisit);
} else {
    $viewData = $controller->view($pid, $authUserId);
    $todayVisit = $viewData['found'] ? $controller->todayAppointmentForPatient($pid) : null;
    $templateVars = buildDocTemplateVars(
        $viewData,
        $pid,
        $moduleBase,
        $docLandingUrl,
        $webRoot,
        invalidPid: !$viewData['found'],
        scheduledPatients: !$viewData['found'] ? $controller->scheduledPatientsToday() : [],
        todayVisit: $todayVisit,
    );
}

$templateVars = array_merge(
    $templateVars,
    buildPatientChartNavVars($pid, (bool)($templateVars['found'] ?? false)),
);
$templateVars = enrichDocPresentationVars($templateVars);

$twig = (new TwigContainer(dirname(__DIR__) . '/templates', OEGlobalsBag::getInstance()->getKernel()))->getTwig();
echo $twig->render('oe-module-clinical-copilot/doc.html.twig', $templateVars);

/**
 * @param array{found: bool, result: ?\OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadResult, history: list<\OpenEMR\Modules\ClinicalCopilot\Doc\DocRow>, patient: ?\OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers} $viewData
 *
 * @return array<string, mixed>
 */
function buildDocTemplateVars(
    array $viewData,
    int $pid,
    string $moduleBase,
    string $docLandingUrl,
    string $webRoot,
    bool $invalidPid,
    array $scheduledPatients = [],
    ?ScheduledPatientRow $todayVisit = null,
): array {
    $templateVars = [
        'landing' => false,
        'found' => $viewData['found'],
        'invalid_pid' => $invalidPid,
        'scheduled_patients' => $scheduledPatients,
        'doc_landing_url' => $docLandingUrl,
        'patient' => $viewData['patient'],
        'pid' => $pid,
        'doc_url' => $moduleBase . '/doc.php?pid=' . $pid,
        'chat_url' => $moduleBase . '/chat.php',
        'status_url' => $moduleBase . '/status.php',
        'history_rows' => $viewData['found'] ? DocViewModel::historyRows($viewData['history']) : [],
        'visit_label' => ChartLinkResolver::visitLabel($todayVisit),
    ];

    if ($viewData['found']) {
        $templateVars['doc'] = DocViewModel::summary($viewData['result']);
        $templateVars['view_model'] = DocViewModel::build($viewData['result'], $webRoot);
    } else {
        $templateVars['doc'] = ['capability_crash' => false];
        $templateVars['view_model'] = ['narrative' => [], 'facts_by_capability' => [], 'in_flight' => [], 'exclusions' => []];
    }

    return $templateVars;
}

/**
 * @param array<string, mixed> $templateVars
 *
 * @return array<string, mixed>
 */
function enrichDocPresentationVars(array $templateVars): array
{
    $doc = is_array($templateVars['doc'] ?? null) ? $templateVars['doc'] : [];
    $viewModel = is_array($templateVars['view_model'] ?? null) ? $templateVars['view_model'] : [];
    $found = (bool)($templateVars['found'] ?? false);
    $capabilityCrash = (bool)($doc['capability_crash'] ?? false);
    $verifyStatus = is_string($doc['verify_status'] ?? null) ? $doc['verify_status'] : '';
    $narrative = is_array($viewModel['narrative'] ?? null) ? $viewModel['narrative'] : [];

    $templateVars['llm_configured'] = LlmRuntimeConfig::llmConfigured();
    $templateVars['needs_narrative_generation'] = $found
        && !$capabilityCrash
        && ($verifyStatus === 'degraded' || $narrative === []);

    return $templateVars;
}


function docWantsJsonResponse(): bool
{
    if (($_POST['format'] ?? '') === 'json') {
        return true;
    }

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

    return is_string($accept) && str_contains($accept, 'application/json');
}

/**
 * Renders the standard patient-chart header + horizontal nav when doc.php is
 * opened in-chart (pid present and patient found). Matches demographics.php /
 * external_data.php so Copilot feels like part of the dashboard.
 *
 * @return array{show_patient_chart_nav: bool, patient_chart_nav_html: string, patient_nav_list_id: string}
 */
function buildPatientChartNavVars(int $pid, bool $found): array
{
    if ($pid <= 0 || !$found) {
        return [
            'show_patient_chart_nav' => false,
            'patient_chart_nav_html' => '',
            'patient_nav_list_id' => '',
        ];
    }

    $arrOeUiSettings = [
        'heading_title' => xl('Appointment Copilot'),
        'include_patient_name' => true,
        'expandable' => false,
        'expandable_files' => [],
        'action' => '',
        'action_title' => '',
        'action_href' => '',
        'show_help_icon' => false,
        'help_file_name' => '',
    ];
    $oemr_ui = new OemrUI($arrOeUiSettings);

    ob_start();
    require_once OEGlobalsBag::getInstance()->getIncludeRoot() . '/patient_file/summary/dashboard_header.php';
    $list_id = 'clinical_copilot';
    (new PatientMenuRole())->displayHorizNavBarMenu();
    $navHtml = (string)ob_get_clean();

    return [
        'show_patient_chart_nav' => true,
        'patient_chart_nav_html' => $navHtml,
        'patient_nav_list_id' => 'clinical_copilot',
    ];
}
