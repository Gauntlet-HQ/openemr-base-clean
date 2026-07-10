<?php

/**
 * Clinical Co-Pilot synthetic diabetes patient seeder.
 *
 * Idempotent, CLI-only seed script that inserts 4 synthetic type-2-diabetes
 * patients into CORE OpenEMR tables (patient_data, procedure_order/report/
 * result, prescriptions, lists, form_vitals, openemr_postcalendar_events).
 *
 * This is dev/test fixture data for a synthetic-patients-only phase (OPEN-1,
 * build-notes.md T18): writing to core tables here does NOT violate the
 * module's I9 additivity invariant, which governs the module's *runtime*
 * code and the repo diff — not a seed script that lives inside the module
 * directory and is only ever run by a developer against a dev stack.
 *
 * Each patient encodes one or more "landmines" from the U2 build-unit row
 * (ARCHITECTURE_COMPLETE.md) and the lab slice contract (C1-C4):
 *
 *   CCP-001  rising A1c trend; metformin dose increase followed by A1c
 *            that keeps rising anyway (med-dose-vs-response mismatch);
 *            overdue urine ACR (no pending order).
 *   CCP-002  overdue urine ACR WITH an active pending order (reorder
 *            suppression + drawn-but-unresulted order, same landmine);
 *            a late-arriving lab (older clinical date than other results
 *            already on file for the patient).
 *   CCP-003  a lab corrected in place (UPDATE on the same procedure_result
 *            row) AND a lab corrected via a new row (second, higher-id
 *            procedure_result row superseding the first); a "<7.0"
 *            censored A1c; a unitless glucose value.
 *   CCP-004  an A1c reported in IFCC mmol/mol units (needs conversion to
 *            NGSP %); an unrecognized result_status ('amended'); a
 *            preliminary result; an outside/reconciled med in `lists`
 *            (type=medication) alongside an in-house `prescriptions` med
 *            (T4 union).
 *
 * All four patients get a today-dated `openemr_postcalendar_events` row so
 * the worker/warm-sweep has a schedule to iterate.
 *
 * Idempotency: patients are keyed by a stable `pubpid` (CCP-001..CCP-004).
 * Re-running this script reuses the existing pid for a known pubpid,
 * deletes and re-inserts that patient's dependent rows (labs, meds, vitals,
 * schedule), and leaves every other patient in the database untouched.
 *
 * Safety guard: refuses to run unless (a) invoked from the CLI, (b) the
 * `--force` flag is passed, and (c) a dev-stack marker directory
 * (`docker/development-easy/`) is present at the project root — i.e. this
 * looks like the OpenEMR dev checkout described in CONTRIBUTING.md /
 * CLAUDE.md, not a hardened/production deployment.
 *
 * Usage (inside the openemr container):
 *   php tests/Seed/SeedClinicalCopilot.php --force
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
    fwrite(STDERR, "SeedClinicalCopilot must be run from the command line.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__, 6);
$interfaceDir = dirname(__DIR__, 5);

$devMarker = $projectRoot . '/docker/development-easy';
$forced = in_array('--force', $argv, true);

// The Railway deploy pipeline opts in explicitly via CLINICAL_COPILOT_SEED_DEMO=1
// (railway-install-copilot.sh only reaches this script when that is set). On a
// flex deploy the dev-stack marker directory is not the right signal, so an
// explicit operator opt-in is sufficient authorization there -- still gated by
// --force below, so nothing seeds by accident.
$explicitOptIn = getenv('CLINICAL_COPILOT_SEED_DEMO') === '1';

if (!$explicitOptIn && !is_dir($devMarker)) {
    fwrite(STDERR, "Refusing to run: dev-stack marker '$devMarker' not found and CLINICAL_COPILOT_SEED_DEMO is not '1'. This seeder only runs against the OpenEMR dev checkout or with an explicit demo opt-in.\n");
    exit(1);
}

if (!$forced) {
    fwrite(STDERR, "Refusing to run without --force. This script writes synthetic patients directly into core tables.\n");
    fwrite(STDERR, "Usage: php tests/Seed/SeedClinicalCopilot.php --force\n");
    exit(1);
}

$_GET['site'] = 'default';
$ignoreAuth = true;
require_once($interfaceDir . '/globals.php');

// globals.php boots the module's runtime autoloader for `src/` only, not the
// `...\Tests\` namespace, so the shared helper trait (a sibling file) must be
// required explicitly for this script to run standalone.
require_once __DIR__ . '/SeedCoreTableHelpers.php';

use OpenEMR\Common\Database\QueryUtils;

/**
 * @phpstan-type FactCitation array{table: string, pk: int, field: string|null, date_source: string}
 * @phpstan-type FactFixture array{
 *     fact_id: null,
 *     fact_id_note: string,
 *     capability: string,
 *     capability_version: string,
 *     kind: string,
 *     pid: int,
 *     clinical_date: string|null,
 *     date_source: string,
 *     value: array{raw: string, parsed: float|null, comparator: string, unit_original: string, unit_canonical: string|null, conversion_version: string|null}|null,
 *     status: string,
 *     flags: list<string>,
 *     citations: list<FactCitation>,
 *     note: string
 * }
 */
final class SeedClinicalCopilot
{
    use SeedCoreTableHelpers;

    private const LOINC_A1C = '4548-4';
    private const LOINC_ACR = '14957-5';
    private const LOINC_CHOL_TOTAL = '2093-3';
    private const LOINC_LDL = '18262-6';
    private const LOINC_GLUCOSE = '2345-7';

    private const FIXTURE_DIR = __DIR__ . '/fixtures/expected';

    private readonly \DateTimeImmutable $today;

    /** @var array<string, array<string, mixed>> */
    private array $patientFixtures = [];

    /** @var list<array<string, mixed>> */
    private array $landmineIndex = [];

    public function __construct()
    {
        $this->providerId = $this->resolveProviderId();
        $this->today = $this->resolveToday();
    }

    public function run(): void
    {
        // Each patient is ~50 inserts (labs, meds, vitals, schedule). On a
        // small, memory-constrained MySQL (e.g. a 1 GB Railway plan) bursting
        // all four back-to-back adds to the pressure that already makes the base
        // install unstable, so pause between patients to let the server breathe.
        // Tunable via CLINICAL_COPILOT_SEED_THROTTLE_SECONDS (default 2; set 0 to
        // disable, e.g. on a roomy dev box).
        $throttle = $this->seedThrottleSeconds();
        $this->seedPatientOne();
        $this->pause($throttle);
        $this->seedPatientTwo();
        $this->pause($throttle);
        $this->seedPatientThree();
        $this->pause($throttle);
        $this->seedPatientFour();
        $this->writeFixtures();

        fwrite(STDOUT, "Clinical Co-Pilot synthetic seed complete: " . count($this->patientFixtures) . " patients, fixtures written to " . self::FIXTURE_DIR . "\n");
    }

    private function seedThrottleSeconds(): int
    {
        $raw = getenv('CLINICAL_COPILOT_SEED_THROTTLE_SECONDS');
        if ($raw === false || !is_numeric($raw)) {
            return 2;
        }

        return max(0, (int) $raw);
    }

    private function pause(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    // ------------------------------------------------------------------
    // Patient CCP-001: rising A1c trend + med-dose-vs-response mismatch +
    // overdue ACR (no pending order).
    // ------------------------------------------------------------------
    private function seedPatientOne(): void
    {
        $pubpid = 'CCP-001';
        $pid = $this->upsertPatientDemographics($pubpid, 'Alice', 'Rising', '1968-04-12', 'Female');
        $this->clearDependentData($pid);

        $a1cResults = [];
        $a1cDates = [12, 9, 6, 3]; // months ago
        $a1cValues = ['7.2', '7.6', '8.0', '8.4'];
        foreach ($a1cDates as $i => $monthsAgo) {
            $date = $this->today->modify("-{$monthsAgo} months");
            $orderId = $this->insertProcedureOrder($pid, $date, $date, 'complete', self::LOINC_A1C, 'Hemoglobin A1c');
            $reportId = $this->insertProcedureReport($orderId, $date, $date);
            $resultId = $this->insertProcedureResult($reportId, self::LOINC_A1C, 'Hemoglobin A1c', 'N', $a1cValues[$i], '%', 'final', $date);
            $a1cResults[] = ['date' => $date, 'value' => $a1cValues[$i], 'result_id' => $resultId, 'report_id' => $reportId];
        }

        // Metformin: initial dose, then a dose increase 4 months ago. The two
        // A1c draws AFTER the increase (6mo and 3mo ago in DB terms are before
        // the increase; the point is the 3-months-ago draw, which is AFTER
        // the 4-months-ago dose bump, still rose) -- i.e. dose went up and
        // the response kept getting worse, not better.
        $doseIncreaseDate = $this->today->modify('-4 months');
        $initialStart = $this->today->modify('-11 months');
        $rxOldId = $this->insertPrescription($pid, 'Metformin HCl 500 MG Oral Tablet', '500 mg twice daily', $initialStart, $doseIncreaseDate, false);
        $rxNewId = $this->insertPrescription($pid, 'Metformin HCl 1000 MG Oral Tablet', '1000 mg twice daily', $doseIncreaseDate, null, true);

        // ACR overdue: last draw 14 months ago, cadence is P1Y (annual), no
        // newer draw and no pending order.
        $acrDate = $this->today->modify('-14 months');
        $acrOrderId = $this->insertProcedureOrder($pid, $acrDate, $acrDate, 'complete', self::LOINC_ACR, 'Urine Albumin/Creatinine Ratio');
        $acrReportId = $this->insertProcedureReport($acrOrderId, $acrDate, $acrDate);
        $acrResultId = $this->insertProcedureResult($acrReportId, self::LOINC_ACR, 'Urine Albumin/Creatinine Ratio', 'N', '45', 'mg/g', 'final', $acrDate);

        $this->insertVital($pid, $this->today->modify('-3 months'), 88.5, '132', '84');
        $this->insertScheduleEvent($pid, $this->today, 'Endocrinology Follow-up');

        $facts = [];
        foreach ($a1cResults as $r) {
            $facts[] = $this->fact(
                capability: 'control_proxy',
                kind: 'trend_point',
                pid: $pid,
                clinicalDate: $r['date'],
                value: ['raw' => $r['value'], 'parsed' => (float)$r['value'], 'comparator' => 'none', 'unitOriginal' => '%', 'unitCanonical' => '%', 'conversionVersion' => null],
                status: 'final',
                flags: [],
                citations: [$this->citation('procedure_result', $r['result_id'], 'result', 'collected')],
                note: 'One of four rising A1c draws (7.2 -> 8.4 over 12mo -> 3mo ago); trend must read as rising.'
            );
        }
        $facts[] = $this->fact(
            capability: 'med_response',
            kind: 'med_event',
            pid: $pid,
            clinicalDate: $doseIncreaseDate,
            value: null,
            status: 'final',
            flags: [],
            citations: [$this->citation('prescriptions', $rxNewId, 'start_date', 'collected')],
            note: 'Metformin increased 500mg->1000mg 4 months ago; the next A1c draw (3mo ago, 8.4) still rose. Capability must pair the two series and NEVER assert causation.'
        );
        $facts[] = $this->fact(
            capability: 'overdue_tests',
            kind: 'overdue_item',
            pid: $pid,
            clinicalDate: $acrDate,
            value: null,
            status: 'final',
            flags: [],
            citations: [$this->citation('procedure_result', $acrResultId, null, 'collected')],
            note: 'ACR last drawn 14 months ago; cadence is P1Y; no newer draw and no pending order exists -> plain overdue, reorder note should NOT be suppressed.'
        );

        $this->patientFixtures[$pubpid] = [
            'pubpid' => $pubpid,
            'pid' => $pid,
            'label' => 'Rising A1c + metformin dose-response mismatch + overdue ACR (no pending order)',
            'facts' => $facts,
        ];
        $this->landmineIndex[] = [
            'landmine' => 'rising_a1c_trend',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'procedure_result',
            'pks' => array_column($a1cResults, 'result_id'),
            'expected_handling' => 'presented as ascending trend_point series, no exclusions',
        ];
        $this->landmineIndex[] = [
            'landmine' => 'med_dose_vs_response_mismatch',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'prescriptions',
            'pks' => [$rxOldId, $rxNewId],
            'expected_handling' => 'paired med_event + trend cited together; capability must not assert causation (I8)',
        ];
        $this->landmineIndex[] = [
            'landmine' => 'overdue_acr_no_pending_order',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'procedure_result',
            'pks' => [$acrResultId],
            'expected_handling' => 'overdue_item, reorder-suppression note absent (no active pending order)',
        ];
    }

    // ------------------------------------------------------------------
    // Patient CCP-002: overdue ACR WITH a pending draw (reorder
    // suppression + drawn-but-unresulted order) + a late-arriving lab.
    // ------------------------------------------------------------------
    private function seedPatientTwo(): void
    {
        $pubpid = 'CCP-002';
        $pid = $this->upsertPatientDemographics($pubpid, 'Ben', 'Overdue', '1972-09-30', 'Male');
        $this->clearDependentData($pid);

        // ACR last final result 13 months ago (overdue, P1Y cadence).
        $acrOldDate = $this->today->modify('-13 months');
        $acrOldOrderId = $this->insertProcedureOrder($pid, $acrOldDate, $acrOldDate, 'complete', self::LOINC_ACR, 'Urine Albumin/Creatinine Ratio');
        $acrOldReportId = $this->insertProcedureReport($acrOldOrderId, $acrOldDate, $acrOldDate);
        $acrOldResultId = $this->insertProcedureResult($acrOldReportId, self::LOINC_ACR, 'Urine Albumin/Creatinine Ratio', 'N', '38', 'mg/g', 'final', $acrOldDate);

        // Active pending order, drawn 2 days ago, no report/result yet.
        $pendingDrawDate = $this->today->modify('-2 days');
        $acrPendingOrderId = $this->insertProcedureOrder($pid, $pendingDrawDate, $pendingDrawDate, 'pending', self::LOINC_ACR, 'Urine Albumin/Creatinine Ratio');

        // Late-arriving lab: total cholesterol collected 45 days ago, only
        // now on file -- an older clinical date than the patient's other
        // results already on record (A1c below), i.e. it arrives "late"
        // relative to the timeline. Re-inserting this row after an initial
        // digest computation is the E1 late-arrival digest eval in U4/U9;
        // here we only seed the row itself.
        $lateDate = $this->today->modify('-45 days');
        $cholOrderId = $this->insertProcedureOrder($pid, $lateDate, $lateDate, 'complete', self::LOINC_CHOL_TOTAL, 'Total Cholesterol');
        $cholReportId = $this->insertProcedureReport($cholOrderId, $lateDate, $this->today);
        $cholResultId = $this->insertProcedureResult($cholReportId, self::LOINC_CHOL_TOTAL, 'Total Cholesterol', 'N', '210', 'mg/dL', 'final', $lateDate);

        // Stable A1c baseline, both more recent than the late cholesterol.
        $a1cRecentDate = $this->today->modify('-10 days');
        $a1cOrderId = $this->insertProcedureOrder($pid, $a1cRecentDate, $a1cRecentDate, 'complete', self::LOINC_A1C, 'Hemoglobin A1c');
        $a1cReportId = $this->insertProcedureReport($a1cOrderId, $a1cRecentDate, $a1cRecentDate);
        $a1cResultId = $this->insertProcedureResult($a1cReportId, self::LOINC_A1C, 'Hemoglobin A1c', 'N', '6.9', '%', 'final', $a1cRecentDate);

        $this->insertScheduleEvent($pid, $this->today, 'Endocrinology Follow-up');

        $facts = [
            $this->fact(
                capability: 'overdue_tests',
                kind: 'overdue_item',
                pid: $pid,
                clinicalDate: $acrOldDate,
                value: null,
                status: 'final',
                flags: [],
                citations: [$this->citation('procedure_result', $acrOldResultId, null, 'collected')],
                note: 'ACR last drawn 13 months ago (overdue, P1Y) but see the pending_order fact below: reorder note MUST be suppressed.'
            ),
            $this->fact(
                capability: 'pending_results',
                kind: 'pending_order',
                pid: $pid,
                clinicalDate: $pendingDrawDate,
                value: null,
                status: 'final',
                flags: [],
                citations: [$this->citation('procedure_order', $acrPendingOrderId, 'order_status', 'collected')],
                note: 'Drawn-but-unresulted order: activity=1, order_status=pending, no procedure_report/result rows. Never counts as a result; never resets the overdue clock (T10); composes with overdue_item above to suppress the reorder note.'
            ),
            $this->fact(
                capability: 'control_proxy',
                kind: 'trend_point',
                pid: $pid,
                clinicalDate: $lateDate,
                value: ['raw' => '210', 'parsed' => 210.0, 'comparator' => 'none', 'unitOriginal' => 'mg/dL', 'unitCanonical' => 'mg/dL', 'conversionVersion' => null],
                status: 'final',
                flags: [],
                citations: [$this->citation('procedure_result', $cholResultId, 'result', 'collected')],
                note: 'Late-arriving lab: clinical date (45 days ago) is older than this patient\'s other results already on file. Re-running the seed to add a row like this AFTER a digest exists is the E1 late-arrival eval (digest must change).'
            ),
            $this->fact(
                capability: 'control_proxy',
                kind: 'trend_point',
                pid: $pid,
                clinicalDate: $a1cRecentDate,
                value: ['raw' => '6.9', 'parsed' => 6.9, 'comparator' => 'none', 'unitOriginal' => '%', 'unitCanonical' => '%', 'conversionVersion' => null],
                status: 'final',
                flags: [],
                citations: [$this->citation('procedure_result', $a1cResultId, 'result', 'collected')],
                note: 'Stable baseline A1c draw, more recent (10 days ago) than the late-arriving cholesterol (45 days ago) -- the two clinical dates are out of insertion order.'
            ),
        ];

        $this->patientFixtures[$pubpid] = [
            'pubpid' => $pubpid,
            'pid' => $pid,
            'label' => 'Overdue ACR with active pending order (reorder suppression) + late-arriving cholesterol',
            'facts' => $facts,
        ];
        $this->landmineIndex[] = [
            'landmine' => 'overdue_acr_with_pending_order_reorder_suppression',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'procedure_order',
            'pks' => [$acrPendingOrderId],
            'expected_handling' => 'overdue_item + pending_order both presented; reorder-suppression note IS shown ("do not reorder")',
        ];
        $this->landmineIndex[] = [
            'landmine' => 'drawn_but_unresulted_order',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'procedure_order',
            'pks' => [$acrPendingOrderId],
            'expected_handling' => 'pending_order fact; never counts as a result; never resets overdue clock',
        ];
        $this->landmineIndex[] = [
            'landmine' => 'late_arriving_lab',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'procedure_result',
            'pks' => [$cholResultId],
            'expected_handling' => 'clinical_date (collected) is older than other on-file results for this patient; digest must change on (re-)insertion (E1)',
        ];
    }

    // ------------------------------------------------------------------
    // Patient CCP-003: corrected lab in both variants (in-place + new-row)
    // + "<7.0" censored A1c + unitless glucose.
    // ------------------------------------------------------------------
    private function seedPatientThree(): void
    {
        $pubpid = 'CCP-003';
        $pid = $this->upsertPatientDemographics($pubpid, 'Cara', 'Corrected', '1965-01-22', 'Female');
        $this->clearDependentData($pid);

        // Variant A: in-place correction. Insert as final, then UPDATE the
        // SAME procedure_result row to corrected with a new value.
        $inPlaceDate = $this->today->modify('-60 days');
        $inPlaceOrderId = $this->insertProcedureOrder($pid, $inPlaceDate, $inPlaceDate, 'complete', self::LOINC_A1C, 'Hemoglobin A1c');
        $inPlaceReportId = $this->insertProcedureReport($inPlaceOrderId, $inPlaceDate, $inPlaceDate);
        $inPlaceResultId = $this->insertProcedureResult($inPlaceReportId, self::LOINC_A1C, 'Hemoglobin A1c', 'N', '7.9', '%', 'final', $inPlaceDate);
        QueryUtils::sqlStatementThrowException(
            "UPDATE `procedure_result` SET `result` = ?, `result_status` = ? WHERE `procedure_result_id` = ?",
            ['8.1', 'corrected', $inPlaceResultId]
        );

        // Variant B: new-row correction. Original result, then a SECOND,
        // higher-id procedure_result row (new report, same clinical date)
        // that supersedes it.
        $newRowDate = $this->today->modify('-30 days');
        $newRowOrderId = $this->insertProcedureOrder($pid, $newRowDate, $newRowDate, 'complete', self::LOINC_A1C, 'Hemoglobin A1c');
        $newRowReportOrigId = $this->insertProcedureReport($newRowOrderId, $newRowDate, $newRowDate);
        $newRowOrigResultId = $this->insertProcedureResult($newRowReportOrigId, self::LOINC_A1C, 'Hemoglobin A1c', 'N', '7.5', '%', 'final', $newRowDate);
        $newRowReportCorrId = $this->insertProcedureReport($newRowOrderId, $newRowDate, $this->today);
        $newRowCorrResultId = $this->insertProcedureResult($newRowReportCorrId, self::LOINC_A1C, 'Hemoglobin A1c', 'N', '7.8', '%', 'corrected', $newRowDate);

        // Censored "<7.0" A1c.
        $censoredDate = $this->today->modify('-15 days');
        $censoredOrderId = $this->insertProcedureOrder($pid, $censoredDate, $censoredDate, 'complete', self::LOINC_A1C, 'Hemoglobin A1c');
        $censoredReportId = $this->insertProcedureReport($censoredOrderId, $censoredDate, $censoredDate);
        $censoredResultId = $this->insertProcedureResult($censoredReportId, self::LOINC_A1C, 'Hemoglobin A1c', 'N', '<7.0', '%', 'final', $censoredDate);

        // Unitless glucose.
        $unitlessDate = $this->today->modify('-5 days');
        $unitlessOrderId = $this->insertProcedureOrder($pid, $unitlessDate, $unitlessDate, 'complete', self::LOINC_GLUCOSE, 'Glucose');
        $unitlessReportId = $this->insertProcedureReport($unitlessOrderId, $unitlessDate, $unitlessDate);
        $unitlessResultId = $this->insertProcedureResult($unitlessReportId, self::LOINC_GLUCOSE, 'Glucose', 'N', '110', '', 'final', $unitlessDate);

        // ACR: clean, not overdue.
        $acrDate = $this->today->modify('-2 months');
        $acrOrderId = $this->insertProcedureOrder($pid, $acrDate, $acrDate, 'complete', self::LOINC_ACR, 'Urine Albumin/Creatinine Ratio');
        $acrReportId = $this->insertProcedureReport($acrOrderId, $acrDate, $acrDate);
        $acrResultId = $this->insertProcedureResult($acrReportId, self::LOINC_ACR, 'Urine Albumin/Creatinine Ratio', 'N', '22', 'mg/g', 'final', $acrDate);

        $this->insertScheduleEvent($pid, $this->today, 'Endocrinology Follow-up');

        $facts = [
            $this->fact(
                capability: 'control_proxy',
                kind: 'result',
                pid: $pid,
                clinicalDate: $inPlaceDate,
                value: ['raw' => '8.1', 'parsed' => 8.1, 'comparator' => 'none', 'unitOriginal' => '%', 'unitCanonical' => '%', 'conversionVersion' => null],
                status: 'corrected',
                flags: ['superseded_1'],
                citations: [$this->citation('procedure_result', $inPlaceResultId, 'result', 'collected')],
                note: 'In-place correction: same procedure_result_id row, UPDATEd from final/7.9 to corrected/8.1. Citation notes "supersedes 1 prior result(s)" against its own prior state.'
            ),
            $this->fact(
                capability: 'control_proxy',
                kind: 'result',
                pid: $pid,
                clinicalDate: $newRowDate,
                value: ['raw' => '7.8', 'parsed' => 7.8, 'comparator' => 'none', 'unitOriginal' => '%', 'unitCanonical' => '%', 'conversionVersion' => null],
                status: 'corrected',
                flags: ['superseded_1'],
                citations: [
                    $this->citation('procedure_result', $newRowCorrResultId, 'result', 'collected'),
                    $this->citation('procedure_result', $newRowOrigResultId, 'result', 'collected'),
                ],
                note: 'New-row correction: two procedure_result rows share result_code+clinical date; the corrected row (higher procedure_result_id) wins per C2 supersession and cites the one it supersedes.'
            ),
            $this->fact(
                capability: 'control_proxy',
                kind: 'result',
                pid: $pid,
                clinicalDate: $censoredDate,
                value: ['raw' => '<7.0', 'parsed' => 7.0, 'comparator' => 'lt', 'unitOriginal' => '%', 'unitCanonical' => '%', 'conversionVersion' => null],
                status: 'final',
                flags: ['censored'],
                citations: [$this->citation('procedure_result', $censoredResultId, 'result', 'collected')],
                note: 'Censored comparator value: only supports the claim its direction proves ("below 7.0"), plotted with a marker, never presented as an exact value; NOT a trend point in the strict numeric sense.'
            ),
            $this->fact(
                capability: 'control_proxy',
                kind: 'exclusion',
                pid: $pid,
                clinicalDate: $unitlessDate,
                value: ['raw' => '110', 'parsed' => null, 'comparator' => 'none', 'unitOriginal' => '', 'unitCanonical' => null, 'conversionVersion' => null],
                status: 'excluded',
                flags: ['excluded_reason:unitless'],
                citations: [$this->citation('procedure_result', $unitlessResultId, 'units', 'collected')],
                note: 'No unit, no math (C4, strict): empty units -> verbatim presentation only, excluded from thresholds/trends, counted in the per-analyte unitless-exclusion-rate, visible as an exclusion fact (I5) -- never guessed as mg/dL.'
            ),
        ];

        $this->patientFixtures[$pubpid] = [
            'pubpid' => $pubpid,
            'pid' => $pid,
            'label' => 'Corrected lab both variants (in-place + new-row) + censored <7.0 A1c + unitless glucose',
            'facts' => $facts,
        ];
        $this->landmineIndex[] = [
            'landmine' => 'corrected_lab_in_place',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'procedure_result',
            'pks' => [$inPlaceResultId],
            'expected_handling' => 'status corrected, supersedes 1 prior result(s), single physical row',
        ];
        $this->landmineIndex[] = [
            'landmine' => 'corrected_lab_new_row',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'procedure_result',
            'pks' => [$newRowOrigResultId, $newRowCorrResultId],
            'expected_handling' => 'two rows, same result_code + clinical date; highest procedure_result_id wins (corrected), cites the superseded original',
        ];
        $this->landmineIndex[] = [
            'landmine' => 'censored_value_lt_7_0',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'procedure_result',
            'pks' => [$censoredResultId],
            'expected_handling' => 'comparator=lt, parsed=7.0, flags=[censored], never an exact trend point',
        ];
        $this->landmineIndex[] = [
            'landmine' => 'unitless_value',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'procedure_result',
            'pks' => [$unitlessResultId],
            'expected_handling' => 'excluded (unit: unknown), visible exclusion fact, counted in unitless-exclusion-rate, no unit guessing',
        ];
    }

    // ------------------------------------------------------------------
    // Patient CCP-004: IFCC mmol/mol A1c + unrecognized result_status +
    // preliminary result + outside/reconciled med + in-house Rx (T4 union).
    // ------------------------------------------------------------------
    private function seedPatientFour(): void
    {
        $pubpid = 'CCP-004';
        $pid = $this->upsertPatientDemographics($pubpid, 'Dana', 'Ifcc', '1959-11-03', 'Female');
        $this->clearDependentData($pid);

        // IFCC mmol/mol A1c.
        $ifccDate = $this->today->modify('-20 days');
        $ifccOrderId = $this->insertProcedureOrder($pid, $ifccDate, $ifccDate, 'complete', self::LOINC_A1C, 'Hemoglobin A1c');
        $ifccReportId = $this->insertProcedureReport($ifccOrderId, $ifccDate, $ifccDate);
        $ifccResultId = $this->insertProcedureResult($ifccReportId, self::LOINC_A1C, 'Hemoglobin A1c', 'N', '58', 'mmol/mol', 'final', $ifccDate);
        $ifccCanonicalPercent = round((58 / 10.929) + 2.15, 1);

        // Unrecognized result_status.
        $unrecognizedDate = $this->today->modify('-40 days');
        $unrecognizedOrderId = $this->insertProcedureOrder($pid, $unrecognizedDate, $unrecognizedDate, 'complete', self::LOINC_LDL, 'LDL Cholesterol');
        $unrecognizedReportId = $this->insertProcedureReport($unrecognizedOrderId, $unrecognizedDate, $unrecognizedDate);
        $unrecognizedResultId = $this->insertProcedureResult($unrecognizedReportId, self::LOINC_LDL, 'LDL Cholesterol', 'N', '135', 'mg/dL', 'amended', $unrecognizedDate);

        // Preliminary result.
        $prelimDate = $this->today->modify('-3 days');
        $prelimOrderId = $this->insertProcedureOrder($pid, $prelimDate, $prelimDate, 'complete', self::LOINC_A1C, 'Hemoglobin A1c');
        $prelimReportId = $this->insertProcedureReport($prelimOrderId, $prelimDate, $prelimDate, 'received');
        $prelimResultId = $this->insertProcedureResult($prelimReportId, self::LOINC_A1C, 'Hemoglobin A1c', 'N', '8.2', '%', 'preliminary', $prelimDate);

        // ACR clean, not overdue.
        $acrDate = $this->today->modify('-8 months');
        $acrOrderId = $this->insertProcedureOrder($pid, $acrDate, $acrDate, 'complete', self::LOINC_ACR, 'Urine Albumin/Creatinine Ratio');
        $acrReportId = $this->insertProcedureReport($acrOrderId, $acrDate, $acrDate);
        $acrResultId = $this->insertProcedureResult($acrReportId, self::LOINC_ACR, 'Urine Albumin/Creatinine Ratio', 'N', '18', 'mg/g', 'final', $acrDate);

        // Outside/reconciled med (lists, type=medication) + in-house Rx.
        $medStart = $this->today->modify('-6 months');
        $outsideMedId = $this->insertOutsideMedListRow($pid, 'Atorvastatin 20 MG Oral Tablet', $medStart, 'Reported by patient; reconciled from outside pharmacy record.');
        $inHouseRxId = $this->insertPrescription($pid, 'Lisinopril 10 MG Oral Tablet', '10 mg once daily', $medStart, null, true);

        $this->insertScheduleEvent($pid, $this->today, 'Endocrinology Follow-up');

        $facts = [
            $this->fact(
                capability: 'control_proxy',
                kind: 'trend_point',
                pid: $pid,
                clinicalDate: $ifccDate,
                value: ['raw' => '58', 'parsed' => $ifccCanonicalPercent, 'comparator' => 'none', 'unitOriginal' => 'mmol/mol', 'unitCanonical' => '%', 'conversionVersion' => 'v1'],
                status: 'final',
                flags: [],
                citations: [$this->citation('procedure_result', $ifccResultId, 'result', 'collected')],
                note: "IFCC mmol/mol A1c converted to NGSP % via mod_copilot_cadence code_set=unit_conversion v1 (ngsp_percent = (ifcc_mmol_mol / 10.929) + 2.15); 58 mmol/mol -> {$ifccCanonicalPercent}%. Both original and canonical values + conversion_version must be carried."
            ),
            $this->fact(
                capability: 'control_proxy',
                kind: 'exclusion',
                pid: $pid,
                clinicalDate: $unrecognizedDate,
                value: ['raw' => '135', 'parsed' => 135.0, 'comparator' => 'none', 'unitOriginal' => 'mg/dL', 'unitCanonical' => 'mg/dL', 'conversionVersion' => null],
                status: 'excluded',
                flags: ['excluded_reason:unrecognized_status'],
                citations: [$this->citation('procedure_result', $unrecognizedResultId, 'result_status', 'collected')],
                note: "result_status='amended' is not in the recognized C2 set (final/corrected/''/preliminary/cannot be done/incomplete/error/pending/canceled) -> excluded and flagged, never guessed; does not reset the overdue clock."
            ),
            $this->fact(
                capability: 'pending_results',
                kind: 'preliminary_result',
                pid: $pid,
                clinicalDate: $prelimDate,
                value: ['raw' => '8.2', 'parsed' => 8.2, 'comparator' => 'none', 'unitOriginal' => '%', 'unitCanonical' => '%', 'conversionVersion' => null],
                status: 'preliminary',
                flags: [],
                citations: [$this->citation('procedure_result', $prelimResultId, 'result', 'collected')],
                note: 'Preliminary result: rendered in the in-flight section labeled ("preliminary A1c 8.2 - final pending"), never a trend point, does not reset the overdue clock.'
            ),
            $this->fact(
                capability: 'med_response',
                kind: 'med_event',
                pid: $pid,
                clinicalDate: $medStart,
                value: null,
                status: 'unstated',
                flags: [],
                citations: [$this->citation('lists', $outsideMedId, 'title', 'collected')],
                note: 'Outside/reconciled med in `lists` (type=medication); T4 union member 1 of 2.'
            ),
            $this->fact(
                capability: 'med_response',
                kind: 'med_event',
                pid: $pid,
                clinicalDate: $medStart,
                value: null,
                status: 'final',
                flags: [],
                citations: [$this->citation('prescriptions', $inHouseRxId, 'drug', 'collected')],
                note: 'In-house Rx in `prescriptions`; T4 union member 2 of 2 -- MedResponse must present both via the host PrescriptionService union.'
            ),
        ];

        $this->patientFixtures[$pubpid] = [
            'pubpid' => $pubpid,
            'pid' => $pid,
            'label' => 'IFCC A1c conversion + unrecognized status + preliminary result + outside/in-house med union',
            'facts' => $facts,
        ];
        $this->landmineIndex[] = [
            'landmine' => 'ifcc_mmol_mol_a1c',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'procedure_result',
            'pks' => [$ifccResultId],
            'expected_handling' => "converted 58 mmol/mol -> {$ifccCanonicalPercent}% via unit_conversion v1; original+canonical+version all carried",
        ];
        $this->landmineIndex[] = [
            'landmine' => 'unrecognized_result_status',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'procedure_result',
            'pks' => [$unrecognizedResultId],
            'expected_handling' => "result_status='amended' -> excluded + flagged (unrecognized), visible exclusion, no clock reset",
        ];
        $this->landmineIndex[] = [
            'landmine' => 'preliminary_result',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'procedure_result',
            'pks' => [$prelimResultId],
            'expected_handling' => 'in-flight section, not a trend point, no clock reset',
        ];
        $this->landmineIndex[] = [
            'landmine' => 'outside_and_in_house_med_union',
            'pubpid' => $pubpid,
            'pid' => $pid,
            'table' => 'lists,prescriptions',
            'pks' => [$outsideMedId, $inHouseRxId],
            'expected_handling' => 'both present via PrescriptionService union (T4); never asserted as duplicates or conflicts',
        ];
    }

    // ------------------------------------------------------------------
    // Fixture emission
    // ------------------------------------------------------------------
    private function writeFixtures(): void
    {
        if (!is_dir(self::FIXTURE_DIR)) {
            mkdir(self::FIXTURE_DIR, 0755, true);
        }

        foreach ($this->patientFixtures as $pubpid => $data) {
            $path = self::FIXTURE_DIR . '/' . strtolower(str_replace('-', '_', $pubpid)) . '.json';
            file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
        }

        $landminesPath = self::FIXTURE_DIR . '/landmines.json';
        file_put_contents($landminesPath, json_encode([
            'note' => 'Index of every seeded landmine: which patient/table/row(s) encode it and the expected downstream handling per ARCHITECTURE_COMPLETE.md C1-C4 and the Fact schema. Consumed by U4/U5 contract evals.',
            'landmines' => $this->landmineIndex,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
    }

    /**
     * Builds one Fact-shaped fixture record (ARCHITECTURE_COMPLETE.md "Fact
     * object"). fact_id is intentionally null: it is
     * hash(capability, kind, citations, canonical value), computed by U3's
     * digest function, which does not exist yet -- fabricating a value here
     * would be a fake authority. Everything else is the real expected shape.
     *
     * @param array{raw: string, parsed: float|null, comparator: string, unitOriginal: string, unitCanonical: string|null, conversionVersion: string|null}|null $value
     * @param list<string> $flags
     * @param list<array{table: string, pk: int, field: string|null, date_source: string}> $citations
     * @return array<string, mixed>
     */
    private function fact(
        string $capability,
        string $kind,
        int $pid,
        ?\DateTimeImmutable $clinicalDate,
        ?array $value,
        string $status,
        array $flags,
        array $citations,
        string $note
    ): array {
        return [
            'fact_id' => null,
            'fact_id_note' => 'computed by U3 content-address digest: hash(capability, kind, citations, canonical value); not knowable before U3 exists',
            'capability' => $capability,
            'capability_version' => '1',
            'kind' => $kind,
            'pid' => $pid,
            'clinical_date' => $clinicalDate?->format('Y-m-d'),
            'date_source' => 'collected',
            'value' => $value === null ? null : [
                'raw' => $value['raw'],
                'parsed' => $value['parsed'],
                'comparator' => $value['comparator'],
                'unit_original' => $value['unitOriginal'],
                'unit_canonical' => $value['unitCanonical'],
                'conversion_version' => $value['conversionVersion'],
            ],
            'status' => $status,
            'flags' => $flags,
            'citations' => $citations,
            'note' => $note,
        ];
    }

    /**
     * @return array{table: string, pk: int, field: string|null, date_source: string}
     */
    private function citation(string $table, int $pk, ?string $field, string $dateSource): array
    {
        return ['table' => $table, 'pk' => $pk, 'field' => $field, 'date_source' => $dateSource];
    }

}

(new SeedClinicalCopilot())->run();
