<?php

/**
 * V3 (patient identity guard): a citation resolving to another patient's fact is a sev-1, not a retry.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Verify;

use OpenEMR\Modules\ClinicalCopilot\Verify\CheckId;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationContext;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPath;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a fact belonging to a different patient than the
 * session's pinned pid reaching the session fact set (a defect upstream of
 * the LLM -- the tool executor is supposed to prevent this on ingest, I10).
 * V3 is the independent re-check on the way out (ARCHITECTURE.md §2.2/§2.3)
 * and, unlike every other check, its failure is sev-1: {@see VerificationResult::hasSev1()}
 * must report true so {@see \OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGeneration}
 * never retries it.
 */
final class WrongPatientTest extends TestCase
{
    public function testCitingAFactFromAnotherPatientFailsV3AndTripsSev1(): void
    {
        $factSet = VerifyTestFactory::sessionFactSet([
            VerifyTestFactory::a1cEarly(),
            VerifyTestFactory::wrongPatientVital(),
        ]);
        $context = new VerificationContext($factSet, VerificationPath::Chat);

        $wrongPatientFactId = VerifyTestFactory::wrongPatientVital()->factId;
        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('Weight was 180 lb.', 'vital', [$wrongPatientFactId], [180.0]),
        ]);

        $result = (new Verifier())->verify($json, $context);

        $v3 = $result->find(CheckId::PatientIdentity);
        self::assertNotNull($v3);
        self::assertFalse($v3->passed);
        self::assertTrue($result->hasSev1());
        self::assertStringContainsString((string)VerifyTestFactory::WRONG_PID, $v3->findings[0]);
    }

    public function testACleanCitationToThePinnedPatientNeverTripsV3(): void
    {
        $factSet = VerifyTestFactory::sessionFactSet([VerifyTestFactory::a1cEarly()]);
        $context = new VerificationContext($factSet, VerificationPath::Chat);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c was 7.2.', 'lab_value', [VerifyTestFactory::a1cEarly()->factId], [7.2]),
        ]);

        $result = (new Verifier())->verify($json, $context);

        $v3 = $result->find(CheckId::PatientIdentity);
        self::assertNotNull($v3);
        self::assertTrue($v3->passed);
        self::assertFalse($result->hasSev1());
    }
}
