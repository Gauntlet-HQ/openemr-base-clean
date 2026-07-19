<?php

/**
 * Clinical Co-Pilot -- observability dashboard (ARCHITECTURE.md §3.3).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Page bootstrap contract (docs/build-notes.md): flags set BEFORE globals.php.
// Read-only-session -- never sets $sessionAllowWrite (even the breaker
// admin actions below are single-row config writes to mod_copilot_cadence,
// never anything requiring a write-session).
$ignoreAuth = false;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeBaseStatus;
use OpenEMR\Modules\ClinicalCopilot\Observability\Metrics\MetricsService;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceCircuitBreaker;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceConfigStore;
use OpenEMR\Modules\ClinicalCopilot\Observability\ReadyCheck;
use OpenEMR\Modules\ClinicalCopilot\Observability\TracePayloadStore;
use OpenEMR\Modules\ClinicalCopilot\Verify\CheckId;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPolicy;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($isPost) {
    CsrfUtils::checkCsrfInput(INPUT_POST, $session, dieOnFail: true);
}

// ARCHITECTURE.md §3.2: "Access to the trace UI is ACL-gated (admin) and
// itself audit-logged." Gated on the host's admin section (mirrors
// oe-module-dashboard-context's own AdminController pattern) AND this
// module's own ACL section, so a site can independently deny the copilot
// even to a technical admin.
$isAdmin = AclMain::aclCheckCore('admin', 'super') || AclMain::aclCheckCore('admin', 'users');
if (!$isAdmin || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    echo xlt('Access denied');
    exit;
}

$authUser = (string)($session->get('authUser') ?? '');
$authProvider = (string)($session->get('authProvider') ?? '');
$configStore = new CadenceConfigStore();

// Populated only when a testing-panel button is pressed (POST actions below).
$evalResult = null;
$evalError = null;
$loadTestResult = null;
$loadTestError = null;

if ($isPost) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'breaker_force_open') {
        $configStore->forceOpen($authUser, (string)($_POST['reason'] ?? ''));
        EventAuditLogger::getInstance()->newEvent('security', $authUser, $authProvider, 1, 'Clinical Co-Pilot: circuit breaker manually forced open');
    } elseif ($action === 'breaker_reset') {
        $configStore->manualReset($authUser);
        EventAuditLogger::getInstance()->newEvent('security', $authUser, $authProvider, 1, 'Clinical Co-Pilot: circuit breaker manually reset');
    } elseif ($action === 'run_evals') {
        // The 54-case boolean-rubric gate, on demand from the UI — the SAME
        // deterministic engine the CLI/CI gate uses (ops/eval/run-evals.php),
        // with no live model or DB. EvalGate lives under ops/eval/ (not src/),
        // so require it explicitly; its src/ collaborators autoload normally.
        require_once dirname(__DIR__) . '/ops/eval/EvalGate.php';
        try {
            $evalResult = (new \OpenEMR\Modules\ClinicalCopilot\Ops\Eval\EvalGate(dirname(__DIR__) . '/ops/eval'))->run();
            // Persist the run summary (rates/regressions only, no PHI) so the
            // worker-tick eval-regression alert fires on the LAST recorded run.
            // Best-effort: a persistence failure must not hide the on-screen
            // result the admin just asked for.
            try {
                $configStore->recordEvalRun($evalResult);
            } catch (\Throwable $persistError) {
                (new \OpenEMR\Common\Logging\SystemLogger())->error('ClinicalCopilot: failed to record eval run for alerting', ['exception' => $persistError]);
            }
        } catch (\Throwable $e) {
            $evalError = 'Eval run failed to start (see server log).';
            (new \OpenEMR\Common\Logging\SystemLogger())->error('ClinicalCopilot: dashboard eval run failed', ['exception' => $e]);
        }
        EventAuditLogger::getInstance()->newEvent('security', $authUser, $authProvider, 1, 'Clinical Co-Pilot: eval gate run from dashboard');
    } elseif ($action === 'run_load_test') {
        // In-process concurrency bench at 50 workers (real CPU/latency/throughput
        // of the module's hot paths). The CLI bench spawns its workers via
        // proc_open (no pcntl needed — it runs in the OpenEMR/Railway container),
        // so shell out to it and read its JSON. A short, bounded burst; the
        // daily/hourly $ caps + breaker are untouched.
        $benchScript = dirname(__DIR__) . '/ops/load/bench/bench.php';
        $cmd = 'php ' . escapeshellarg($benchScript)
            . ' guideline_retrieval_sparse verify_chat --concurrency=50 --duration=4 --warmup=100 --json 2>/dev/null';
        $raw = function_exists('shell_exec') ? @shell_exec($cmd) : null;
        $decoded = is_string($raw) ? json_decode(trim($raw), true) : null;
        if (is_array($decoded) && $decoded !== []) {
            $loadTestResult = $decoded;
        } else {
            $loadTestError = 'Load test could not run (needs CLI php with shell_exec enabled). Run it from a shell: ops/load/bench/run-load-test.sh.';
        }
        EventAuditLogger::getInstance()->newEvent('security', $authUser, $authProvider, 1, 'Clinical Co-Pilot: in-process load test run from dashboard');
    } elseif ($action === 'enable_load_test') {
        $configStore->enableLoadTestMode($authUser, 30);
        EventAuditLogger::getInstance()->newEvent('security', $authUser, $authProvider, 1, 'Clinical Co-Pilot: load-test mode ENABLED (per-user chat caps lifted 30m)');
    } elseif ($action === 'disable_load_test') {
        $configStore->disableLoadTestMode($authUser);
        EventAuditLogger::getInstance()->newEvent('security', $authUser, $authProvider, 1, 'Clinical Co-Pilot: load-test mode disabled');
    }
}

EventAuditLogger::getInstance()->newEvent('security', $authUser, $authProvider, 1, 'Clinical Co-Pilot: observability dashboard viewed');

$metricsService = new MetricsService();
$windowHours = is_numeric($_GET['window_hours'] ?? null) ? max(1, min(8760, (int)$_GET['window_hours'])) : 24;
$since = new DateTimeImmutable("-{$windowHours} hours");

$correlationId = (string)($_GET['correlation_id'] ?? '');
$payloadRef = (string)($_GET['payload_ref'] ?? '');
$kindFilter = ($_GET['kind'] ?? '') !== '' ? (string)$_GET['kind'] : null;
$statusFilter = ($_GET['status'] ?? '') !== '' ? (string)$_GET['status'] : null;

$waterfall = null;
$payloadRefs = [];
if ($correlationId !== '') {
    $waterfall = $metricsService->spanWaterfall($correlationId);
    $payloadRefs = (new TracePayloadStore())->forCorrelationId($correlationId);
    // Drilling into one request's spans can surface pid-scoped clinical
    // data (facts, prompt text) -- audited as a chart-adjacent view, same as
    // any other chart-data access (ARCHITECTURE.md §4).
    EventAuditLogger::getInstance()->newEvent('patient-record', $authUser, $authProvider, 1, "Clinical Co-Pilot dashboard span drill-down, correlation_id={$correlationId}");
}

$payload = $payloadRef !== '' ? (new TracePayloadStore())->fetch($payloadRef) : null;
$payloadJson = $payload !== null ? json_encode($payload['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null;

$metrics = $metricsService->overview($since);

// Verification panel, named and enforcement-aware. The V1-V6 content gate is a
// runtime policy (VerificationPolicy): when it is NOT enforced, the checks still
// RUN and record verdicts (so the ledger shows what would have failed) but they
// do not block/degrade a turn — so a "fail" is advisory, not a blocked turn.
// Patient identity (V3) is enforced unconditionally (cross-patient PHI guard).
$verifyTally = is_array($metrics['verification_pass_fail_by_check'] ?? null) ? $metrics['verification_pass_fail_by_check'] : [];
$verifyGateEnforced = VerificationPolicy::gateEnforced();
$verificationChecks = [];
foreach (CheckId::cases() as $check) {
    $counts = is_array($verifyTally[$check->value] ?? null) ? $verifyTally[$check->value] : ['passed' => 0, 'failed' => 0];
    $verificationChecks[] = [
        'code' => $check->value,
        'label' => $check->label(),
        'passed' => (int)($counts['passed'] ?? 0),
        'failed' => (int)($counts['failed'] ?? 0),
        'enforced' => $verifyGateEnforced || $check === CheckId::PatientIdentity,
    ];
}

$dashboardUrl = OEGlobalsBag::getInstance()->getWebRoot() . '/interface/modules/custom_modules/oe-module-clinical-copilot/public/dashboard.php';

// Self-referential URL that carries THIS session's already-resolved site id.
// Two 400-avoidance properties, both hinging on it being the session's OWN
// site (never a hardcoded guess):
//   1. It always equals $_SESSION['site_id'], so globals.php's multisite guard
//      (interface/globals.php:305) can never clear the session on a mismatch —
//      the earlier bug where a hardcoded ?site=default cleared a non-default
//      session, cascading every later POST into a MissingSiteIdException (a
//      BadRequestHttpException => HTTP 400).
//   2. On the unattended auto-refresh, if the session HAS since expired, the
//      site param lets globals resolve the site and redirect to the login
//      screen (clean) instead of throwing that 400.
$currentSite = (string)($session->get('site_id') ?? '');
if ($currentSite === '' || preg_match('/[^A-Za-z0-9\-.]/', $currentSite) === 1) {
    $currentSite = 'default';
}
$dashboardUrlSite = $dashboardUrl . '?site=' . rawurlencode($currentSite);

$templateVars = [
    'window_hours' => $windowHours,
    'metrics' => $metrics,
    'verification_checks' => $verificationChecks,
    'verify_gate_enforced' => $verifyGateEnforced,
    'alerts' => $metricsService->recentFiredAlerts(),
    'breaker' => (new CadenceCircuitBreaker())->snapshot(),
    'limits' => $configStore->limits(),
    'ready' => (new ReadyCheck())->check(),
    // The separate, PHI-free medical-knowledge store the summarizer's RAG pulls
    // from (or 'not_configured' => running on the in-repo offline corpus).
    'knowledge' => KnowledgeBaseStatus::createDefault()->snapshot(),
    'requests' => $metricsService->requestList($kindFilter, $statusFilter, 100),
    'kind_filter' => $kindFilter,
    'status_filter' => $statusFilter,
    'correlation_id' => $correlationId,
    'waterfall' => $waterfall,
    'payload_refs' => $payloadRefs,
    'payload_ref' => $payloadRef,
    'payload' => $payload,
    'payload_json' => $payloadJson,
    'eval_result' => $evalResult,
    // Per-category roll-up of the golden set (extraction / retrieval / refusal /
    // missing_data) derived from the run's PHI-free per-case rubric booleans, so
    // the panel shows the full set is exercised, not just aggregate rubric rates.
    'eval_category_summary' => evalCategorySummary($evalResult),
    'eval_error' => $evalError,
    'load_test_result' => $loadTestResult,
    'load_test_error' => $loadTestError,
    'load_test_mode' => $configStore->loadTestMode(),
    'dashboard_url' => $dashboardUrl,
    // Same URL plus this session's own resolved site id — used for the
    // unattended auto-refresh and the POST forms so neither can 400 on an
    // expired/mismatched session (see $dashboardUrlSite above). Drill-down GET
    // links keep the bare URL: they only fire inside an active session.
    'dashboard_url_site' => $dashboardUrlSite,
    // Auto-refresh cadence. Low-volume app, so 2 minutes (not 30s).
    'refresh_seconds' => 120,
];

$twig = (new TwigContainer(dirname(__DIR__) . '/templates', OEGlobalsBag::getInstance()->getKernel()))->getTwig();
echo $twig->render('oe-module-clinical-copilot/dashboard.html.twig', $templateVars);

/**
 * Roll the eval run's per-case rubric booleans up by category (extraction,
 * retrieval, refusal, missing_data). Input is the PHI-free `cases` list from
 * EvalGate::run() (ids/categories/booleans only), so the summary carries no
 * case content. Returns [] when no run has happened yet.
 *
 * @param array<string, mixed>|null $evalResult
 * @return array<string, array{total: int, passed: int}>
 */
function evalCategorySummary(?array $evalResult): array
{
    $cases = is_array($evalResult['cases'] ?? null) ? $evalResult['cases'] : [];
    $summary = [];
    foreach ($cases as $case) {
        if (!is_array($case)) {
            continue;
        }
        $category = (string)($case['category'] ?? '');
        if ($category === '') {
            continue;
        }
        $rubrics = is_array($case['rubrics'] ?? null) ? $case['rubrics'] : [];
        $allPassed = !in_array(false, $rubrics, true);
        $summary[$category] ??= ['total' => 0, 'passed' => 0];
        $summary[$category]['total']++;
        if ($allPassed) {
            $summary[$category]['passed']++;
        }
    }
    ksort($summary);

    return $summary;
}
