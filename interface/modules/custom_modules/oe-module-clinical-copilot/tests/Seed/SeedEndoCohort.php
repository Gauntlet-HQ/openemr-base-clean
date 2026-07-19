<?php

/**
 * Clinical Co-Pilot synthetic endocrinology *cohort* seeder.
 *
 * Idempotent, CLI-only seed script that inserts N synthetic endocrinology
 * patients (pubpid ENDO-0NN; N = CLINICAL_COPILOT_COHORT_COUNT, default 20)
 * into CORE OpenEMR tables (patient_data, procedure_order/report/result,
 * prescriptions, lists, form_vitals, openemr_postcalendar_events), each with a
 * RANDOMIZED 0-20 year longitudinal history so the pre-visit synthesis and chat
 * surfaces have realistic volume/variety to render and page through -- not just
 * the four hand-authored edge-case "landmine" patients in SeedClinicalCopilot.php,
 * which this script complements rather than replaces (run both; this one
 * does not touch CCP-001..CCP-004).
 *
 * Each patient's upcoming appointment is spread across a demo window
 * (CLINICAL_COPILOT_COHORT_APPT_START, default 2026-07-13, for
 * CLINICAL_COPILOT_COHORT_APPT_DAYS days, default 7) so the pre-visit list --
 * which shows CURDATE() appointments -- has patients on each day of the window.
 *
 * Distribution: years-of-history is `mt_rand(0, 20)` per patient (seeded
 * deterministically -- see below), so re-running produces the exact same
 * cohort every time. Across 50 patients this reliably yields a spread from
 * brand-new (0 years, a single intake visit) through long-standing
 * (18-20 years, ~40 semi-annual visits), rather than one uniform depth.
 *
 * Each patient gets a randomly assigned condition profile
 * (type 2 diabetes / hypothyroidism / both / new-patient screening) and a
 * randomly assigned trajectory (improving / worsening / stable / volatile)
 * that biases how its A1c/TSH random walk drifts over the visit series, so
 * the 50-patient set is clinically varied, not just varied in length.
 *
 * Idempotency: patients are keyed by a stable `pubpid` (ENDO-001..ENDO-050).
 * Re-running this script reuses the existing pid for a known pubpid,
 * deletes and re-inserts that patient's dependent rows, and leaves every
 * other patient in the database untouched.
 *
 * Safety guard: refuses to run unless (a) invoked from the CLI, (b) the
 * `--force` flag is passed, and (c) EITHER a dev-stack marker directory
 * (`docker/development-easy/`) is present at the project root OR the operator
 * has set `CLINICAL_COPILOT_SEED_ALLOW=1`. The env-var branch is the
 * deliberate "this is a synthetic-only box, seed it" assertion a cloud target
 * (Railway, T24/OPEN-3) sets, so the SAME script seeds both the local dev
 * stack and a Railway deploy without ever running by accident against a
 * real-PHI deployment. Never point it at real PHI.
 *
 * Usage (inside the openemr container):
 *   php tests/Seed/SeedEndoCohort.php --force
 *
 * On Railway (or any synthetic-only cloud target), run it as the web user
 * with the opt-in set -- see ops/railway/README.md and ops/railway/seed.sh:
 *   CLINICAL_COPILOT_SEED_ALLOW=1 php tests/Seed/SeedEndoCohort.php --force
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Seed;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "SeedEndoCohort must be run from the command line.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__, 6);
$interfaceDir = dirname(__DIR__, 5);

// Authorize a run one of two ways so the SAME script seeds the local dev
// stack AND a synthetic-only cloud target (Railway) without ever running by
// accident against a real-PHI deployment: (a) the dev-stack marker
// `docker/development-easy/` is present, or (b) the operator has explicitly
// set CLINICAL_COPILOT_SEED_ALLOW=1 (see ops/railway/README.md). `--force` is
// still required on top of either.
$devMarker = $projectRoot . '/docker/development-easy';
$seedAllowEnv = strtolower(trim((string) (getenv('CLINICAL_COPILOT_SEED_ALLOW') ?: '')));
$seedAllowed = in_array($seedAllowEnv, ['1', 'true', 'yes', 'on'], true);
$forced = in_array('--force', $argv, true);

if (!is_dir($devMarker) && !$seedAllowed) {
    fwrite(STDERR, "Refusing to run: no dev-stack marker ('$devMarker') and CLINICAL_COPILOT_SEED_ALLOW is not set.\n");
    fwrite(STDERR, "This seeder writes SYNTHETIC patients into core tables. Run it only against the dev\n");
    fwrite(STDERR, "checkout or a synthetic-only cloud target. For the latter (e.g. Railway) set\n");
    fwrite(STDERR, "CLINICAL_COPILOT_SEED_ALLOW=1 -- see ops/railway/README.md. Never point it at real PHI.\n");
    exit(1);
}

if (!$forced) {
    fwrite(STDERR, "Refusing to run without --force. This script writes synthetic patients directly into core tables.\n");
    fwrite(STDERR, "Usage: php tests/Seed/SeedEndoCohort.php --force\n");
    exit(1);
}

fwrite(STDOUT, 'Clinical Co-Pilot endo cohort seed: authorized via ' . (is_dir($devMarker) ? 'dev-stack marker' : 'CLINICAL_COPILOT_SEED_ALLOW') . ".\n");

$_GET['site'] = 'default';
$ignoreAuth = true;
require_once($interfaceDir . '/globals.php');

// globals.php boots the module's runtime autoloader for `src/` only, not the
// `...\Tests\` namespace, so the shared helper trait (a sibling file) must be
// required explicitly for this script to run standalone (dev stack or Railway).
require_once __DIR__ . '/SeedCoreTableHelpers.php';

use OpenEMR\Common\Database\QueryUtils;

final class SeedEndoCohort
{
    use SeedCoreTableHelpers;

    /** Fixed seed: re-running this script always produces the same cohort. */
    private const RNG_SEED = 20260708;

    /** Default cohort size; override with CLINICAL_COPILOT_COHORT_COUNT. */
    private const DEFAULT_PATIENT_COUNT = 20;

    /**
     * Appointment window span (days): each patient's single upcoming appointment
     * is spread round-robin by index across this many days starting from the
     * window start, so the pre-visit list -- which shows CURDATE() appointments
     * -- has patients on every day of the window rather than all on one day. The
     * window START defaults to the server's own today (see appointmentWindowStart),
     * so the cohort appears starting now; both are overridable via
     * CLINICAL_COPILOT_COHORT_APPT_START (Y-m-d) and CLINICAL_COPILOT_COHORT_APPT_DAYS.
     */
    private const DEFAULT_APPT_DAYS = 7;

    private const LOINC_A1C = '4548-4';
    private const LOINC_TSH = '3016-3';
    private const LOINC_FREE_T4 = '3024-7';
    private const LOINC_ACR = '14957-5';
    private const LOINC_CHOL_TOTAL = '2093-3';
    private const LOINC_LDL = '18262-6';
    private const LOINC_HDL = '2085-9';
    private const LOINC_TRIGLYCERIDES = '2571-8';
    private const LOINC_CREATININE = '2160-0';
    private const LOINC_GLUCOSE = '2345-7';

    private const FIXTURE_DIR = __DIR__ . '/fixtures/expected';

    /** @var list<string> */
    private const FIRST_NAMES_F = [
        'Maria', 'Linda', 'Susan', 'Karen', 'Nancy', 'Betty', 'Sandra', 'Donna',
        'Carol', 'Ruth', 'Sharon', 'Deborah', 'Patricia', 'Barbara', 'Jean',
        'Alice', 'Gloria', 'Teresa', 'Janet', 'Rosa', 'Diane', 'Julie', 'Wanda',
        'Yolanda', 'Priya',
    ];

    /** @var list<string> */
    private const FIRST_NAMES_M = [
        'James', 'Robert', 'John', 'Michael', 'David', 'William', 'Richard',
        'Charles', 'Joseph', 'Thomas', 'Carlos', 'Kevin', 'Brian', 'George',
        'Edward', 'Ronald', 'Anthony', 'Frank', 'Raymond', 'Gregory', 'Samuel',
        'Vincent', 'Marcus', 'Hassan', 'Wei',
    ];

    /** @var list<string> */
    private const LAST_NAMES = [
        'Alvarez', 'Nguyen', 'Kowalski', 'Johnson', 'Patel', 'Okafor', 'Rossi',
        'Kim', 'Mueller', 'Fitzgerald', 'Suarez', 'Ivanov', 'Costa', 'Haddad',
        'Larsen', 'Osei', 'Fontaine', 'Novak', 'Reyes', 'Weaver', 'Chowdhury',
        'Delgado', 'Bianchi', 'Petrov', 'Bergstrom', 'Adeyemi', 'Moreau',
        'Sato', 'Whitfield', 'Rahman', 'Castillo', 'Lindqvist', 'Odom',
        'Marchetti', 'Volkov', 'Abara', 'Tanaka', 'Fernandez', 'Grant',
        'Sokolov', 'Diallo', 'Beaumont', 'Krause', 'Silva', 'Nakamura',
        'Whitaker', 'Popescu', 'Aziz', 'Callahan', 'Torres',
    ];

    private const CONDITION_PROFILES = [
        'type2_diabetes',
        'hypothyroidism',
        'diabetes_and_hypothyroidism',
        'new_patient_screening',
    ];
    /** Weighted so comorbid/diabetes-only patients dominate, as in a real endo panel. */
    private const CONDITION_WEIGHTS = [40, 20, 25, 15];

    private const TRAJECTORIES = ['improving', 'worsening', 'stable', 'volatile'];

    private readonly \DateTimeImmutable $today;

    /** @var list<array<string, mixed>> */
    private array $cohortSummary = [];

    public function __construct()
    {
        $this->providerId = $this->resolveProviderId();
        $this->today = $this->resolveToday();
    }

    public function run(): void
    {
        mt_srand(self::RNG_SEED);

        $count = $this->cohortCount();
        $start = $this->appointmentWindowStart();
        $days = $this->appointmentWindowDays();
        fwrite(STDOUT, sprintf(
            "Clinical Co-Pilot endo cohort seed: %d patients, appointments spread across %s .. %s.\n",
            $count,
            $start->format('Y-m-d'),
            $start->modify('+' . ($days - 1) . ' days')->format('Y-m-d'),
        ));

        // Each patient is dozens of inserts; on a memory-constrained MySQL
        // seeding 20 back-to-back can spike it into the instability we saw
        // ("MySQL server has gone away"). Pause between patients to let it
        // breathe. Tunable via CLINICAL_COPILOT_SEED_THROTTLE_SECONDS (default 1;
        // 0 disables on a roomy box).
        $rawThrottle = getenv('CLINICAL_COPILOT_SEED_THROTTLE_SECONDS');
        $throttle = is_string($rawThrottle) && is_numeric($rawThrottle) ? max(0, (int) $rawThrottle) : 1;
        for ($i = 1; $i <= $count; $i++) {
            $this->seedPatient($i);
            if ($i < $count && $throttle > 0) {
                sleep($throttle);
            }
        }

        $this->writeSummary();

        fwrite(STDOUT, "Clinical Co-Pilot endo cohort seed complete: " . count($this->cohortSummary) . " patients.\n");
    }

    /** Cohort size, overridable via CLINICAL_COPILOT_COHORT_COUNT (clamped 1..500). */
    private function cohortCount(): int
    {
        $raw = getenv('CLINICAL_COPILOT_COHORT_COUNT');
        $n = is_string($raw) && is_numeric($raw) ? (int) $raw : self::DEFAULT_PATIENT_COUNT;

        return max(1, min(500, $n));
    }

    private function appointmentWindowStart(): \DateTimeImmutable
    {
        $raw = getenv('CLINICAL_COPILOT_COHORT_APPT_START');
        if (is_string($raw) && $raw !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        // Default: the DATABASE's own today ({@see resolveToday}). The pre-visit
        // list only shows pc_eventDate = CURDATE(), so anchoring the window to
        // the server's today guarantees the cohort appears starting now, instead
        // of on an absolute date that may not match the server clock (the cause
        // of "seeded but nothing shows in the UI"). Override with
        // CLINICAL_COPILOT_COHORT_APPT_START for a specific demo date.
        return $this->today;
    }

    /** Number of days the appointment window spans, overridable (clamped 1..60). */
    private function appointmentWindowDays(): int
    {
        $raw = getenv('CLINICAL_COPILOT_COHORT_APPT_DAYS');
        $n = is_string($raw) && is_numeric($raw) ? (int) $raw : self::DEFAULT_APPT_DAYS;

        return max(1, min(60, $n));
    }

    /**
     * The upcoming-appointment date for patient #$index: round-robin across the
     * window so each day gets a roughly even share (20 patients over 7 days =>
     * 3,3,3,3,3,3,2), interspersed rather than clumped on one day.
     */
    private function appointmentDate(int $index): \DateTimeImmutable
    {
        $offset = ($index - 1) % $this->appointmentWindowDays();

        return $this->appointmentWindowStart()->modify("+{$offset} days");
    }

    private function seedPatient(int $index): int
    {
        $pubpid = sprintf('ENDO-%03d', $index);

        $sex = mt_rand(0, 1) === 0 ? 'Female' : 'Male';
        $fname = $sex === 'Female'
            ? self::FIRST_NAMES_F[array_rand(self::FIRST_NAMES_F)]
            : self::FIRST_NAMES_M[array_rand(self::FIRST_NAMES_M)];
        $lname = self::LAST_NAMES[array_rand(self::LAST_NAMES)];

        $ageYears = mt_rand(28, 82);
        $dob = $this->today->modify("-{$ageYears} years")->modify('-' . mt_rand(0, 364) . ' days')->format('Y-m-d');

        $yearsOfHistory = mt_rand(0, 20);
        $profile = $this->weightedChoice(self::CONDITION_PROFILES, self::CONDITION_WEIGHTS);
        // A brand-new patient (0 years) is, by definition, a screening visit
        // regardless of which chronic-disease profile they'll carry forward.
        if ($yearsOfHistory === 0) {
            $profile = 'new_patient_screening';
        }
        $trajectory = self::TRAJECTORIES[array_rand(self::TRAJECTORIES)];

        $pid = $this->upsertPatientDemographics($pubpid, $fname, $lname, $dob, $sex);
        $this->clearDependentData($pid);

        $visitsAgoMonths = $this->buildVisitSchedule($yearsOfHistory);

        $isDiabetic = in_array($profile, ['type2_diabetes', 'diabetes_and_hypothyroidism', 'new_patient_screening'], true);
        $isThyroid = in_array($profile, ['hypothyroidism', 'diabetes_and_hypothyroidism'], true);

        $a1cSeries = $isDiabetic ? $this->generateA1cSeries(count($visitsAgoMonths), $trajectory) : [];
        $tshSeries = $isThyroid ? $this->generateTshSeries(count($visitsAgoMonths), $trajectory) : [];

        $weightBaseline = $sex === 'Female' ? $this->randFloat(58.0, 95.0) : $this->randFloat(68.0, 110.0);

        $labCount = 0;
        $visitCount = 0;

        // Oldest visit first so med start dates / dose titrations land chronologically.
        $orderedVisits = array_reverse($visitsAgoMonths);
        $orderedA1c = array_reverse($a1cSeries);
        $orderedTsh = array_reverse($tshSeries);

        $metforminStartId = null;
        $metforminDoseUpId = null;
        $levothyroxineStartId = null;
        $levothyroxineDoseUpId = null;
        $weightRunning = $weightBaseline;

        foreach ($orderedVisits as $visitIndex => $monthsAgo) {
            $visitDate = $this->today->modify("-{$monthsAgo} months");
            $visitCount++;
            $isAnnualPanelVisit = ($visitIndex % 2) === 0;

            if ($isDiabetic && isset($orderedA1c[$visitIndex])) {
                $a1c = $orderedA1c[$visitIndex];
                $orderId = $this->insertProcedureOrder($pid, $visitDate, $visitDate, 'complete', self::LOINC_A1C, 'Hemoglobin A1c');
                $reportId = $this->insertProcedureReport($orderId, $visitDate, $visitDate);
                $this->insertProcedureResult($reportId, self::LOINC_A1C, 'Hemoglobin A1c', 'N', number_format($a1c, 1), '%', 'final', $visitDate);
                $labCount++;

                // Fasting glucose roughly tracks the A1c band (28.7*A1c - 46.7
                // is the ADAG mean-glucose approximation) with visit noise.
                $glucose = max(65, min(400, (int)round(28.7 * $a1c - 46.7 + mt_rand(-15, 15))));
                $glucOrderId = $this->insertProcedureOrder($pid, $visitDate, $visitDate, 'complete', self::LOINC_GLUCOSE, 'Glucose');
                $glucReportId = $this->insertProcedureReport($glucOrderId, $visitDate, $visitDate);
                $this->insertProcedureResult($glucReportId, self::LOINC_GLUCOSE, 'Glucose', 'N', (string)$glucose, 'mg/dL', 'final', $visitDate);
                $labCount++;
            }

            if ($isThyroid && isset($orderedTsh[$visitIndex])) {
                $tsh = $orderedTsh[$visitIndex];
                $orderId = $this->insertProcedureOrder($pid, $visitDate, $visitDate, 'complete', self::LOINC_TSH, 'Thyroid Stimulating Hormone');
                $reportId = $this->insertProcedureReport($orderId, $visitDate, $visitDate);
                $this->insertProcedureResult($reportId, self::LOINC_TSH, 'Thyroid Stimulating Hormone', 'N', number_format($tsh, 2), 'mIU/L', 'final', $visitDate);
                $labCount++;

                if ($tsh > 5.5 || $tsh < 0.5) {
                    $freeT4 = $this->randFloat(0.6, 1.8);
                    $ft4OrderId = $this->insertProcedureOrder($pid, $visitDate, $visitDate, 'complete', self::LOINC_FREE_T4, 'Free T4');
                    $ft4ReportId = $this->insertProcedureReport($ft4OrderId, $visitDate, $visitDate);
                    $this->insertProcedureResult($ft4ReportId, self::LOINC_FREE_T4, 'Free T4', 'N', number_format($freeT4, 2), 'ng/dL', 'final', $visitDate);
                    $labCount++;
                }
            }

            if ($isAnnualPanelVisit) {
                $this->insertAnnualPanel($pid, $visitDate, $isDiabetic);
                $labCount += $isDiabetic ? 6 : 4;
            }

            $weightRunning = max(40.0, min(180.0, $weightRunning + $this->randFloat(-1.5, 1.5)));
            $systolic = (string)mt_rand($isDiabetic ? 118 : 108, $isDiabetic ? 148 : 132);
            $diastolic = (string)mt_rand(68, 92);
            $this->insertVital($pid, $visitDate, round($weightRunning, 1), $systolic, $diastolic);

            if ($isDiabetic && $metforminStartId === null && $yearsOfHistory > 0) {
                $metforminStartId = $this->insertPrescription($pid, 'Metformin HCl 500 MG Oral Tablet', '500 mg twice daily', $visitDate, null, true);
            }

            if ($isThyroid && $levothyroxineStartId === null && $yearsOfHistory > 0) {
                $doseMcg = self::choice([25, 50, 75, 88, 100, 112, 125, 137, 150]);
                $levothyroxineStartId = $this->insertPrescription($pid, "Levothyroxine {$doseMcg} MCG Oral Tablet", "{$doseMcg} mcg once daily", $visitDate, null, true);
            }

            $isHalfwayVisit = $visitIndex === intdiv(count($orderedVisits), 2);
            if ($isHalfwayVisit && $isDiabetic && $metforminStartId !== null && $yearsOfHistory >= 4) {
                QueryUtils::sqlStatementThrowException(
                    "UPDATE `prescriptions` SET `active` = 0, `end_date` = ? WHERE `id` = ?",
                    [$visitDate->format('Y-m-d'), $metforminStartId]
                );
                $metforminDoseUpId = $this->insertPrescription($pid, 'Metformin HCl 1000 MG Oral Tablet', '1000 mg twice daily', $visitDate, null, true);
            }
            if ($isHalfwayVisit && $isThyroid && $levothyroxineStartId !== null && $yearsOfHistory >= 4) {
                QueryUtils::sqlStatementThrowException(
                    "UPDATE `prescriptions` SET `active` = 0, `end_date` = ? WHERE `id` = ?",
                    [$visitDate->format('Y-m-d'), $levothyroxineStartId]
                );
                $adjustedDoseMcg = self::choice([50, 75, 88, 100, 112, 125, 150, 175]);
                $levothyroxineDoseUpId = $this->insertPrescription($pid, "Levothyroxine {$adjustedDoseMcg} MCG Oral Tablet", "{$adjustedDoseMcg} mcg once daily", $visitDate, null, true);
            }
        }

        $eventTitle = $yearsOfHistory === 0 ? 'New Patient Intake - Endocrinology' : 'Endocrinology Follow-up';
        // Upcoming appointment in the demo window (July 13-19 by default), spread
        // by index across the days, at a varied clinic-hours time so a day's list
        // reads like a real schedule rather than every patient at 08:50.
        $apptDate = $this->appointmentDate($index);
        $apptTime = sprintf('%02d:%02d:00', mt_rand(8, 15), mt_rand(0, 3) * 15);
        $this->insertScheduleEvent($pid, $apptDate, $eventTitle, $apptTime);

        $this->cohortSummary[] = [
            'pubpid' => $pubpid,
            'pid' => $pid,
            'name' => "{$fname} {$lname}",
            'sex' => $sex,
            'dob' => $dob,
            'condition_profile' => $profile,
            'trajectory' => $trajectory,
            'years_of_history' => $yearsOfHistory,
            'visit_count' => $visitCount,
            'lab_result_count' => $labCount,
            'a1c_series_pct' => array_map(static fn (float $v): float => round($v, 1), $a1cSeries),
            'tsh_series_mIU_L' => array_map(static fn (float $v): float => round($v, 2), $tshSeries),
            'medications' => array_values(array_filter([
                $metforminStartId !== null ? ($metforminDoseUpId !== null ? 'Metformin 500mg -> 1000mg (dose increase mid-history)' : 'Metformin 500mg') : null,
                $levothyroxineStartId !== null ? ($levothyroxineDoseUpId !== null ? 'Levothyroxine (dose adjusted mid-history)' : 'Levothyroxine (stable dose)') : null,
            ])),
        ];

        return $pid;
    }

    /**
     * Semi-annual visit cadence (0, 6, 12, ... months ago) out to
     * yearsOfHistory*12 months ago. A brand-new patient (0 years) gets a
     * single intake visit today; this deliberately does NOT round up to a
     * 6-month cadence for that case.
     *
     * @return list<int> months-ago, descending from most recent (0) to oldest
     */
    private function buildVisitSchedule(int $yearsOfHistory): array
    {
        if ($yearsOfHistory === 0) {
            return [0];
        }

        $months = [];
        for ($m = 0; $m <= $yearsOfHistory * 12; $m += 6) {
            $months[] = $m;
        }

        return $months;
    }

    /**
     * @return list<float> most-recent-first, matching buildVisitSchedule's ordering
     */
    private function generateA1cSeries(int $visitCount, string $trajectory): array
    {
        $bias = match ($trajectory) {
            'improving' => -0.12,
            'worsening' => 0.12,
            'volatile' => 0.0,
            default => 0.0, // stable
        };
        $spread = $trajectory === 'volatile' ? 0.6 : 0.25;

        // Build oldest-to-newest, then reverse to match the most-recent-first
        // convention the caller expects.
        $value = $this->randFloat(5.8, 9.0);
        $series = [$value];
        for ($i = 1; $i < $visitCount; $i++) {
            $value = max(5.0, min(13.0, $value + $bias + $this->randFloat(-$spread, $spread)));
            $series[] = $value;
        }

        return array_reverse($series);
    }

    /**
     * @return list<float> most-recent-first, matching buildVisitSchedule's ordering
     */
    private function generateTshSeries(int $visitCount, string $trajectory): array
    {
        $bias = match ($trajectory) {
            'improving' => -0.15,
            'worsening' => 0.2,
            'volatile' => 0.0,
            default => 0.0,
        };
        $spread = $trajectory === 'volatile' ? 1.2 : 0.4;

        $value = $this->randFloat(0.8, 6.5);
        $series = [$value];
        for ($i = 1; $i < $visitCount; $i++) {
            $value = max(0.1, min(15.0, $value + $bias + $this->randFloat(-$spread, $spread)));
            $series[] = $value;
        }

        return array_reverse($series);
    }

    private function insertAnnualPanel(int $pid, \DateTimeImmutable $date, bool $isDiabetic): void
    {
        $totalChol = mt_rand(150, 260);
        $ldl = mt_rand(70, 170);
        $hdl = mt_rand(35, 75);
        $triglycerides = mt_rand(80, 220);

        $cholOrderId = $this->insertProcedureOrder($pid, $date, $date, 'complete', self::LOINC_CHOL_TOTAL, 'Total Cholesterol');
        $cholReportId = $this->insertProcedureReport($cholOrderId, $date, $date);
        $this->insertProcedureResult($cholReportId, self::LOINC_CHOL_TOTAL, 'Total Cholesterol', 'N', (string)$totalChol, 'mg/dL', 'final', $date);

        $ldlOrderId = $this->insertProcedureOrder($pid, $date, $date, 'complete', self::LOINC_LDL, 'LDL Cholesterol');
        $ldlReportId = $this->insertProcedureReport($ldlOrderId, $date, $date);
        $this->insertProcedureResult($ldlReportId, self::LOINC_LDL, 'LDL Cholesterol', 'N', (string)$ldl, 'mg/dL', 'final', $date);

        $hdlOrderId = $this->insertProcedureOrder($pid, $date, $date, 'complete', self::LOINC_HDL, 'HDL Cholesterol');
        $hdlReportId = $this->insertProcedureReport($hdlOrderId, $date, $date);
        $this->insertProcedureResult($hdlReportId, self::LOINC_HDL, 'HDL Cholesterol', 'N', (string)$hdl, 'mg/dL', 'final', $date);

        $trigOrderId = $this->insertProcedureOrder($pid, $date, $date, 'complete', self::LOINC_TRIGLYCERIDES, 'Triglycerides');
        $trigReportId = $this->insertProcedureReport($trigOrderId, $date, $date);
        $this->insertProcedureResult($trigReportId, self::LOINC_TRIGLYCERIDES, 'Triglycerides', 'N', (string)$triglycerides, 'mg/dL', 'final', $date);

        if ($isDiabetic) {
            $acr = mt_rand(5, 90);
            $acrOrderId = $this->insertProcedureOrder($pid, $date, $date, 'complete', self::LOINC_ACR, 'Urine Albumin/Creatinine Ratio');
            $acrReportId = $this->insertProcedureReport($acrOrderId, $date, $date);
            $this->insertProcedureResult($acrReportId, self::LOINC_ACR, 'Urine Albumin/Creatinine Ratio', 'N', (string)$acr, 'mg/g', 'final', $date);

            $creatinine = $this->randFloat(0.6, 1.4);
            $creatOrderId = $this->insertProcedureOrder($pid, $date, $date, 'complete', self::LOINC_CREATININE, 'Creatinine');
            $creatReportId = $this->insertProcedureReport($creatOrderId, $date, $date);
            $this->insertProcedureResult($creatReportId, self::LOINC_CREATININE, 'Creatinine', 'N', number_format($creatinine, 2), 'mg/dL', 'final', $date);
        }
    }

    private function writeSummary(): void
    {
        // Dev-only summary artifact; a deployment never reads it. On Railway the
        // module dir is root-owned and this runs as apache, so skip quietly when
        // it is not writable rather than warning per file (patients are seeded).
        if (!is_dir(self::FIXTURE_DIR)) {
            @mkdir(self::FIXTURE_DIR, 0755, true);
        }
        if (!is_dir(self::FIXTURE_DIR) || !is_writable(self::FIXTURE_DIR)) {
            fwrite(STDOUT, "Skipped cohort summary file (dev-only artifact): " . self::FIXTURE_DIR . " is not writable. Patients were still seeded.\n");
            return;
        }

        $path = self::FIXTURE_DIR . '/endo_cohort_summary.json';
        file_put_contents($path, json_encode([
            'note' => 'Index of the synthetic endocrinology cohort (ENDO-0NN) seeded by SeedEndoCohort.php. Complements, does not replace, the 4 landmine patients (CCP-001..CCP-004) in SeedClinicalCopilot.php. years_of_history is mt_rand(0,20), fixed RNG seed ' . self::RNG_SEED . ' -- re-running reproduces the same cohort.',
            'patient_count' => count($this->cohortSummary),
            'years_of_history_distribution' => $this->summarizeYearsDistribution(),
            'patients' => $this->cohortSummary,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
    }

    /**
     * @return array<string, int>
     */
    private function summarizeYearsDistribution(): array
    {
        $buckets = ['0' => 0, '1-4' => 0, '5-9' => 0, '10-14' => 0, '15-20' => 0];
        foreach ($this->cohortSummary as $patient) {
            $years = $patient['years_of_history'];
            if ($years === 0) {
                $buckets['0']++;
            } elseif ($years <= 4) {
                $buckets['1-4']++;
            } elseif ($years <= 9) {
                $buckets['5-9']++;
            } elseif ($years <= 14) {
                $buckets['10-14']++;
            } else {
                $buckets['15-20']++;
            }
        }

        return $buckets;
    }

    private function randFloat(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }

    /**
     * @param list<string> $items
     * @param list<int> $weights
     */
    private function weightedChoice(array $items, array $weights): string
    {
        $total = array_sum($weights);
        $roll = mt_rand(1, $total);
        $cumulative = 0;
        foreach ($items as $i => $item) {
            $cumulative += $weights[$i];
            if ($roll <= $cumulative) {
                return $item;
            }
        }

        return $items[array_key_last($items)];
    }

    /**
     * @param list<int> $items
     */
    private static function choice(array $items): int
    {
        return $items[array_rand($items)];
    }
}

(new SeedEndoCohort())->run();
