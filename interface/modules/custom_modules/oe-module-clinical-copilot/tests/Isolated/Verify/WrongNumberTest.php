<?php

/**
 * V4 (numeric grounding): the classic hallucination -- right citation, wrong number.
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
 * Failure mode guarded: ARCHITECTURE.md §2.2, V4 row -- "the classic
 * hallucination: right citation, wrong number." A claim citing a real fact
 * but asserting a number that fact does not carry must be blocked; the
 * check never performs arithmetic itself.
 */
final class WrongNumberTest extends TestCase
{
    public function testANumberNotPresentInTheCitedFactIsRejected(): void
    {
        $fact = VerifyTestFactory::a1cEarly();
        $factSet = VerifyTestFactory::sessionFactSet([$fact]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        // The cited fact's parsed value is 7.2 -- 9.9 does not appear anywhere in it.
        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('Most recent A1c was 9.9.', 'lab_value', [$fact->factId], [9.9]),
        ]);

        $result = (new Verifier())->verify($json, $context);

        $v4 = $result->find(CheckId::NumericGrounding);
        self::assertNotNull($v4);
        self::assertFalse($v4->passed);
        self::assertStringContainsString('9.9', $v4->findings[0]);
    }

    public function testANumberOnlyInFreeTextNotInNumericValuesIsStillChecked(): void
    {
        $fact = VerifyTestFactory::a1cEarly();
        $factSet = VerifyTestFactory::sessionFactSet([$fact]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('Most recent A1c was 9.9.', 'lab_value', [$fact->factId]),
        ]);

        $result = (new Verifier())->verify($json, $context);

        self::assertFalse($result->find(CheckId::NumericGrounding)?->passed);
    }

    public function testAMatchingNumberFromTheCitedFactPasses(): void
    {
        $fact = VerifyTestFactory::a1cEarly();
        $factSet = VerifyTestFactory::sessionFactSet([$fact]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('Most recent A1c was 7.2.', 'lab_value', [$fact->factId], [7.2]),
        ]);

        $result = (new Verifier())->verify($json, $context);

        self::assertTrue($result->find(CheckId::NumericGrounding)?->passed);
    }

    public function testADateInProseIsExemptNarrative(): void
    {
        $fact = VerifyTestFactory::a1cEarly();
        $factSet = VerifyTestFactory::sessionFactSet([$fact]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        // A date stated in prose is narrative, not a data pull: under the V4
        // grounding policy (only actual medications/results/readings must
        // ground) a date needs no citation, so even a date that does not match
        // the cited fact's clinical_date no longer fails V4.
        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c was drawn on 2025-06-30.', 'lab_value', [$fact->factId]),
        ]);

        $result = (new Verifier())->verify($json, $context);

        self::assertTrue($result->find(CheckId::NumericGrounding)?->passed);
    }
}
