<?php

/**
 * Adversarial: a claim citing a fact belonging to a different patient must freeze the session (V3 sev-1).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat;

use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ClaimType;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * USERS.md UC6: "any question about a different patient ... structurally
 * impossible (§1.2) and additionally refused in prose." Structural
 * impossibility is {@see \OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolExecutor}'s
 * job (no tool accepts a pid, tested against real capabilities in
 * `tests/Db/Chat/ToolExecutorTest.php`). This test exercises the SECOND
 * layer of defense (I10: "asserted on tool return AND re-verified on every
 * output citation") entirely in isolation: a fact belonging to a different
 * pid already sitting in the session fact set (simulating an upstream
 * defect that slipped past the executor) is cited by the model's answer --
 * {@see \OpenEMR\Modules\ClinicalCopilot\Chat\ChatAgent} must treat this as
 * V3's sev-1 case: no retry, session frozen, a {@see \OpenEMR\Modules\ClinicalCopilot\Verify\Sev1Signal}
 * raised with the correct correlation id and pinned pid.
 */
final class CrossPatientForgedCitationTest extends TestCase
{
    private const WRONG_PID = 999;

    public function testCitingAnotherPatientsFactFreezesTheSession(): void
    {
        $wrongPatientFact = FactTestFactory::a1cTrendPoint(self::WRONG_PID, 1, '9.9', '2025-01-01');

        $llm = QueuedChatLlmClient::up([
            ChatTestFactory::finalAnswerResponse([
                ['text' => 'Her A1c is 9.9%.', 'claim_type' => ClaimType::LabValue->value, 'citation_ids' => [$wrongPatientFact->factId], 'numeric_values' => [9.9], 'flags' => [], 'order' => 0, 'emphasis' => null],
            ]),
        ]);
        $agent = ChatTestFactory::chatAgent($llm, new StubToolExecutor());

        $answer = $agent->answer(ChatTestFactory::PINNED_PID, 'corr-forged-citation', [$wrongPatientFact], null, [], 'What is her A1c?');

        self::assertTrue($answer->frozen, 'a cross-patient citation must freeze the session, never be shown');
        self::assertSame(VerifyStatus::Degraded, $answer->verifyStatus);
        self::assertNotNull($answer->sev1Signal);
        self::assertSame('corr-forged-citation', $answer->sev1Signal->correlationId);
        self::assertSame(ChatTestFactory::PINNED_PID, $answer->sev1Signal->pinnedPid);
        self::assertSame(1, $llm->callCount(), 'V3 is never retried (ARCHITECTURE.md §2.3), unlike an ordinary check failure');
    }
}
