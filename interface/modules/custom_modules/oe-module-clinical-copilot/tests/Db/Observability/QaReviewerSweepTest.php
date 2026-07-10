<?php

/**
 * DB-backed U12 acceptance evals: the post-mortem QA sweep is idempotent, advisory-only, and honest about unavailability.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Observability;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnStore;
use OpenEMR\Modules\ClinicalCopilot\Doc\NewDoc;
use OpenEMR\Modules\ClinicalCopilot\Doc\QaStatus;
use OpenEMR\Modules\ClinicalCopilot\Doc\RegenReason;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\DocQaAnnotator;
use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\FlashReviewer;
use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaReviewer;
use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaStore;
use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaTargetType;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\PatientIdentifierLookup;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisDocPayload;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ClaimType;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * docs/build-notes.md "U12 additions": the sweep is (a) idempotent -- one
 * verdict per target, ever; (b) advisory -- writing it never touches
 * `mod_copilot_doc.doc` (the served content); (c) honest about
 * `status='unavailable'` when no ADC/LLM is configured; (d) never blocks --
 * every branch below completes without throwing, regardless of the stub
 * LLM's behavior.
 */
final class QaReviewerSweepTest extends TestCase
{
    private const SYNTHETIC_PID = 999201;

    private DocStore $docStore;
    private QaStore $qaStore;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->docStore = new DocStore();
        $this->qaStore = new QaStore();
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    private function reviewer(string $rawJson): QaReviewer
    {
        return new QaReviewer(
            $this->qaStore,
            new DocQaAnnotator(),
            new FlashReviewer(StubQaLlmClient::up($rawJson)),
            new PatientIdentifierLookup(),
            new ChatSessionStore(),
            new ChatTurnStore(),
        );
    }

    private function reviewerDown(): QaReviewer
    {
        return new QaReviewer(
            $this->qaStore,
            new DocQaAnnotator(),
            new FlashReviewer(StubQaLlmClient::down()),
            new PatientIdentifierLookup(),
            new ChatSessionStore(),
            new ChatTurnStore(),
        );
    }

    private function insertPassedDoc(): int
    {
        $fact = FactTestFactory::a1cTrendPoint(pid: self::SYNTHETIC_PID);
        $claim = new Claim('A1c is 7.2%', ClaimType::LabValue, [$fact->factId], [7.2], [], 0);
        $doc = SynthesisDocPayload::build([$fact], [$claim], VerifyStatus::Passed, null, null, [], 1);

        return $this->docStore->insert(new NewDoc(
            self::SYNTHETIC_PID,
            'digest-' . bin2hex(random_bytes(8)),
            'endo-previsit-v1',
            null,
            $doc,
            ['control_proxy' => '1'],
            'reduce-v1',
            bin2hex(random_bytes(16)),
            VerifyStatus::Passed,
            RegenReason::None,
        ));
    }

    private function insertDegradedDoc(): int
    {
        $fact = FactTestFactory::a1cTrendPoint(pid: self::SYNTHETIC_PID);
        $doc = SynthesisDocPayload::build([$fact], null, VerifyStatus::Degraded, 'llm_unavailable', 'narrative unavailable', [], 1);

        return $this->docStore->insert(new NewDoc(
            self::SYNTHETIC_PID,
            'digest-' . bin2hex(random_bytes(8)),
            'endo-previsit-v1',
            null,
            $doc,
            ['control_proxy' => '1'],
            'reduce-v1',
            bin2hex(random_bytes(16)),
            VerifyStatus::Degraded,
            RegenReason::None,
        ));
    }

    public function testConcurringReviewMarksDocOkWithAScore(): void
    {
        $docId = $this->insertPassedDoc();

        $summary = $this->reviewer('{"concurs":true,"salience_ok":true,"flags":[],"reviewer_note":"looks fine"}')->sweep(5000);

        self::assertGreaterThanOrEqual(1, $summary->swept);
        $outcome = self::findOutcome($summary->docOutcomes(), $docId);
        self::assertNotNull($outcome);
        self::assertSame('ok', $outcome->status);
        self::assertSame(QaStatus::Ok, $outcome->qaStatus);
        self::assertEqualsWithDelta(1.0, $outcome->qaScore, 0.0001);

        $docRow = QueryUtils::querySingleRow('SELECT `qa_status`, `qa_score` FROM `mod_copilot_doc` WHERE `id` = ?', [$docId]);
        self::assertSame('ok', $docRow['qa_status']);
        self::assertEqualsWithDelta(1.0, (float)$docRow['qa_score'], 0.0001);

        self::assertTrue($this->qaStore->existsFor(QaTargetType::Doc, $docId));
    }

    public function testDisagreeingReviewMarksDocLow(): void
    {
        $docId = $this->insertPassedDoc();

        $summary = $this->reviewer('{"concurs":false,"salience_ok":true,"flags":[{"claim_ref":"claim 0","class":"emphasis","note":"overstated"}],"reviewer_note":"emphasis concern"}')->sweep(5000);

        $outcome = self::findOutcome($summary->docOutcomes(), $docId);
        self::assertNotNull($outcome);
        self::assertSame(QaStatus::Low, $outcome->qaStatus);

        $docRow = QueryUtils::querySingleRow('SELECT `qa_status` FROM `mod_copilot_doc` WHERE `id` = ?', [$docId]);
        self::assertSame('low', $docRow['qa_status']);
    }

    public function testSweepIsIdempotentAcrossRuns(): void
    {
        $docId = $this->insertPassedDoc();
        $reviewer = $this->reviewer('{"concurs":true,"salience_ok":true,"flags":[],"reviewer_note":"fine"}');

        $first = $reviewer->sweep(5000);
        self::assertNotNull(self::findOutcome($first->docOutcomes(), $docId));

        $second = $reviewer->sweep(5000);
        self::assertNull(self::findOutcome($second->docOutcomes(), $docId), 'a target already scored must never be swept twice');

        $count = (int)QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM `mod_copilot_qa` WHERE `target_type` = ? AND `target_id` = ?',
            'c',
            ['doc', $docId],
        );
        self::assertSame(1, $count, 'UNIQUE(target_type, target_id) -- exactly one verdict, ever');
    }

    public function testNoAdcWritesUnavailableAndNeverThrows(): void
    {
        $docId = $this->insertPassedDoc();

        $summary = $this->reviewerDown()->sweep(5000);

        $outcome = self::findOutcome($summary->docOutcomes(), $docId);
        self::assertNotNull($outcome);
        self::assertSame('unavailable', $outcome->status);
        self::assertNull($outcome->qaScore);

        $docRow = QueryUtils::querySingleRow('SELECT `qa_status` FROM `mod_copilot_doc` WHERE `id` = ?', [$docId]);
        self::assertSame('unavailable', $docRow['qa_status']);

        $qaRow = QueryUtils::querySingleRow('SELECT `status` FROM `mod_copilot_qa` WHERE `target_type` = ? AND `target_id` = ?', ['doc', $docId]);
        self::assertSame('unavailable', $qaRow['status']);
    }

    public function testDegradedDocIsRecordedAsOkWithNoReviewNeeded(): void
    {
        $docId = $this->insertDegradedDoc();

        // A "down" stub proves the point cleanly: a degraded (facts-only)
        // doc must never even ATTEMPT a Flash call (there is no narrative to
        // review), so this must succeed even though the LLM is unavailable.
        $summary = $this->reviewerDown()->sweep(5000);

        $outcome = self::findOutcome($summary->docOutcomes(), $docId);
        self::assertNotNull($outcome);
        self::assertSame('ok', $outcome->status);
        self::assertSame(QaStatus::Ok, $outcome->qaStatus);

        $docRow = QueryUtils::querySingleRow('SELECT `qa_status` FROM `mod_copilot_doc` WHERE `id` = ?', [$docId]);
        self::assertSame('ok', $docRow['qa_status']);
    }

    public function testSweepNeverThrowsEvenWithMalformedFlashResponse(): void
    {
        $docId = $this->insertPassedDoc();

        $summary = $this->reviewer('not valid json at all')->sweep(5000);

        $outcome = self::findOutcome($summary->docOutcomes(), $docId);
        self::assertNotNull($outcome);
        self::assertSame('error', $outcome->status);

        // Advisory-only: even an error target still gets annotated (as
        // Unavailable, the closest fit) rather than left dangling in
        // 'pending' forever.
        $docRow = QueryUtils::querySingleRow('SELECT `qa_status` FROM `mod_copilot_doc` WHERE `id` = ?', [$docId]);
        self::assertSame('unavailable', $docRow['qa_status']);
    }

    /**
     * @param list<\OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaSweepOutcome> $outcomes
     */
    private static function findOutcome(array $outcomes, int $targetId): ?\OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaSweepOutcome
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->targetId === $targetId) {
                return $outcome;
            }
        }

        return null;
    }
}
