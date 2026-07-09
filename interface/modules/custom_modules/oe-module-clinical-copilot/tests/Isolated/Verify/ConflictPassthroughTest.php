<?php

/**
 * V6 (conflict passthrough): a conflict fact must be flagged when cited, and never silently dropped in a synthesis.
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
 * Failure mode guarded (ARCHITECTURE.md §2.2, V6 row; I8): the LLM
 * adjudicating (or silently hiding) a data conflict instead of surfacing it.
 * Two distinct presence checks over the closed conflict-flagged-fact set --
 * NOT general omission detection.
 */
final class ConflictPassthroughTest extends TestCase
{
    public function testCitingAConflictFactWithoutTheConflictFlagIsRejected(): void
    {
        $conflictFact = VerifyTestFactory::conflictedGlucose();
        $factSet = VerifyTestFactory::sessionFactSet([$conflictFact]);
        $context = new VerificationContext($factSet, VerificationPath::Chat);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('Glucose reading was 190.', 'lab_value', [$conflictFact->factId], [190.0]),
        ]);

        $result = (new Verifier())->verify($json, $context);

        $v6 = $result->find(CheckId::ConflictPassthrough);
        self::assertNotNull($v6);
        self::assertFalse($v6->passed);
        self::assertStringContainsString('conflict', $v6->findings[0]);
    }

    public function testCitingAConflictFactWithTheConflictFlagPasses(): void
    {
        $conflictFact = VerifyTestFactory::conflictedGlucose();
        $factSet = VerifyTestFactory::sessionFactSet([$conflictFact]);
        $context = new VerificationContext($factSet, VerificationPath::Chat);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('Glucose reading was 190 (conflicting proofs).', 'conflict', [$conflictFact->factId], [190.0], ['conflict']),
        ]);

        $result = (new Verifier())->verify($json, $context);

        self::assertTrue($result->find(CheckId::ConflictPassthrough)?->passed);
    }

    public function testASynthesisThatOmitsAConflictFlaggedFactEntirelyIsRejected(): void
    {
        $conflictFact = VerifyTestFactory::conflictedGlucose();
        $otherFact = VerifyTestFactory::a1cEarly();
        $factSet = VerifyTestFactory::sessionFactSet([$conflictFact, $otherFact]);
        $context = new VerificationContext($factSet, VerificationPath::Synthesis);

        // The narrative only ever cites the unrelated A1c fact -- the
        // conflict-flagged glucose fact is never cited by any claim.
        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c was 7.2.', 'lab_value', [$otherFact->factId], [7.2]),
        ]);

        $result = (new Verifier())->verify($json, $context);

        $v6 = $result->find(CheckId::ConflictPassthrough);
        self::assertNotNull($v6);
        self::assertFalse($v6->passed);
        self::assertStringContainsString($conflictFact->factId, $v6->findings[0]);
    }

    public function testAChatAnswerScopedAwayFromTheConflictFactDoesNotTripV6ii(): void
    {
        // Unlike the synthesis path, a chat turn is scoped to the question
        // asked -- it must not be forced to cite every conflict fact in the
        // whole session just because one happens to be in scope.
        $conflictFact = VerifyTestFactory::conflictedGlucose();
        $otherFact = VerifyTestFactory::a1cEarly();
        $factSet = VerifyTestFactory::sessionFactSet([$conflictFact, $otherFact]);
        $context = new VerificationContext($factSet, VerificationPath::Chat);

        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c was 7.2.', 'lab_value', [$otherFact->factId], [7.2]),
        ]);

        $result = (new Verifier())->verify($json, $context);

        self::assertTrue($result->find(CheckId::ConflictPassthrough)?->passed);
    }
}
