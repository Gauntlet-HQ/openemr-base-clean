<?php

/**
 * Clinical Co-Pilot -- stream a blank OpenEMR-compliant intake form as a PDF.
 *
 * The front of the intake loop: staff download this, the patient fills it in,
 * staff scan it and upload it via intake_upload.php -> Gemini extraction ->
 * review -> chart. The field labels are the exact intake_form.schema.json enum
 * values, so the scanned form maps 1:1 onto the strict extraction schema.
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
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Ingest\IntakeFormTemplate;
use OpenEMR\Pdf\Config_Mpdf;

// Same gate as the intake upload page it is launched from.
if (!AclMain::aclCheckCore('patients', 'med') || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    echo xlt('Access denied');
    exit;
}

// ?sample=1 renders a FILLED synthetic form (OPEN-1, no real PHI) so staff can
// download it and re-upload it to exercise the whole intake pipeline end-to-end
// without hand-scanning a form. Default is the blank form patients fill in.
$sample = ($_GET['sample'] ?? '') !== '';
$filename = $sample ? 'sample-intake-form.pdf' : 'patient-intake-form.pdf';

try {
    $mpdf = new \Mpdf\Mpdf(Config_Mpdf::getConfigMpdf());
    $mpdf->SetTitle($sample ? 'Sample Patient Intake Form' : 'Patient Intake Form');
    $mpdf->WriteHTML(IntakeFormTemplate::html($sample ? IntakeFormTemplate::sample() : []));

    header('Content-Type: application/pdf');
    // "inline" so it opens in the browser's PDF viewer to print; the filename is
    // used if the user chooses Save.
    header('Content-Disposition: inline; filename="' . $filename . '"');
    echo $mpdf->Output($filename, \Mpdf\Output\Destination::STRING_RETURN);
} catch (\Throwable $e) {
    (new SystemLogger())->error('ClinicalCopilot: intake form PDF generation failed', ['exception' => $e]);
    http_response_code(500);
    echo xlt('Could not generate the intake form. Please try again or contact your administrator.');
}
