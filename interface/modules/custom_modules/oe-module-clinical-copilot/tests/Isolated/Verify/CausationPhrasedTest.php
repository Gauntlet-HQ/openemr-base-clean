<?php

/**
 * V5 (banned-claim lint): causation, recommendation, diagnosis, dosage, and interaction phrasing are all blocked.
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
 * Failure mode guarded: the model asserting causation between a medication
 * and a lab result (ARCHITECTURE.md §2.2, V5 row; I8) instead of merely
 * juxtaposing both facts by citation. Also covers the sibling banned classes
 * (recommendation/diagnosis/dosage/interaction) the same lexicon guards.
 */
final class CausationPhrasedTest extends TestCase
{
    public function testExplicitCausationPhrasingIsRejected(): void
    {
        $fact = VerifyTestFactory::a1cLater();
        $factSet = VerifyTestFactory::sessionFactSet([$fact]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim(
                'A1c rose to 7.6 because the metformin dose was increased last month.',
                'trend',
                [$fact->factId],
                [7.6],
            ),
        ]);

        $result = (new Verifier())->verify($json, $context);

        $v5 = $result->find(CheckId::BannedClaimLint);
        self::assertNotNull($v5);
        self::assertFalse($v5->passed);
        self::assertStringContainsString('causation', $v5->findings[0]);
    }

    public function testAfterImpliesCausationOnlyWithBothADrugAndAnAnalyteTerm(): void
    {
        $fact = VerifyTestFactory::a1cLater();
        $factSet = VerifyTestFactory::sessionFactSet([$fact]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        $blocked = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c improved after starting metformin.', 'trend', [$fact->factId]),
        ]);
        $allowed = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('Drawn after the morning fast.', 'lab_value', [$fact->factId]),
        ]);

        $blockedResult = (new Verifier())->verify($blocked, $context);
        $allowedResult = (new Verifier())->verify($allowed, $context);

        self::assertFalse($blockedResult->find(CheckId::BannedClaimLint)?->passed);
        self::assertTrue($allowedResult->find(CheckId::BannedClaimLint)?->passed);
    }

    public function testTreatmentRecommendationPhrasingIsRejected(): void
    {
        $fact = VerifyTestFactory::a1cLater();
        $factSet = VerifyTestFactory::sessionFactSet([$fact]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('The patient should increase their metformin dose.', 'trend', [$fact->factId]),
        ]);

        $result = (new Verifier())->verify($json, $context);

        self::assertFalse($result->find(CheckId::BannedClaimLint)?->passed);
    }

    public function testDiagnosisPhrasingIsRejected(): void
    {
        $fact = VerifyTestFactory::a1cLater();
        $factSet = VerifyTestFactory::sessionFactSet([$fact]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('This pattern is consistent with a diagnosis of nephropathy.', 'trend', [$fact->factId]),
        ]);

        $result = (new Verifier())->verify($json, $context);

        self::assertFalse($result->find(CheckId::BannedClaimLint)?->passed);
    }

    public function testANeutralJuxtapositionWithNoTriggerPhraseIsAllowed(): void
    {
        $fact = VerifyTestFactory::a1cLater();
        $factSet = VerifyTestFactory::sessionFactSet([$fact]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('Metformin dose was increased; the next A1c was 7.6.', 'trend', [$fact->factId], [7.6]),
        ]);

        $result = (new Verifier())->verify($json, $context);

        self::assertTrue($result->find(CheckId::BannedClaimLint)?->passed);
    }
}
