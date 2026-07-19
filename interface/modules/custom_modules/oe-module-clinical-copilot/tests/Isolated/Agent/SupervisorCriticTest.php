<?php

/**
 * The supervisor's critic stage hard-gates composed answers: uncited/unsafe claims never leave the graph.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Agent;

use OpenEMR\Modules\ClinicalCopilot\Agent\AgentRequest;
use OpenEMR\Modules\ClinicalCopilot\Agent\AnswerStatus;
use OpenEMR\Modules\ClinicalCopilot\Agent\ComposedAnswer;
use OpenEMR\Modules\ClinicalCopilot\Agent\CriticWorker;
use OpenEMR\Modules\ClinicalCopilot\Agent\EvidenceRetrieverWorker;
use OpenEMR\Modules\ClinicalCopilot\Agent\IntakeExtractorWorker;
use OpenEMR\Modules\ClinicalCopilot\Agent\Supervisor;
use OpenEMR\Modules\ClinicalCopilot\Agent\SupervisorResult;
use OpenEMR\Modules\ClinicalCopilot\Agent\WorkerName;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineCorpus;
use OpenEMR\Modules\ClinicalCopilot\Rag\SparseRetriever;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce\StubLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Verify\CheckId;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * Failure modes guarded: the multi-agent path emitting a fabricated claim
 * that cites nothing (an uncited "creatinine of 2.4" no gathered fact
 * supports), emitting banned causation/dosing advice, emitting a claim that
 * cites another patient's fact, or the critic running invisibly (no `verify`
 * child span under the supervisor span, so the handoff would not be
 * inspectable from the trace). Also pins that the gate is a HARD default:
 * these tests deliberately clear CLINICAL_COPILOT_VERIFY_ENFORCE and rely on
 * {@see \OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPolicy}'s
 * enforced-by-default posture, while one test proves the QA-only `=0`
 * relaxation still works in the disable direction.
 */
final class SupervisorCriticTest extends TestCase
{
    private const PID = 42;

    protected function setUp(): void
    {
        // These tests assert the OUT-OF-THE-BOX posture (gate enforced by
        // default), so clear any override a sibling test may have leaked.
        putenv('CLINICAL_COPILOT_VERIFY_ENFORCE');
    }

    protected function tearDown(): void
    {
        putenv('CLINICAL_COPILOT_VERIFY_ENFORCE');
    }

    private function supervisor(ComposedAnswer $draft, TraceRecorderInterface $tracer): Supervisor
    {
        $llm = StubLlmClient::up(new LlmResponse('{"fields":[]}', 'gemini-2.5-pro', 900, 30, 500));

        return new Supervisor(
            new IntakeExtractorWorker(new ExtractionClient($llm, 'gemini-2.5-pro'), $tracer),
            new EvidenceRetrieverWorker(
                new SparseRetriever(new GuidelineCorpus(dirname(__DIR__, 3) . '/src/Rag/corpus')),
                $tracer,
            ),
            new CriticWorker(new Verifier(), $tracer),
            $tracer,
            StubAnswerComposer::returning($draft),
        );
    }

    private function committedA1cFact(int $pid = self::PID): Fact
    {
        return FactTestFactory::a1cTrendPoint($pid, 4201, '7.8', '2026-01-10');
    }

    /**
     * @param list<array<string, mixed>> $claims
     */
    private static function claimsJson(array $claims): string
    {
        return (string)json_encode($claims, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function claim(array $overrides): array
    {
        return $overrides + [
            'text' => 'placeholder',
            'claim_type' => 'lab_value',
            'citation_ids' => [],
            'numeric_values' => [],
            'flags' => [],
            'order' => 0,
            'emphasis' => null,
        ];
    }

    private static function assertBlocked(SupervisorResult $result, AnswerStatus $expected): void
    {
        self::assertSame($expected, $result->answerStatus);
        self::assertTrue($result->answerBlocked());
        self::assertNull($result->answer, 'a blocked draft must never surface its claims');
        self::assertSame("couldn't produce a verifiable answer", $result->refusalMessage);
    }

    public function testFabricatedUncitedClaimIsBlockedAndItsTextNeverSurfaces(): void
    {
        $fabricated = 'Her creatinine today is 2.4 mg/dL and her kidney function is declining.';
        $draft = new ComposedAnswer(
            self::claimsJson([
                self::claim(['text' => $fabricated, 'citation_ids' => [], 'numeric_values' => [2.4]]),
            ]),
            [$this->committedA1cFact()],
        );

        $tracer = new RecordingTraceRecorder();
        $result = $this->supervisor($draft, $tracer)
            ->handle(new AgentRequest(pid: self::PID, correlationId: 'crit-1'));

        self::assertBlocked($result, AnswerStatus::Refused);
        self::assertTrue($result->routedTo(WorkerName::Critic));

        $v2 = null;
        foreach ($result->verdicts as $verdict) {
            if ($verdict->checkId === CheckId::CitationResolution) {
                $v2 = $verdict;
            }
        }
        self::assertNotNull($v2, 'the critic must record a citation-resolution verdict');
        self::assertFalse($v2->passed, 'an uncited clinical claim must fail V2');

        // The fabricated text is structurally unreachable: not in the answer
        // (null), and the result serializes without it.
        self::assertStringNotContainsString('creatinine', (string)json_encode($result->answer));

        // The refusal is loud in the trace too: the supervisor span degrades.
        $supervisorSpans = $tracer->spansOfKind('supervisor');
        self::assertCount(1, $supervisorSpans);
        self::assertSame('degraded', $supervisorSpans[0]->status);
    }

    public function testBannedDosingRecommendationIsRefusedEvenWhenCited(): void
    {
        // Properly cited, so V2 passes -- the refusal must come from the V5
        // banned-claim lint (recommendation/dosage advice).
        $fact = $this->committedA1cFact();
        $draft = new ComposedAnswer(
            self::claimsJson([
                self::claim([
                    'text' => 'She should increase the dose of metformin.',
                    'claim_type' => 'med_event',
                    'citation_ids' => [$fact->factId],
                ]),
            ]),
            [$fact],
        );

        $result = $this->supervisor($draft, new RecordingTraceRecorder())
            ->handle(new AgentRequest(pid: self::PID, correlationId: 'crit-2'));

        self::assertBlocked($result, AnswerStatus::Refused);

        $v5 = null;
        foreach ($result->verdicts as $verdict) {
            if ($verdict->checkId === CheckId::BannedClaimLint) {
                $v5 = $verdict;
            }
        }
        self::assertNotNull($v5);
        self::assertFalse($v5->passed, 'dosing/recommendation language must fail the V5 banned-claim lint');
    }

    public function testWrongPatientCitationFreezesUnconditionally(): void
    {
        // The composed claim cites a fact whose pid is NOT the request's pid:
        // the V3 sev-1 freeze, mirroring the chat path, applies regardless of
        // the content-gate policy.
        $foreignFact = $this->committedA1cFact(pid: 777);
        $draft = new ComposedAnswer(
            self::claimsJson([
                self::claim([
                    'text' => 'The most recent hemoglobin A1c on file is 7.8 %.',
                    'citation_ids' => [$foreignFact->factId],
                    'numeric_values' => [7.8],
                ]),
            ]),
            [$foreignFact],
        );

        $tracer = new RecordingTraceRecorder();
        $result = $this->supervisor($draft, $tracer)
            ->handle(new AgentRequest(pid: self::PID, correlationId: 'crit-3'));

        self::assertBlocked($result, AnswerStatus::FrozenSev1);
        self::assertSame('error', $tracer->spansOfKind('supervisor')[0]->status);
        self::assertSame('error', $tracer->spansOfKind('verify')[0]->status);
    }

    public function testGroundedCitedAnswerPassesTheCritic(): void
    {
        $fact = $this->committedA1cFact();
        $draft = new ComposedAnswer(
            self::claimsJson([
                self::claim([
                    'text' => 'The most recent hemoglobin A1c on file is 7.8 %.',
                    'citation_ids' => [$fact->factId],
                    'numeric_values' => [7.8],
                ]),
            ]),
            [$fact],
        );

        $result = $this->supervisor($draft, new RecordingTraceRecorder())
            ->handle(new AgentRequest(pid: self::PID, correlationId: 'crit-4'));

        self::assertSame(AnswerStatus::Answered, $result->answerStatus);
        self::assertFalse($result->answerBlocked());
        self::assertNotNull($result->answer);
        self::assertCount(1, $result->answer);
        self::assertContains($fact->factId, $result->answer[0]->citationIds);
        self::assertNull($result->refusalMessage);
        foreach ($result->verdicts as $verdict) {
            self::assertTrue($verdict->passed, "check {$verdict->checkId->value} must pass on a grounded, cited claim");
        }
    }

    public function testCriticExecutionIsRecordedAsAVerifyChildSpanOfTheSupervisorSpan(): void
    {
        $fact = $this->committedA1cFact();
        $draft = new ComposedAnswer(
            self::claimsJson([
                self::claim([
                    'text' => 'The most recent hemoglobin A1c on file is 7.8 %.',
                    'citation_ids' => [$fact->factId],
                    'numeric_values' => [7.8],
                ]),
            ]),
            [$fact],
        );

        $tracer = new RecordingTraceRecorder();
        $this->supervisor($draft, $tracer)
            ->handle(new AgentRequest(pid: self::PID, correlationId: 'crit-5'));

        $supervisorSpans = $tracer->spansOfKind('supervisor');
        $verifySpans = $tracer->spansOfKind('verify');
        self::assertCount(1, $supervisorSpans, 'exactly one supervisor root span');
        self::assertCount(1, $verifySpans, 'exactly one critic (verify) span');

        $supervisorSpan = $supervisorSpans[0];
        $criticSpan = $verifySpans[0];
        self::assertSame($supervisorSpan->spanId, $criticSpan->parentSpanId, 'the critic span must be a CHILD of the supervisor span');
        self::assertNull($supervisorSpan->parentSpanId, 'the supervisor span is the root of the handoff tree');
        self::assertSame('crit-5', $criticSpan->correlationId);
        self::assertSame(self::PID, $criticSpan->pid);
        self::assertSame('ok', $criticSpan->status);
    }

    public function testQaEnvRelaxationStillWorksInTheDisableDirection(): void
    {
        // The default flipped to enforced; CLINICAL_COPILOT_VERIFY_ENFORCE=0
        // must still relax the gate for QA (the override works BOTH ways).
        // The verdict ledger keeps recording what would have been blocked.
        putenv('CLINICAL_COPILOT_VERIFY_ENFORCE=0');

        $draft = new ComposedAnswer(
            self::claimsJson([
                self::claim(['text' => 'Her creatinine today is 2.4 mg/dL.', 'numeric_values' => [2.4]]),
            ]),
            [$this->committedA1cFact()],
        );

        $result = $this->supervisor($draft, new RecordingTraceRecorder())
            ->handle(new AgentRequest(pid: self::PID, correlationId: 'crit-6'));

        self::assertSame(AnswerStatus::Answered, $result->answerStatus);
        self::assertNotNull($result->answer);

        $failed = array_filter($result->verdicts, static fn ($v): bool => !$v->passed && !$v->skipped);
        self::assertNotSame([], $failed, 'even relaxed, the critic records the failing verdicts');
    }
}
