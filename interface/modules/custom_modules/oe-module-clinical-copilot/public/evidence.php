<?php

/**
 * Clinical Co-Pilot -- Week 2 guideline evidence (augments the summary/chat).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Read-only page (no writes, no CSRF -- same posture as status.php).
$ignoreAuth = false;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Rag\PatientEvidenceService;

$session = SessionWrapperFactory::getInstance()->getActiveSession();

if (!AclMain::aclCheckCore('patients', 'med') || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    echo xlt('Access denied');
    exit;
}

$webRoot = OEGlobalsBag::getInstance()->getWebRoot();
$moduleBase = $webRoot . '/interface/modules/custom_modules/oe-module-clinical-copilot/public';

$rawPid = $_GET['pid'] ?? null;
$pid = is_numeric($rawPid) ? (int)$rawPid : 0;

// Selected topics from the query string (?topics=a1c,lipids). Default: all,
// so the page is useful immediately; a caller may narrow to a patient's
// notable analytes.
$selected = [];
$topicsParam = $_GET['topics'] ?? '';
if (is_string($topicsParam) && $topicsParam !== '') {
    foreach (explode(',', $topicsParam) as $key) {
        $key = trim($key);
        if (PatientEvidenceService::isTopic($key)) {
            $selected[] = $key;
        }
    }
}
if ($selected === []) {
    $selected = array_map(static fn (array $t): string => $t['key'], PatientEvidenceService::availableTopics());
}

$groups = PatientEvidenceService::createDefault()->forTopics($selected);

$twig = (new TwigContainer(dirname(__DIR__) . '/templates', OEGlobalsBag::getInstance()->getKernel()))->getTwig();
echo $twig->render('oe-module-clinical-copilot/evidence.html.twig', [
    'pid' => $pid,
    'doc_url' => $pid > 0 ? $moduleBase . '/doc.php?pid=' . $pid : $moduleBase . '/doc.php',
    'evidence_url' => $moduleBase . '/evidence.php',
    'topics' => PatientEvidenceService::availableTopics(),
    'selected' => $selected,
    'groups' => $groups,
]);
