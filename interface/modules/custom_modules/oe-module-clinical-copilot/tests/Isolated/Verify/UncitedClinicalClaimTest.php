<?php

/**
 * V2 (citation resolution): a clinical claim smuggled in under a zero-citation-eligible type is still blocked.
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
 * Failure mode guarded: the model declaring a claim `greeting` (legally
 * zero-citation) while smuggling an actual lab value into the text --
 * ARCHITECTURE.md §2.2, V2 row: "any claim mentioning an analyte,
 * medication, numeric value, date, or patient attribute is clinical
 * regardless of its declared type and must cite."
 */
final class UncitedClinicalClaimTest extends TestCase
{
    public function testGreetingThatSmugglesInALabValueMustStillCite(): void
    {
        $factSet = VerifyTestFactory::sessionFactSet([VerifyTestFactory::a1cEarly()]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('Good morning -- her A1c is 7.2 today.', 'greeting'),
        ]);

        $result = (new Verifier())->verify($json, $context);

        self::assertFalse($result->allPassed());
        $v2 = $result->find(CheckId::CitationResolution);
        self::assertNotNull($v2);
        self::assertFalse($v2->passed);
    }

    public function testAGenuinelyContentFreeGreetingMayOmitCitations(): void
    {
        $factSet = VerifyTestFactory::sessionFactSet([VerifyTestFactory::a1cEarly()]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('Good morning -- here is the pre-visit summary.', 'greeting'),
        ]);

        $result = (new Verifier())->verify($json, $context);

        $v2 = $result->find(CheckId::CitationResolution);
        self::assertNotNull($v2);
        self::assertTrue($v2->passed);
    }

    public function testAClinicalTypeClaimWithNoCitationsIsAlwaysRejected(): void
    {
        $factSet = VerifyTestFactory::sessionFactSet([VerifyTestFactory::a1cEarly()]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c is trending upward.', 'trend'),
        ]);

        $result = (new Verifier())->verify($json, $context);

        $v2 = $result->find(CheckId::CitationResolution);
        self::assertNotNull($v2);
        self::assertFalse($v2->passed);
    }

    public function testAFabricatedCitationIdThatResolvesToNothingIsRejected(): void
    {
        $factSet = VerifyTestFactory::sessionFactSet([VerifyTestFactory::a1cEarly()]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c was 7.2.', 'lab_value', ['fact-that-does-not-exist'], [7.2]),
        ]);

        $result = (new Verifier())->verify($json, $context);

        $v2 = $result->find(CheckId::CitationResolution);
        self::assertNotNull($v2);
        self::assertFalse($v2->passed);
    }
}
