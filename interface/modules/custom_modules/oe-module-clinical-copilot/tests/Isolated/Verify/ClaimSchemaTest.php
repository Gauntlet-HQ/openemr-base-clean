<?php

/**
 * V1 (schema gate): guards free prose / malformed output reaching semantic checks.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Verify;

use OpenEMR\Modules\ClinicalCopilot\Verify\ClaimSchema;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: free-form prose (or any structurally invalid JSON)
 * from the model reaching V2-V6, which assume a valid list of typed claims.
 * ARCHITECTURE.md §2.1: "Free prose without claim structure is
 * schema-rejected before any semantic check runs."
 */
final class ClaimSchemaTest extends TestCase
{
    public function testFreeProseIsRejected(): void
    {
        $result = (new ClaimSchema())->parse('Her A1c looks stable this visit.');

        self::assertFalse($result->valid);
        self::assertNotEmpty($result->errors);
    }

    public function testNonArrayJsonIsRejected(): void
    {
        $result = (new ClaimSchema())->parse('{"text": "not a list"}');

        self::assertFalse($result->valid);
    }

    public function testMissingRequiredFieldIsRejected(): void
    {
        $json = VerifyTestFactory::claimsJson([
            ['text' => 'A1c 7.2', 'claim_type' => 'lab_value'],
        ]);

        $result = (new ClaimSchema())->parse($json);

        self::assertFalse($result->valid);
    }

    public function testUnrecognizedClaimTypeIsRejected(): void
    {
        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c 7.2', 'not_a_real_type', ['f1']),
        ]);

        $result = (new ClaimSchema())->parse($json);

        self::assertFalse($result->valid);
    }

    public function testValidClaimListParses(): void
    {
        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c was 7.2.', 'lab_value', ['f1'], [7.2]),
        ]);

        $result = (new ClaimSchema())->parse($json);

        self::assertTrue($result->valid);
        self::assertCount(1, $result->claims);
    }

    /**
     * The answer-producing agent-loop round carries tool declarations, and
     * Gemini function-calling and `responseSchema` are mutually exclusive, so
     * that round is unconstrained: Flash-class models fence the array in
     * ```json even when told not to. V1 is the documented client-side backstop
     * (ARCHITECTURE.md §2.1) and must unwrap the fence, not reject the turn.
     */
    public function testFencedJsonIsUnwrappedAndParses(): void
    {
        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c was 7.2.', 'lab_value', ['f1'], [7.2]),
        ]);

        $result = (new ClaimSchema())->parse("```json\n{$json}\n```");

        self::assertTrue($result->valid);
        self::assertCount(1, $result->claims);
    }

    public function testFenceWithoutLanguageTagIsUnwrapped(): void
    {
        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c was 7.2.', 'lab_value', ['f1'], [7.2]),
        ]);

        $result = (new ClaimSchema())->parse("```\n{$json}\n```");

        self::assertTrue($result->valid);
        self::assertCount(1, $result->claims);
    }

    public function testProseWrappedArrayIsExtracted(): void
    {
        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c was 7.2.', 'lab_value', ['f1'], [7.2]),
        ]);

        $result = (new ClaimSchema())->parse("Here are the claims: {$json} Hope that helps.");

        self::assertTrue($result->valid);
        self::assertCount(1, $result->claims);
    }

    public function testSurroundingWhitespaceIsTolerated(): void
    {
        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c was 7.2.', 'lab_value', ['f1'], [7.2]),
        ]);

        $result = (new ClaimSchema())->parse("\n\n  {$json}  \n");

        self::assertTrue($result->valid);
        self::assertCount(1, $result->claims);
    }

    /**
     * Extraction is a backstop for wrapping, never a schema relaxation: a
     * fenced JSON object is still not a claim-list array and must be rejected.
     */
    public function testFencedNonArrayJsonIsStillRejected(): void
    {
        $result = (new ClaimSchema())->parse("```json\n{\"text\": \"not a list\"}\n```");

        self::assertFalse($result->valid);
    }

    public function testProseWithoutAnyArrayIsStillRejected(): void
    {
        $result = (new ClaimSchema())->parse('I could not find that information for this patient.');

        self::assertFalse($result->valid);
    }
}
