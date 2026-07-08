<?php

/**
 * DB-backed U6 acceptance evals: DocStore append-only + T22 best-of-N selection.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\DocStore;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Doc\NewDoc;
use OpenEMR\Modules\ClinicalCopilot\Doc\RegenReason;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use PHPUnit\Framework\TestCase;

/**
 * `mod_copilot_doc` has no FK against core tables (its `pid` column is a
 * read-only reference by convention, not a foreign key), so these evals use
 * a synthetic pid rather than depending on the U2 seed.
 *
 * E7: DocStore's PUBLIC API is exactly `insert()` + `findBest()` -- there is
 * no update/delete method to call, verified both by direct exercise (rows
 * read back unmutated after further inserts) and by reflection.
 *
 * T22: `findBest()` selects the most recent `verify_status='passed'` row,
 * preferring higher `qa_score`; falls back to the latest `degraded` row when
 * none passed. Because DocStore's own `insert()` never accepts a `qa_score`
 * (only U12's not-yet-built QA sweep would ever set one, and how it does so
 * without violating append-only is explicitly out of U6's scope -- see the
 * U5/U6 report), the best-of-N ordering eval seeds rows with distinct
 * `qa_score` values via direct SQL (a legitimate test fixture, not a
 * DocStore code path) and exercises ONLY the read side, `findBest()`.
 */
final class DocStoreTest extends TestCase
{
    private const SYNTHETIC_PID = 999001;

    private DocStore $docStore;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->docStore = new DocStore();
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    private function newDoc(string $digest, VerifyStatus $verifyStatus, RegenReason $regenReason = RegenReason::None): NewDoc
    {
        return new NewDoc(
            self::SYNTHETIC_PID,
            $digest,
            'endo-previsit-v1',
            null,
            ['facts' => [], 'narrative' => $verifyStatus === VerifyStatus::Passed ? 'a verified narrative' : null],
            ['control_proxy' => '1', 'med_response' => '1'],
            'reduce-v1',
            bin2hex(random_bytes(16)),
            $verifyStatus,
            $regenReason,
        );
    }

    /**
     * Eval E7: append-only. Two inserts against the SAME (pid, digest) both
     * persist as distinct rows (the relaxed key, T22); re-reading the first
     * row's content afterward proves it was never mutated by the second
     * insert; the row count for this digest never decreases.
     */
    public function testAppendOnlyRowsCoexistAndStayImmutable(): void
    {
        $digest = 'digest-' . bin2hex(random_bytes(8));

        $firstId = $this->docStore->insert($this->newDoc($digest, VerifyStatus::Passed));
        $countAfterFirst = $this->countRowsForDigest($digest);
        self::assertSame(1, $countAfterFirst);

        $firstRowBefore = $this->fetchRawRow($firstId);

        $secondId = $this->docStore->insert($this->newDoc($digest, VerifyStatus::Passed));
        self::assertNotSame($firstId, $secondId);

        $countAfterSecond = $this->countRowsForDigest($digest);
        self::assertSame(2, $countAfterSecond, 'row count for this (pid, digest) must never decrease -- it only grows');

        $firstRowAfter = $this->fetchRawRow($firstId);
        self::assertSame($firstRowBefore, $firstRowAfter, 'the first row must be byte-identical after a second attempt is appended -- E7');
    }

    /**
     * E7, structural: DocStore's public surface has no update/delete method
     * to accidentally call -- a reader auditing "can this ledger be
     * mutated in code" needs only this class's method list.
     */
    public function testNoUpdateOrDeleteMethodExistsOnDocStore(): void
    {
        $reflection = new \ReflectionClass(DocStore::class);
        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $m): string => strtolower($m->getName()),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach ($publicMethodNames as $name) {
            self::assertStringNotContainsString('update', $name);
            self::assertStringNotContainsString('delete', $name);
        }

        self::assertEqualsCanonicalizing(['insert', 'findbest'], $publicMethodNames, 'DocStore declares no constructor -- its only public surface is insert() and findBest()');
    }

    /**
     * T22 eval: among `verify_status='passed'` rows for one (pid, digest),
     * `findBest()` prefers the HIGHER `qa_score`, even when it is not the
     * most recently inserted row.
     */
    public function testBestOfNPrefersHigherQaScorePassedRow(): void
    {
        $digest = 'digest-' . bin2hex(random_bytes(8));

        $lowId = $this->docStore->insert($this->newDoc($digest, VerifyStatus::Passed));
        $highId = $this->docStore->insert($this->newDoc($digest, VerifyStatus::Passed));
        // Older row (lowId) scores higher than the newer one (highId) --
        // proves the selection is NOT simply "most recent".
        $this->setQaScore($lowId, 0.900);
        $this->setQaScore($highId, 0.400);

        $best = $this->docStore->findBest(self::SYNTHETIC_PID, $digest);

        self::assertNotNull($best);
        self::assertSame($lowId, $best->id, 'the higher-scoring passed row must win even though it is the older attempt');
        self::assertEqualsWithDelta(0.900, $best->qaScore, 0.001);
    }

    /**
     * T22 eval: when no `verify_status='passed'` row exists for a digest,
     * `findBest()` falls back to the latest `degraded` row (facts-only, I6)
     * rather than returning null (a false cache miss would trigger an
     * unnecessary fresh LLM call on a path that already has a valid,
     * verified-degraded fallback to serve).
     */
    public function testDegradedFallbackWhenNonePassed(): void
    {
        $digest = 'digest-' . bin2hex(random_bytes(8));

        $this->docStore->insert($this->newDoc($digest, VerifyStatus::Degraded, RegenReason::VerifyRetry));
        $secondDegradedId = $this->docStore->insert($this->newDoc($digest, VerifyStatus::Degraded, RegenReason::VerifyRetry));

        $best = $this->docStore->findBest(self::SYNTHETIC_PID, $digest);

        self::assertNotNull($best);
        self::assertSame(VerifyStatus::Degraded, $best->verifyStatus);
        self::assertSame($secondDegradedId, $best->id, 'the LATEST degraded row wins when none passed');
    }

    /**
     * T22 eval: a digest with no row at all is a true miss -- findBest()
     * returns null (the caller extracts fresh and runs reduce+verify; this
     * is not DocStore's concern).
     */
    public function testNoRowsIsATrueMiss(): void
    {
        self::assertNull($this->docStore->findBest(self::SYNTHETIC_PID, 'digest-that-was-never-inserted'));
    }

    /**
     * T22 eval: multiple attempts per (pid, digest) coexist -- the relaxed
     * (pid, fact_digest, id) index (replacing the old UNIQUE key) does not
     * reject a third, fourth, ... insert for the same digest.
     */
    public function testManyAttemptsPerDigestCoexist(): void
    {
        $digest = 'digest-' . bin2hex(random_bytes(8));

        for ($i = 0; $i < 5; $i++) {
            $this->docStore->insert($this->newDoc($digest, VerifyStatus::Passed));
        }

        self::assertSame(5, $this->countRowsForDigest($digest));
    }

    private function countRowsForDigest(string $digest): int
    {
        $count = QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM `mod_copilot_doc` WHERE `pid` = ? AND `fact_digest` = ?',
            'c',
            [self::SYNTHETIC_PID, $digest],
        );

        return (int)$count;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRawRow(int $id): array
    {
        $row = QueryUtils::querySingleRow('SELECT * FROM `mod_copilot_doc` WHERE `id` = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * Test-only fixture helper: sets qa_score directly via SQL, simulating
     * what a future QA-sweep writer would need to do post-insert -- NOT a
     * DocStore method (DocStore has no such path, see this class's docblock).
     */
    private function setQaScore(int $id, float $score): void
    {
        QueryUtils::sqlStatementThrowException(
            "UPDATE `mod_copilot_doc` SET `qa_score` = ?, `qa_status` = 'ok' WHERE `id` = ?",
            [$score, $id],
        );
    }
}
