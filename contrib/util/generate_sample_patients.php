<?php

/**
 * generate_sample_patients.php
 *
 * CLI utility that seeds the database with realistic sample patients for
 * development and demo use. Generates demographics (including date of birth
 * and a synthetic SSN), intake/social history, encounters, problem lists,
 * prescriptions, and bloodwork with configurable distributions.
 *
 * Distributions:
 *   - 85% established patients (have prior history: past encounters + problems)
 *   - 15% new patients (no prior history), split into:
 *       - 10% presenting with a visit condition (recorded on today's intake)
 *       -  5% wellness visits (no problems at all)
 *
 * Prescriptions and bloodwork percentages apply to ALL patients (new patients
 * may carry current/recently-discontinued medications from a prior provider
 * and receive screening labs at their intake visit even with no prior history
 * at this practice):
 *   - 90% of all patients have >=1 prescription (active or recently
 *     discontinued)
 *   - every patient gets a bloodwork panel; 87% are normal and 13% have an
 *     abnormality (high A1c, low testosterone, or high WBC)
 *
 * USAGE (run inside the openemr container):
 *   php contrib/util/generate_sample_patients.php [site] [count] [--dry-run] [--batch-size N]
 *   e.g.  php contrib/util/generate_sample_patients.php default 100
 *         php contrib/util/generate_sample_patients.php default 100 --dry-run
 *         php contrib/util/generate_sample_patients.php default 5000 --batch-size 250
 *
 * --dry-run reports the planned distribution without writing anything.
 *
 * --batch-size N commits every N patients (useful for large runs). If a batch
 * fails, only that in-flight batch is rolled back; previously committed
 * batches remain. With no --batch-size (or 0), the entire run is a single
 * transaction: any mid-run failure rolls back everything (all-or-nothing).
 *
 * Generated patients are tagged with a "SMPL" pubpid prefix so they can be
 * found or removed later, e.g.:
 *   DELETE FROM patient_data WHERE pubpid LIKE 'SMPL%';
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This script may only be run from the command line.\n");
}

$dryRun = false;
$batchSize = 0; // 0 = one transaction for the whole run (all-or-nothing)
$positional = [];
$args = array_slice($argv, 1);
for ($a = 0; $a < count($args); $a++) {
    $arg = $args[$a];
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--batch-size') {
        $batchSize = max(0, (int) ($args[$a + 1] ?? 0));
        $a++;
    } elseif (str_starts_with($arg, '--batch-size=')) {
        $batchSize = max(0, (int) substr($arg, strlen('--batch-size=')));
    } else {
        $positional[] = $arg;
    }
}
$site = $positional[0] ?? 'default';
$count = isset($positional[1]) ? max(1, (int) $positional[1]) : 100;

$_GET['site'] = $site;
$ignoreAuth = true;
$sessionAllowWrite = true;
require_once(__DIR__ . "/../../interface/globals.php");
require_once(__DIR__ . "/../../library/forms.inc.php");

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\PatientService;

// Author context for forms/records created by this script.
$_SESSION['authUser'] ??= 'admin';
$_SESSION['authProvider'] ??= 'Default';

/* -------------------------------------------------------------------------
 * Data pools
 * ---------------------------------------------------------------------- */

$firstNamesMale = ['James', 'Robert', 'John', 'Michael', 'David', 'William', 'Richard', 'Joseph', 'Thomas', 'Christopher', 'Daniel', 'Matthew', 'Anthony', 'Mark', 'Steven', 'Andrew', 'Kenneth', 'Joshua', 'Kevin', 'Brian'];
$firstNamesFemale = ['Mary', 'Patricia', 'Jennifer', 'Linda', 'Elizabeth', 'Barbara', 'Susan', 'Jessica', 'Sarah', 'Karen', 'Nancy', 'Lisa', 'Betty', 'Margaret', 'Sandra', 'Ashley', 'Kimberly', 'Emily', 'Donna', 'Michelle'];
$lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Perez', 'Thompson', 'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson'];
$middleInitials = ['A', 'B', 'C', 'D', 'E', 'J', 'L', 'M', 'R', 'T', ''];

$raceCodes = ['white', 'black_or_afri_amer', 'Asian', 'amer_ind_or_alaska_native', 'native_hawai_or_pac_island', 'decline_to_specify'];
$ethnicityCodes = ['hisp_or_latin', 'not_hisp_or_latin', 'decline_to_specify'];
$maritalCodes = ['married', 'single', 'divorced', 'widowed', 'separated', 'domestic partner'];

$cities = [
    ['Springfield', 'IL', '62701'], ['Portland', 'OR', '97201'], ['Austin', 'TX', '78701'],
    ['Columbus', 'OH', '43201'], ['Denver', 'CO', '80201'], ['Raleigh', 'NC', '27601'],
    ['Madison', 'WI', '53703'], ['Tucson', 'AZ', '85701'], ['Albany', 'NY', '12201'],
    ['Fresno', 'CA', '93701'],
];
$streets = ['Main St', 'Oak Ave', 'Maple Dr', 'Cedar Ln', 'Elm St', 'Washington Blvd', 'Park Ave', 'Lake Rd', 'Hill St', 'Sunset Dr'];

$tobaccoOptions = ['Never smoker', 'Former smoker, quit 2015', 'Current every day smoker', 'Never used tobacco'];
$alcoholOptions = ['None', 'Social, 1-2 drinks/week', 'Moderate, 3-5 drinks/week', 'Denies alcohol use'];
$exerciseOptions = ['Sedentary', 'Walks 30 min daily', 'Runs 3x/week', 'Gym 4x/week', 'Light activity'];
$drugUseOptions = ['Denies recreational drug use', 'None reported', 'Former cannabis use'];

// Conditions used both as new-patient visit reasons and established problems.
$conditions = [
    ['Essential hypertension', 'ICD10:I10'],
    ['Type 2 diabetes mellitus', 'ICD10:E11.9'],
    ['Acute bronchitis', 'ICD10:J20.9'],
    ['Low back pain', 'ICD10:M54.5'],
    ['Migraine, unspecified', 'ICD10:G43.909'],
    ['Gastro-esophageal reflux disease', 'ICD10:K21.9'],
    ['Generalized anxiety disorder', 'ICD10:F41.1'],
    ['Urinary tract infection', 'ICD10:N39.0'],
    ['Asthma, unspecified', 'ICD10:J45.909'],
    ['Hyperlipidemia', 'ICD10:E78.5'],
];

// drug, dosage, form, route
$drugs = [
    ['Lisinopril', '10 mg', 'tablet', 'oral'],
    ['Metformin', '500 mg', 'tablet', 'oral'],
    ['Atorvastatin', '20 mg', 'tablet', 'oral'],
    ['Amlodipine', '5 mg', 'tablet', 'oral'],
    ['Omeprazole', '20 mg', 'capsule', 'oral'],
    ['Levothyroxine', '75 mcg', 'tablet', 'oral'],
    ['Albuterol', '90 mcg', 'inhaler', 'inhalation'],
    ['Sertraline', '50 mg', 'tablet', 'oral'],
    ['Ibuprofen', '600 mg', 'tablet', 'oral'],
    ['Amoxicillin', '500 mg', 'capsule', 'oral'],
];

/* -------------------------------------------------------------------------
 * Distribution buckets (exact counts, shuffled)
 * ---------------------------------------------------------------------- */

/**
 * Build a shuffled array of labels with exact counts.
 *
 * @param array<string, int> $counts
 * @return list<string>
 */
function buildShuffledBucket(array $counts): array
{
    $bucket = [];
    foreach ($counts as $label => $qty) {
        for ($i = 0; $i < $qty; $i++) {
            $bucket[] = $label;
        }
    }
    shuffle($bucket);
    return $bucket;
}

$numNew = (int) round($count * 0.15);
$numNewCondition = (int) round($count * 0.10);
$numNewCondition = min($numNewCondition, $numNew);
$numNewWellness = $numNew - $numNewCondition;
$numEstablished = $count - $numNew;

$categoryBucket = buildShuffledBucket([
    'established' => $numEstablished,
    'new_condition' => $numNewCondition,
    'new_wellness' => $numNewWellness,
]);

// Prescriptions and bloodwork apply to ALL patients, so these buckets are
// sized to the full count and consumed in patient order via $i in the main
// loop. Every patient gets a bloodwork panel; 13% of all patients are flagged
// abnormal.
$numMeds = (int) round($count * 0.90);
$medsBucket = buildShuffledBucket([
    'yes' => $numMeds,
    'no' => $count - $numMeds,
]);

$numAbnormal = (int) round($count * 0.13);
$abnormalBucket = buildShuffledBucket([
    'yes' => $numAbnormal,
    'no' => $count - $numAbnormal,
]);

/* -------------------------------------------------------------------------
 * Environment lookups (provider + facility)
 * ---------------------------------------------------------------------- */

$providerRow = sqlQuery("SELECT id FROM users WHERE authorized = 1 AND active = 1 ORDER BY id LIMIT 1");
$providerId = (int) (is_array($providerRow) ? ($providerRow['id'] ?? 1) : 1);

$facilityRow = sqlQuery("SELECT id, name FROM facility ORDER BY id LIMIT 1");
$facilityId = (int) (is_array($facilityRow) ? ($facilityRow['id'] ?? 3) : 3);
$facilityName = is_array($facilityRow) ? (string) ($facilityRow['name'] ?? 'Your Clinic Name Here') : 'Your Clinic Name Here';

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

function randomDateTime(int $daysBackMin, int $daysBackMax): string
{
    $daysBack = random_int($daysBackMin, $daysBackMax);
    return date('Y-m-d H:i:s', strtotime("-{$daysBack} days"));
}

/**
 * Create an encounter and its forms row; returns the business encounter number.
 */
function createEncounter(int $pid, string $dateTime, string $reason, int $providerId, int $facilityId, string $facilityName): int
{
    $uuid = UuidRegistry::getRegistryForTable('form_encounter')->createUuid();
    $encounter = QueryUtils::generateId();
    $formEncounterId = sqlInsert(
        "INSERT INTO `form_encounter` SET `uuid` = ?, `date` = ?, `reason` = ?, `facility` = ?, "
        . "`facility_id` = ?, `pid` = ?, `encounter` = ?, `pc_catid` = 5, `provider_id` = ?, `class_code` = 'AMB'",
        [$uuid, $dateTime, $reason, $facilityName, $facilityId, $pid, $encounter, $providerId]
    );
    addForm($encounter, "New Patient Encounter", $formEncounterId, "newpatient", $pid, '1', $dateTime, 'admin', 'Default');

    return (int) $encounter;
}

function createProblem(int $pid, string $title, string $diagnosis, string $begdate): void
{
    $uuid = UuidRegistry::getRegistryForTable('lists')->createUuid();
    sqlInsert(
        "INSERT INTO `lists` SET `uuid` = ?, `date` = NOW(), `type` = 'medical_problem', `title` = ?, "
        . "`diagnosis` = ?, `begdate` = ?, `activity` = 1, `outcome` = 0, `subtype` = '', `pid` = ?",
        [$uuid, $title, $diagnosis, $begdate, $pid]
    );
}

/**
 * @param array{0: string, 1: string, 2: string, 3: string} $drug
 */
function createPrescription(int $pid, array $drug, int $providerId, bool $discontinued): void
{
    $uuid = UuidRegistry::getRegistryForTable('prescriptions')->createUuid();
    $startDate = date('Y-m-d', strtotime('-' . random_int(30, 720) . ' days'));
    $endDate = $discontinued ? date('Y-m-d', strtotime('-' . random_int(1, 60) . ' days')) : null;
    $active = $discontinued ? 0 : 1;
    $note = $discontinued ? 'Recently discontinued.' : 'Active medication.';

    sqlInsert(
        "INSERT INTO `prescriptions` SET `uuid` = ?, `patient_id` = ?, `date_added` = NOW(), `provider_id` = ?, "
        . "`start_date` = ?, `drug` = ?, `dosage` = ?, `quantity` = ?, `route` = ?, `form` = ?, "
        . "`active` = ?, `end_date` = ?, `note` = ?, `txDate` = ?",
        [
            $uuid, $pid, $providerId, $startDate, $drug[0], $drug[1], '30', $drug[3], $drug[2],
            $active, $endDate, $note, $startDate,
        ]
    );
}

/**
 * Create a bloodwork panel (order + code + report + 3 results). When
 * $abnormalAnalyte is set, that analyte is flagged abnormal.
 */
function createBloodwork(int $pid, int $encounter, int $providerId, string $collectedDateTime, ?string $abnormalAnalyte): void
{
    $orderUuid = UuidRegistry::getRegistryForTable('procedure_order')->createUuid();
    $orderId = sqlInsert(
        "INSERT INTO `procedure_order` SET `uuid` = ?, `provider_id` = ?, `patient_id` = ?, `encounter_id` = ?, "
        . "`date_collected` = ?, `date_ordered` = ?, `order_status` = 'complete', `activity` = 1, `lab_id` = 0, "
        . "`procedure_order_type` = 'laboratory_test'",
        [$orderUuid, $providerId, $pid, $encounter, $collectedDateTime, $collectedDateTime]
    );

    sqlInsert(
        "INSERT INTO `procedure_order_code` SET `procedure_order_id` = ?, `procedure_order_seq` = 1, "
        . "`procedure_code` = 'CBCMP', `procedure_name` = 'Blood Panel (CBC + Metabolic)', "
        . "`procedure_source` = '1', `diagnoses` = '', `do_not_send` = 0",
        [$orderId]
    );

    $reportUuid = UuidRegistry::getRegistryForTable('procedure_report')->createUuid();
    $reportId = sqlInsert(
        "INSERT INTO `procedure_report` SET `uuid` = ?, `procedure_order_id` = ?, `procedure_order_seq` = 1, "
        . "`date_collected` = ?, `date_report` = ?, `report_status` = 'final', `review_status` = 'reviewed'",
        [$reportUuid, $orderId, $collectedDateTime, $collectedDateTime]
    );

    // analyte => [loinc, name, units, range, normalValue, abnormalValue, abnormalFlag]
    $analytes = [
        'a1c' => ['4548-4', 'Hemoglobin A1c', '%', '4.0-5.6', '5.2', '7.8', 'high'],
        'testosterone' => ['2986-8', 'Testosterone, Total', 'ng/dL', '300-1000', '560', '190', 'low'],
        'wbc' => ['6690-2', 'WBC', '10*3/uL', '4.5-11.0', '7.1', '15.4', 'high'],
    ];

    foreach ($analytes as $key => $a) {
        $isAbnormal = ($key === $abnormalAnalyte);
        $value = $isAbnormal ? $a[5] : $a[4];
        $flag = $isAbnormal ? $a[6] : 'no';
        $resultUuid = UuidRegistry::getRegistryForTable('procedure_result')->createUuid();
        sqlInsert(
            "INSERT INTO `procedure_result` SET `uuid` = ?, `procedure_report_id` = ?, `result_code` = ?, "
            . "`result_text` = ?, `date` = ?, `units` = ?, `result` = ?, `range` = ?, `abnormal` = ?, "
            . "`result_status` = 'final'",
            [$resultUuid, $reportId, $a[0], $a[1], $collectedDateTime, $a[2], $value, $a[3], $flag]
        );
    }
}

/* -------------------------------------------------------------------------
 * Main generation loop
 * ---------------------------------------------------------------------- */

$patientService = new PatientService();
$created = 0;
$committed = 0;
$summary = ['established' => 0, 'new_condition' => 0, 'new_wellness' => 0, 'with_meds' => 0, 'abnormal_labs' => 0];

// A batch size of 0 means one transaction spanning the whole run.
$effectiveBatch = ($batchSize > 0) ? $batchSize : $count;
$txOpen = false;
$writtenInBatch = 0;

echo ($dryRun ? "[DRY RUN] " : "") . "Generating {$count} sample patients for site '{$site}'"
    . (($batchSize > 0 && !$dryRun) ? " (committing every {$batchSize})" : "") . "...\n";

try {
    for ($i = 0; $i < $count; $i++) {
        $category = $categoryBucket[$i];
        $isEstablished = ($category === 'established');

        // Prescriptions and bloodwork apply to every patient, consumed in
        // patient order so the population percentages stay exact.
        $hasMeds = ($medsBucket[$i] === 'yes');
        $isAbnormal = ($abnormalBucket[$i] === 'yes');
        $getsBloodwork = true;

        // Tally the intended distribution (also reported in dry-run mode).
        $summary[$category]++;
        if ($hasMeds) {
            $summary['with_meds']++;
        }
        if ($isAbnormal) {
            $summary['abnormal_labs']++;
        }

        if ($dryRun) {
            $created++;
            continue;
        }

        if (!$txOpen) {
            sqlBeginTrans();
            $txOpen = true;
            $writtenInBatch = 0;
        }

        $isMale = (bool) random_int(0, 1);
        $sex = $isMale ? 'Male' : 'Female';
        $fname = $isMale
            ? $firstNamesMale[array_rand($firstNamesMale)]
            : $firstNamesFemale[array_rand($firstNamesFemale)];
        $lname = $lastNames[array_rand($lastNames)];
        $mname = $middleInitials[array_rand($middleInitials)];
        $age = random_int(18, 88);
        $dob = date('Y-m-d', strtotime("-{$age} years -" . random_int(0, 364) . " days"));
        [$city, $state, $zip] = $cities[array_rand($cities)];

        // Synthetic SSN. The serial segment mirrors the pubpid sequence so
        // values are unique within a run and obviously fake (area 700-899,
        // never a real SSA assignment range for these sample rows).
        $seq = $i + 1;
        $ss = sprintf('%03d-%02d-%04d', 700 + ($seq % 200), 10 + ($seq % 89), $seq);

        $patientData = [
            'title' => $isMale ? 'Mr.' : 'Ms.',
            'fname' => $fname,
            'lname' => $lname,
            'mname' => $mname,
            'DOB' => $dob,
            'ss' => $ss,
            'sex' => $sex,
            'race' => $raceCodes[array_rand($raceCodes)],
            'ethnicity' => $ethnicityCodes[array_rand($ethnicityCodes)],
            'status' => $maritalCodes[array_rand($maritalCodes)],
            'street' => random_int(100, 9999) . ' ' . $streets[array_rand($streets)],
            'city' => $city,
            'state' => $state,
            'postal_code' => $zip,
            'phone_home' => sprintf('(%03d) %03d-%04d', random_int(200, 999), random_int(200, 999), random_int(0, 9999)),
            'email' => strtolower($fname . '.' . $lname . random_int(1, 999) . '@example.com'),
            'pubpid' => 'SMPL' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
        ];

        $inserted = $patientService->databaseInsert($patientData);
        $pid = (int) $inserted['pid'];

        // Intake / social history for every patient.
        $historyUuid = UuidRegistry::getRegistryForTable('history_data')->createUuid();
        sqlInsert(
            "INSERT INTO `history_data` SET `pid` = ?, `date` = NOW(), `uuid` = ?, `tobacco` = ?, `alcohol` = ?, "
            . "`exercise_patterns` = ?, `recreational_drugs` = ?",
            [
                $pid, $historyUuid,
                $tobaccoOptions[array_rand($tobaccoOptions)],
                $alcoholOptions[array_rand($alcoholOptions)],
                $exerciseOptions[array_rand($exerciseOptions)],
                $drugUseOptions[array_rand($drugUseOptions)],
            ]
        );

        // Encounters + problems by category.
        $latestEncounter = 0;
        $latestEncounterDate = date('Y-m-d H:i:s');

        if ($isEstablished) {
            $numEnc = random_int(2, 4);
            for ($e = 0; $e < $numEnc; $e++) {
                $encDate = randomDateTime(30 + ($e * 200), 200 + ($e * 300));
                [$condTitle] = $conditions[array_rand($conditions)];
                $enc = createEncounter($pid, $encDate, "Follow-up: {$condTitle}", $providerId, $facilityId, $facilityName);
                if ($e === 0) {
                    $latestEncounter = $enc;
                    $latestEncounterDate = $encDate;
                }
            }
            $numProblems = random_int(1, 3);
            $used = [];
            for ($p = 0; $p < $numProblems; $p++) {
                $idx = array_rand($conditions);
                if (isset($used[$idx])) {
                    continue;
                }
                $used[$idx] = true;
                [$title, $dx] = $conditions[$idx];
                $begdate = date('Y-m-d', strtotime('-' . random_int(200, 2500) . ' days'));
                createProblem($pid, $title, $dx, $begdate);
            }
        } elseif ($category === 'new_condition') {
            $today = date('Y-m-d H:i:s');
            [$title, $dx] = $conditions[array_rand($conditions)];
            $latestEncounter = createEncounter($pid, $today, "New patient visit: {$title}", $providerId, $facilityId, $facilityName);
            $latestEncounterDate = $today;
            createProblem($pid, $title, $dx, date('Y-m-d'));
        } else { // new_wellness
            $today = date('Y-m-d H:i:s');
            $latestEncounter = createEncounter($pid, $today, "New patient wellness visit / annual exam", $providerId, $facilityId, $facilityName);
            $latestEncounterDate = $today;
        }

        // Prescriptions (all patients; 90% overall).
        if ($hasMeds) {
            $numRx = random_int(1, 2);
            for ($r = 0; $r < $numRx; $r++) {
                $drug = $drugs[array_rand($drugs)];
                // ~30% chance a given prescription is recently discontinued.
                $discontinued = (random_int(1, 100) <= 30);
                createPrescription($pid, $drug, $providerId, $discontinued);
            }
        }

        // Bloodwork (all patients); 13% have an abnormality.
        if ($getsBloodwork) {
            $abnormalAnalyte = null;
            if ($isAbnormal) {
                $abnormalChoices = ['a1c', 'testosterone', 'wbc'];
                $abnormalAnalyte = $abnormalChoices[array_rand($abnormalChoices)];
            }
            createBloodwork($pid, $latestEncounter, $providerId, $latestEncounterDate, $abnormalAnalyte);
        }

        $created++;
        $writtenInBatch++;

        if ($writtenInBatch >= $effectiveBatch) {
            sqlCommitTrans();
            $committed = $created;
            $txOpen = false;
        }

        if (($created % 25) === 0) {
            echo "  ...created {$created} patients\n";
        }
    }

    // Commit any partial final batch.
    if ($txOpen) {
        sqlCommitTrans();
        $committed = $created;
        $txOpen = false;
    }
} catch (\Throwable $e) {
    if ($txOpen) {
        sqlRollbackTrans();
    }
    fwrite(
        STDERR,
        "Generation failed. Rolled back the in-flight batch; {$committed} patient(s) committed before the failure: "
        . $e->getMessage() . "\n"
    );
    exit(1);
}

echo "\nDone" . ($dryRun ? " (dry run — nothing written)" : "") . ".\n";
echo ($dryRun ? "Planned" : "Created") . ": {$created} patients\n";
echo "  Established (with history): {$summary['established']}\n";
echo "  New - with visit condition: {$summary['new_condition']}\n";
echo "  New - wellness (no problems): {$summary['new_wellness']}\n";
echo "  Patients with prescriptions: {$summary['with_meds']}\n";
echo "  Patients with abnormal bloodwork: {$summary['abnormal_labs']} (all patients get a panel; the rest are normal)\n";
if (!$dryRun) {
    echo "\nRemove sample data later with: DELETE FROM patient_data WHERE pubpid LIKE 'SMPL%';\n";
}
