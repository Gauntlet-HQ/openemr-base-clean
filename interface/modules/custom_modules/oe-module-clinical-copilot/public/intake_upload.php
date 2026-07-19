<?php

/**
 * Clinical Co-Pilot -- Week 2 intake: create a patient from an intake PDF.
 *
 * Deferred-save flow: upload -> extract (persist NOTHING) -> a two-pane review
 * screen (prefilled new-patient fields on the left, the source PDF on the right)
 * -> the human edits and clicks Save, and only THEN is the patient created, the
 * PDF stored, and reviewed allergies/medications written to the chart. Nothing
 * is written before the human confirms, so a failed/absent extraction degrades
 * to a blank form to fill by hand rather than crashing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Page bootstrap contract (docs/build-notes.md): flags set BEFORE globals.php.
$ignoreAuth = false;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use OpenEMR\Modules\ClinicalCopilot\Controller\IngestController;
use OpenEMR\Modules\ClinicalCopilot\Ingest\IntakeFormTemplate;
use OpenEMR\Modules\ClinicalCopilot\Ingest\UploadedDocument;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// A body over PHP's post_max_size makes PHP discard $_POST/$_FILES (including the
// CSRF token), which would otherwise die on the missing token. Detect it and
// return a clear size error instead. (The carried base64 PDF can approach this.)
if ($isPost && $_POST === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    echo xlt('That upload was too large to process. Please use a smaller file.');
    exit;
}

if ($isPost) {
    CsrfUtils::checkCsrfInput(INPUT_POST, $session, dieOnFail: true);
}

if (!AclMain::aclCheckCore('patients', 'med') || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    echo xlt('Access denied');
    exit;
}

$authUser = (string)($session->get('authUser') ?? '');
$webRoot = OEGlobalsBag::getInstance()->getWebRoot();
$moduleBase = $webRoot . '/interface/modules/custom_modules/oe-module-clinical-copilot/public';
$postUrl = $moduleBase . '/intake_upload.php';
$action = $isPost ? (string)($_POST['action'] ?? '') : '';

if ($action === 'upload') {
    $entry = $_FILES['document'] ?? null;
    $upload = UploadedDocument::fromFilesEntry($entry);
    if ($upload === null) {
        renderIntakeForm($moduleBase, $webRoot, error: UploadedDocument::describeRejection($entry));
        exit;
    }

    // Extract only -- no patient, no draft, no writes. Degrades to empty on any
    // model failure (the form just renders blank for manual entry).
    $visionUsed = false;
    $schemaRejected = false;
    $citations = [];
    try {
        $preview = IngestController::createDefault()->previewIntake($upload->bytes, $upload->mimeType);
        $values = is_array($preview['fields'] ?? null) ? $preview['fields'] : [];
        // Optional per-field source citations (page/quote) the model volunteered.
        // Often empty — a citation-free intake extraction is fully successful.
        $citations = is_array($preview['citations'] ?? null) ? $preview['citations'] : [];
        $visionUsed = (bool)($preview['vision_used'] ?? false);
        $schemaRejected = (bool)($preview['schema_rejected'] ?? false);
    } catch (\Throwable $e) {
        (new SystemLogger())->error('ClinicalCopilot: intake preview failed', ['exception' => $e]);
        $values = [];
    }

    // Turn a silent blank form into a diagnostic: say WHY nothing prefilled so a
    // reviewer (and we) can tell "model not configured" from "read but rejected"
    // from "nothing on the page" — instead of guessing at an empty screen.
    $anyValue = false;
    foreach ($values as $v) {
        if (is_string($v) && trim($v) !== '') {
            $anyValue = true;
            break;
        }
    }
    $note = '';
    if (!$anyValue) {
        if (!$visionUsed) {
            $note = xl('Automated extraction is unavailable (the AI model is not configured or did not respond). Fill the form in by hand, then Save.');
        } elseif ($schemaRejected) {
            $note = xl('The document was read but the extracted data did not match the expected format. Fill in or correct the fields against the document, then Save.');
        } else {
            $note = xl('No values could be read from this document. Fill the form in by hand, then Save.');
        }
    }

    // The uploaded PDF rides through the review page as base64 (shown in the
    // iframe, carried in a hidden field, stored on Save). We deliberately do NOT
    // add a server-side temp-file stash for this: intake forms and lab reports
    // are a few pages, nowhere near the hundreds of pages Gemini can ingest, so
    // the round-trip is cheap and the oversize edge is already handled (the 413
    // guard above). The priority for this module is extraction matching and
    // human correction, not large-document scale — so the simpler path wins.
    renderReview($postUrl, $values, base64_encode($upload->bytes), $upload->mimeType, $upload->filename, [], $note, $citations);
    exit;
}

if ($action === 'save') {
    $values = readFieldValues();
    $pdfB64 = (string)($_POST['pdf_b64'] ?? '');
    // pdf_mime rides through a hidden field, so re-validate it against the upload
    // allowlist on save — never trust it for the stored document type or the
    // review iframe's data: URI.
    $pdfMime = safePdfMime((string)($_POST['pdf_mime'] ?? 'application/pdf'));
    $pdfName = (string)($_POST['pdf_name'] ?? 'intake.pdf');
    $pdfBytes = $pdfB64 !== '' ? (string)base64_decode($pdfB64, true) : '';

    $clinical = [
        'allergies' => $values['allergies'] ?? null,
        'medications' => $values['current_medications'] ?? null,
    ];

    try {
        $result = IngestController::createDefault()
            ->commitReviewedIntake($values, $clinical, $pdfBytes, $pdfName, $pdfMime, $authUser);
    } catch (\Throwable $e) {
        (new SystemLogger())->error('ClinicalCopilot: intake save failed', ['exception' => $e]);
        renderReview($postUrl, $values, $pdfB64, $pdfMime, $pdfName, [xl('The patient could not be saved right now. Please try again.')]);
        exit;
    }

    if ($result['pid'] !== null) {
        // Land on the new patient's chart (core demographics summary, which
        // accepts set_pid to make them the active patient).
        header('Location: ' . $webRoot . '/interface/patient_file/summary/demographics.php?set_pid=' . (int)$result['pid']);
        exit;
    }

    renderReview($postUrl, $values, $pdfB64, $pdfMime, $pdfName, $result['errors']);
    exit;
}

renderIntakeForm($moduleBase, $webRoot);

/** Re-validate the round-tripped mime against the upload allowlist; else PDF. */
function safePdfMime(string $mime): string
{
    $allowed = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];

    return in_array($mime, $allowed, true) ? $mime : 'application/pdf';
}

/**
 * @return array<string, string>
 */
function readFieldValues(): array
{
    $raw = $_POST['field'] ?? [];
    if (!is_array($raw)) {
        return [];
    }

    $values = [];
    foreach ($raw as $key => $value) {
        if (is_string($key) && is_string($value)) {
            $values[$key] = trim($value);
        }
    }

    return $values;
}

function renderIntakeForm(string $moduleBase, string $webRoot, string $error = ''): void
{
    echo twigEnv()->render('oe-module-clinical-copilot/intake_upload.html.twig', [
        'post_url' => $moduleBase . '/intake_upload.php',
        'pdf_url' => $moduleBase . '/intake_form_pdf.php',
        'web_root' => $webRoot,
        'error' => $error,
        'llm_configured' => LlmRuntimeConfig::llmConfigured(),
        'max_mb' => (int)floor(UploadedDocument::MAX_DOCUMENT_BYTES / (1024 * 1024)),
    ]);
}

/**
 * Render the two-pane review screen: prefilled new-patient fields (left), the
 * source PDF (right), all sections expanded.
 *
 * $citations is the sparse map of OPTIONAL model-volunteered source citations
 * (page/quote per field_key). Purely advisory display: a field without one
 * renders exactly as before, and the save flow never reads them (they are not
 * round-tripped through the error re-render — the values are what matter).
 *
 * @param array<string, string> $values    field_key => current value
 * @param list<string>          $errors
 * @param array<string, mixed>  $citations field_key => {page, quote}
 */
function renderReview(string $postUrl, array $values, string $pdfB64, string $pdfMime, string $pdfName, array $errors, string $note = '', array $citations = []): void
{
    // Build the grouped field view model from the single source of truth that
    // also drives the printable form (so the screen and the PDF always match).
    $groups = [];
    foreach (IntakeFormTemplate::sections() as $section) {
        $fields = [];
        foreach ($section['fields'] as $field) {
            $key = $field['key'];
            $cite = is_array($citations[$key] ?? null) ? $citations[$key] : [];
            $citePage = $cite['page'] ?? null;
            $citeQuote = $cite['quote'] ?? null;
            $fields[] = [
                'key' => $key,
                'label' => $field['label'],
                'is_demographic' => $field['patient_data'] !== null,
                'lines' => $field['lines'],
                'value' => $values[$key] ?? '',
                // Optional citation, display-only: null/'' when the model did
                // not volunteer one (the normal case — never an error).
                'cite_page' => is_int($citePage) && $citePage >= 1 ? $citePage : null,
                'cite_quote' => is_string($citeQuote) ? $citeQuote : '',
            ];
        }
        $groups[] = ['section' => $section['section'], 'fields' => $fields];
    }

    echo twigEnv()->render('oe-module-clinical-copilot/intake_review.html.twig', [
        'post_url' => $postUrl,
        'groups' => $groups,
        'errors' => $errors,
        'pdf_data_uri' => 'data:' . $pdfMime . ';base64,' . $pdfB64,
        'pdf_b64' => $pdfB64,
        'pdf_mime' => $pdfMime,
        'pdf_name' => $pdfName,
        'sex_options' => ['Male', 'Female', 'Unknown', 'Other'],
        'extraction_note' => $note,
    ]);
}

function twigEnv(): \Twig\Environment
{
    return (new TwigContainer(dirname(__DIR__) . '/templates', OEGlobalsBag::getInstance()->getKernel()))->getTwig();
}
