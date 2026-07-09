<?php

/**
 * V4 numeric-grounding policy: actual data pulls must ground; narrative
 * numbers (dates, frequencies, ages, disease type/stage, doses) are exempt.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Verify;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Verify\CheckId;
use OpenEMR\Modules\ClinicalCopilot\Verify\SessionFactSet;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationContext;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPath;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * Locks the loosened V4 rule: every actual clinical value a claim pulls (a
 * result cited as `value-result`) must ground, but ordinary clinical English
 * -- disease type/stage, how long ago / how often, ages, and medication doses
 * -- no longer trips the check. A stray value stated in prose but not exempt
 * is still caught, so the "every medical pull is verified" guarantee holds.
 */
final class VerifierNumericGroundingTest extends TestCase
{
    private const PID = 42;

    private function factSet(): SessionFactSet
    {
        // One grounded A1c result: value 7.2, cited id "a1c".
        $a1c = Fact::fromArray([
            'fact_id' => 'a1c',
            'capability' => 'control_proxy',
            'capability_version' => '1',
            'kind' => 'result',
            'pid' => self::PID,
            'clinical_date' => '2024-03-15',
            'date_source' => 'collected',
            'value' => ['raw' => '7.2 %', 'parsed' => 7.2, 'comparator' => 'none', 'unit_original' => '%', 'unit_canonical' => '%', 'conversion_version' => null],
            'status' => 'final',
            'flags' => [],
            'citations' => [['table' => 'procedure_result', 'pk' => 1001, 'field' => 'result', 'date_source' => 'collected']],
        ]);

        // One med event with no numeric value, cited id "met".
        $med = Fact::fromArray([
            'fact_id' => 'met',
            'capability' => 'med_response',
            'capability_version' => '1',
            'kind' => 'med_event',
            'pid' => self::PID,
            'clinical_date' => '2023-01-01',
            'date_source' => 'collected',
            'value' => null,
            'status' => 'final',
            'flags' => [],
            'citations' => [['table' => 'prescriptions', 'pk' => 2002, 'field' => 'drug', 'date_source' => 'collected']],
        ]);

        return new SessionFactSet(self::PID, [$a1c, $med]);
    }

    private function v4(string $text, string $claimType, string $citeId, string $numericValues = '[]'): bool
    {
        $claim = json_encode([[
            'text' => $text,
            'claim_type' => $claimType,
            'citation_ids' => [$citeId],
            'numeric_values' => json_decode($numericValues, true),
            'flags' => [],
            'order' => 0,
            'emphasis' => null,
        ]], JSON_THROW_ON_ERROR);

        $result = (new Verifier())->verify($claim, new VerificationContext($this->factSet(), VerificationPath::Chat));
        $verdict = $result->find(CheckId::NumericGrounding);

        return $verdict !== null && $verdict->passed;
    }

    /**
     * @dataProvider exemptNarrativeProvider
     */
    public function testNarrativeNumbersAreExempt(string $text, string $type, string $cite): void
    {
        self::assertTrue($this->v4($text, $type, $cite), "V4 should PASS for narrative-number text: {$text}");
    }

    /**
     * @return array<string, array{string, string, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function exemptNarrativeProvider(): array
    {
        return [
            'grounded result value' => ['The most recent A1c is 7.2.', 'lab_value', 'a1c'],
            'disease type' => ['Her A1c of 7.2 reflects her type 2 diabetes.', 'lab_value', 'a1c'],
            'duration phrase' => ['Her A1c of 7.2 has held over the past 3 months.', 'trend', 'a1c'],
            'incidental count in prose' => ['The A1c of 7.2 is her latest of many.', 'lab_value', 'a1c'],
            'age phrase' => ['At 68 years old her A1c is 7.2.', 'lab_value', 'a1c'],
            'medication dose + frequency' => ['She takes metformin 1000 mg twice daily.', 'med_event', 'met'],
            'stray prose date no longer grounded' => ['The A1c of 7.2 was drawn 2019-01-01.', 'lab_value', 'a1c'],
        ];
    }

    public function testStrayUngroundedValueStillFails(): void
    {
        // "7.9" is a clinical value the model states in prose but no cited fact
        // carries it -- the guard must still catch it.
        self::assertFalse(
            $this->v4('Her A1c is 7.9.', 'lab_value', 'a1c'),
            'V4 must FAIL when a claim states a value not carried by any cited fact'
        );
    }

    public function testDeclaredNumericValueMustGround(): void
    {
        // A number the model DECLARES it is asserting must ground even if the
        // prose alone would have been exempt.
        self::assertFalse(
            $this->v4('Her control is worse than her type 2 peers.', 'lab_value', 'a1c', '[9.9]'),
            'V4 must FAIL when a declared numeric_value is not grounded'
        );
    }
}
