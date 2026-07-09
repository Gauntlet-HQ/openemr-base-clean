<?php

/**
 * VerifiedGeneration's fail-closed loop: reduce -> verify -> one retry -> degrade.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Verify;

use OpenEMR\Modules\ClinicalCopilot\Doc\RegenReason;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptContext;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ReduceRequest;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationContext;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPath;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGeneration;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGenerationRequest;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGenerationResult;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §2.3: "Any check fails -> one regeneration with the
 * verifier's specific findings appended to the prompt. Second failure -> the
 * response is discarded, never shown." Exercised end to end here with real
 * {@see Reducer}/{@see Verifier} instances and only the LLM boundary
 * stubbed (build-notes.md: "No live LLM calls anywhere in tests").
 */
final class VerifiedGenerationTest extends TestCase
{
    public function testFirstAttemptCleanPassesWithoutAnyRetry(): void
    {
        $client = new QueuedLlmClient([self::cleanResponse()]);
        $result = $this->runGeneration($client, VerificationPath::Synthesis);

        self::assertSame(VerifyStatus::Passed, $result->verifyStatus);
        self::assertSame(RegenReason::None, $result->regenReason);
        self::assertSame(1, $result->attempts);
        self::assertSame(1, $client->callCount());
        self::assertNotNull($result->claims);
        self::assertCount(1, $result->claims);
        self::assertNotNull($result->redactionMap);
    }

    public function testAnOrdinaryFailureIsRetriedOnceAndThenPasses(): void
    {
        $client = new QueuedLlmClient([self::wrongNumberResponse(), self::cleanResponse()]);
        $result = $this->runGeneration($client, VerificationPath::Synthesis);

        self::assertSame(VerifyStatus::Passed, $result->verifyStatus);
        self::assertSame(RegenReason::VerifyRetry, $result->regenReason);
        self::assertSame(2, $result->attempts);
        self::assertSame(2, $client->callCount());

        // The retry prompt must carry the first attempt's specific findings.
        $secondCallPrompt = $client->calls()[1]->userContent;
        self::assertStringContainsString('PRIOR VERIFICATION FINDINGS', $secondCallPrompt);
        self::assertStringContainsString('V4', $secondCallPrompt);
    }

    public function testTwoConsecutiveFailuresDiscardAndDegradeToFactsOnly(): void
    {
        $client = new QueuedLlmClient([self::wrongNumberResponse(), self::wrongNumberResponse()]);
        $result = $this->runGeneration($client, VerificationPath::Synthesis);

        self::assertSame(VerifyStatus::Degraded, $result->verifyStatus);
        self::assertSame(RegenReason::VerifyRetry, $result->regenReason);
        self::assertSame(2, $result->attempts);
        self::assertSame(2, $client->callCount());
        self::assertNull($result->claims);
        self::assertSame('narrative unavailable', $result->degradedMessage);
        self::assertFalse($result->frozen);
    }

    public function testTwoConsecutiveFailuresOnChatDegradeWithTheChatMessage(): void
    {
        $client = new QueuedLlmClient([self::wrongNumberResponse(), self::wrongNumberResponse()]);
        $result = $this->runGeneration($client, VerificationPath::Chat);

        self::assertSame(VerifyStatus::Degraded, $result->verifyStatus);
        self::assertSame("couldn't produce a verifiable answer", $result->degradedMessage);
    }

    public function testLlmUnavailableDegradesImmediatelyWithNoVerificationAndNoRetry(): void
    {
        $client = self::downClient();
        $result = $this->runGeneration($client, VerificationPath::Synthesis);

        self::assertSame(VerifyStatus::Degraded, $result->verifyStatus);
        self::assertSame(RegenReason::None, $result->regenReason);
        self::assertSame(1, $result->attempts);
        self::assertSame([], $result->verdicts);
        self::assertFalse($result->frozen);
        // The return value must carry the rich cause (temporary debug aid): the
        // low-level reason category and the underlying provider/ADC message are
        // both surfaced, not just a generic "unavailable" banner.
        self::assertStringContainsString('no_credentials', (string)$result->llmUnavailableDetail);
        self::assertStringContainsString('no ADC in this test environment', (string)$result->llmUnavailableDetail);
        self::assertStringContainsString('no_credentials', (string)$result->degradedMessage);
    }

    public function testAWrongPatientCitationFreezesOnTheFirstAttemptWithNoRetry(): void
    {
        // Only ONE queued response -- if VerifiedGeneration ever retried a
        // sev-1, QueuedLlmClient would throw on the second call and this
        // test would fail loudly rather than silently passing.
        $wrongPatientFact = VerifyTestFactory::wrongPatientVital();
        $client = new QueuedLlmClient([new LlmResponse(
            VerifyTestFactory::claimsJson([
                VerifyTestFactory::claim('Weight was 180 lb.', 'vital', [$wrongPatientFact->factId], [180.0]),
            ]),
            'gemini-2.5-pro',
            10,
            10,
            50,
        )]);

        $facts = [VerifyTestFactory::a1cEarly(), $wrongPatientFact];
        $reducer = new Reducer($client, new PromptAssembler(), new Redactor());
        $verifiedGeneration = new VerifiedGeneration($reducer, new Verifier());

        $request = new VerifiedGenerationRequest(
            new ReduceRequest(
                'session-1',
                'corr-1',
                $facts,
                new PatientIdentifiers('Jane Q. Sampleton', 'MRN-1', '1968-04-11', '19 Birchwood Ln'),
                new PromptContext('endo-previsit-v1', 'reduce-v1'),
            ),
            new VerificationContext(VerifyTestFactory::sessionFactSet($facts), VerificationPath::Chat),
        );

        $result = $verifiedGeneration->generate($request);

        self::assertSame(VerifyStatus::Degraded, $result->verifyStatus);
        self::assertTrue($result->frozen);
        self::assertNotNull($result->sev1Signal);
        self::assertSame(VerifyTestFactory::PINNED_PID, $result->sev1Signal->pinnedPid);
        self::assertSame(1, $result->attempts);
        self::assertSame(1, $client->callCount());
    }

    private function runGeneration(LlmClientInterface $client, VerificationPath $path): VerifiedGenerationResult
    {
        $facts = [VerifyTestFactory::a1cEarly(), VerifyTestFactory::a1cLater()];
        $reducer = new Reducer($client, new PromptAssembler(), new Redactor());
        $verifiedGeneration = new VerifiedGeneration($reducer, new Verifier());

        $request = new VerifiedGenerationRequest(
            new ReduceRequest(
                'session-1',
                'corr-1',
                $facts,
                new PatientIdentifiers('Jane Q. Sampleton', 'MRN-1', '1968-04-11', '19 Birchwood Ln'),
                new PromptContext('endo-previsit-v1', 'reduce-v1'),
            ),
            new VerificationContext(VerifyTestFactory::sessionFactSet($facts), $path),
        );

        return $verifiedGeneration->generate($request);
    }

    private static function cleanResponse(): LlmResponse
    {
        $early = VerifyTestFactory::a1cEarly();
        $later = VerifyTestFactory::a1cLater();
        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('A1c rose from 7.2 to 7.6.', 'trend', [$early->factId, $later->factId], [7.2, 7.6]),
        ]);

        return new LlmResponse($json, 'gemini-2.5-pro', 100, 40, 900);
    }

    private static function wrongNumberResponse(): LlmResponse
    {
        $early = VerifyTestFactory::a1cEarly();
        $json = VerifyTestFactory::claimsJson([
            VerifyTestFactory::claim('Most recent A1c was 9.9.', 'lab_value', [$early->factId], [9.9]),
        ]);

        return new LlmResponse($json, 'gemini-2.5-pro', 100, 40, 900);
    }

    private static function downClient(): LlmClientInterface
    {
        return new class implements LlmClientInterface {
            public function generateStructured(PromptRequest $req): LlmResponse
            {
                throw LlmUnavailableException::noCredentials(new \RuntimeException('no ADC in this test environment'));
            }
        };
    }
}
