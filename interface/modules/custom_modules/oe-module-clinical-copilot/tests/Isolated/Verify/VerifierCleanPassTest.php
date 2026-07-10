<?php

/**
 * A clean, well-cited output passes all six checks with no findings.
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
 * The positive control every failure-mode test in this suite is contrasted
 * against: a narrative with correctly grounded numbers, correct citations,
 * no banned phrasing, and its one conflict fact properly flagged and cited
 * must pass all six checks, with all six verdicts recorded
 * (ARCHITECTURE_COMPLETE.md U10 acceptance criterion).
 */
final class VerifierCleanPassTest extends TestCase
{
    public function testACleanCitedNarrativePassesAllSixChecks(): void
    {
        $early = VerifyTestFactory::a1cEarly();
        $later = VerifyTestFactory::a1cLater();
        $conflict = VerifyTestFactory::conflictedGlucose();
        $factSet = VerifyTestFactory::sessionFactSet([$early, $later, $conflict]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim(
                'A1c rose from 7.2 to 7.6 across two draws.',
                'trend',
                [$early->factId, $later->factId],
                [7.2, 7.6],
                [],
                0,
            ),
            VerifyTestFactory::claim(
                'Glucose was reported as 190; the parsed value and the lab flag disagree on whether this is out of range.',
                'conflict',
                [$conflict->factId],
                [190.0],
                ['conflict'],
                1,
            ),
        ]);

        $result = (new Verifier())->verify($json, $context);

        self::assertTrue($result->allPassed());
        self::assertNotNull($result->claims);
        self::assertCount(2, $result->claims);

        foreach (CheckId::cases() as $checkId) {
            $verdict = $result->find($checkId);
            self::assertNotNull($verdict, "verdict for {$checkId->value} must be recorded");
            self::assertTrue($verdict->passed, "{$checkId->value} should pass: " . implode('; ', $verdict->findings));
            self::assertFalse($verdict->skipped);
        }
    }
}
