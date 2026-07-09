<?php

/**
 * Dependency-free runtime smoke check for the deterministic core.
 *
 * Complements the PHPUnit suites (tests/Isolated, tests/Db): those need the
 * host's vendor/ (PHPUnit) and, for the DB suite, a live database. This script
 * needs NEITHER -- it hand-rolls a PSR-4 autoloader over the module's own src/
 * (the pure-logic classes have no external dependencies) and asserts the
 * load-bearing lab-contract / capability behaviour, including the review fixes
 * (HL7 status aliasing, unit case-insensitivity, drug-name keying). Run it for
 * an instant green signal before the full stack is up:
 *
 *   php interface/modules/custom_modules/oe-module-clinical-copilot/tests/smoke/deterministic-core-smoke.php
 *
 * Exit 0 = all checks pass; non-zero = a failure (prints which).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$root = __DIR__ . '/../../src/';
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'OpenEMR\\Modules\\ClinicalCopilot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($root . $rel)) {
        require $root . $rel;
    }
});

use OpenEMR\Modules\ClinicalCopilot\Lab\ResultStatusClassifier;
use OpenEMR\Modules\ClinicalCopilot\Lab\UnitConverter;
use OpenEMR\Modules\ClinicalCopilot\Lab\ValueParser;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\LabContractConfig;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\ConversionRule;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ClaimType;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedResponse;

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  PASS  {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}  {$detail}\n";
    }
};

echo "== ResultStatusClassifier (HL7-alias + case) ==\n";
$c = static fn (string $s) => ResultStatusClassifier::classify($s);
$check("'correct' (HL7) => Corrected, presented", $c('correct')->presented && $c('correct')->factStatus === FactStatus::Corrected);
$check("'prelim' (HL7) => Preliminary, in-flight", $c('prelim')->factStatus === FactStatus::Preliminary && $c('prelim')->inFlight);
$check("'CORRECTED' (case) => Corrected", $c('CORRECTED')->factStatus === FactStatus::Corrected);
$check("'  Final ' (case+ws) => Final", $c('  Final ')->factStatus === FactStatus::Final);
$check("'' => Unstated presented", $c('')->presented && $c('')->factStatus === FactStatus::Unstated);
$check("'cannot be done' => excluded UnresultedStatus", !$c('cannot be done')->presented && $c('cannot be done')->exclusionReason === ExclusionReason::UnresultedStatus);
$check("'zzz' => excluded UnrecognizedStatus", !$c('zzz')->presented && $c('zzz')->exclusionReason === ExclusionReason::UnrecognizedStatus);

echo "== UnitConverter (case-insensitivity) ==\n";
$cfg = new LabContractConfig(
    loincToAnalyte: [],
    canonicalUnitByAnalyte: ['glucose' => 'mg/dL'],
    conversionRulesByAnalyte: ['glucose' => ['mmol/L' => ConversionRule::multiplier(18.018)]],
    conversionVersion: 'v1',
    cadenceIntervalByLoinc: [],
    cadenceVersionByLoinc: [],
    thresholdByAnalyte: [],
);
$u = static fn (string $unit, ?float $v) => UnitConverter::convert('glucose', $unit, $v, $cfg);
$check("'MG/DL' (upper canonical) not excluded, value kept", !$u('MG/DL', 100.0)->excluded && $u('MG/DL', 100.0)->convertedValue === 100.0);
$check("'mg/dL' canonical kept", !$u('mg/dL', 100.0)->excluded && $u('mg/dL', 100.0)->convertedValue === 100.0);
$conv = $u('MMOL/L', 5.0);
$check("'MMOL/L' (upper) converted ~90.09", !$conv->excluded && abs(($conv->convertedValue ?? 0.0) - 90.09) < 0.1, 'got=' . ($conv->convertedValue ?? 'null'));
$check("'' empty unit => excluded (no unit no math)", $u('', 100.0)->excluded);
$check("'banana' unknown unit => excluded", $u('banana', 5.0)->excluded);

echo "== ValueParser (C3 censored) ==\n";
$lt = ValueParser::parse('<7.0', 'N');
$check("'<7.0' => comparator Lt, parsed 7.0 (censored, not exact)", $lt->comparator === Comparator::Lt && $lt->parsed === 7.0);
$ex = ValueParser::parse('6.5', 'N');
$check("'6.5' => exact, comparator None, parsed 6.5", $ex->comparator === Comparator::None && $ex->parsed === 6.5);
$q = ValueParser::parse('positive', 'N');
$check("'positive' (N) => no numeric claim (parsed null)", $q->parsed === null);

echo "== MedResponse::drugKey (name-not-first-token) ==\n";
$ref = new ReflectionMethod(MedResponse::class, 'drugKey');
$ref->setAccessible(true);
$dk = static fn (string $s): string => (string) $ref->invoke(null, $s);
$check("'Insulin Glargine 100 UNT/ML' => INSULIN GLARGINE", $dk('Insulin Glargine 100 UNT/ML') === 'INSULIN GLARGINE', 'got=' . $dk('Insulin Glargine 100 UNT/ML'));
$check("'Insulin Aspart 100 UNT/ML' => INSULIN ASPART (distinct)", $dk('Insulin Aspart 100 UNT/ML') === 'INSULIN ASPART', 'got=' . $dk('Insulin Aspart 100 UNT/ML'));
$check("dose change groups: Metformin 500 == Metformin 1000", $dk('Metformin 500 MG') === $dk('Metformin 1000 MG TAB') && $dk('Metformin 500 MG') === 'METFORMIN');
$check("no-dose name kept: 'Lantus' => LANTUS", $dk('Lantus') === 'LANTUS');
$check("Glargine != Aspart (no collapse)", $dk('Insulin Glargine 100 UNT/ML') !== $dk('Insulin Aspart 100 UNT/ML'));

echo "== ClaimType::isZeroCitationEligible (exhaustive match) ==\n";
$check("Greeting eligible for zero citations", ClaimType::Greeting->isZeroCitationEligible() === true);
$check("Refusal eligible", ClaimType::Refusal->isZeroCitationEligible() === true);
$check("LabValue NOT eligible (must cite)", ClaimType::LabValue->isZeroCitationEligible() === false);
$check("Conflict NOT eligible (must cite)", ClaimType::Conflict->isZeroCitationEligible() === false);

echo "\n== RESULT: {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
