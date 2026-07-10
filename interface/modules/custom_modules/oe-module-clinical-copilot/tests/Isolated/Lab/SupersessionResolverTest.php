<?php

/**
 * Lab contract C2 supersession: corrected > final > '' > preliminary, ties by highest pk.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Lab;

use OpenEMR\Modules\ClinicalCopilot\Lab\SupersessionCandidate;
use OpenEMR\Modules\ClinicalCopilot\Lab\SupersessionResolver;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: an older, lower-ranked result (or a lower-id row of
 * equal rank) winning a supersession group and rendering a stale/incorrect
 * value as if it were the current one.
 */
final class SupersessionResolverTest extends TestCase
{
    public function testSingleCandidateWinsTrivially(): void
    {
        $result = SupersessionResolver::resolve([new SupersessionCandidate(42, 2)]);

        self::assertSame(42, $result->winnerProcedureResultId);
        self::assertSame([], $result->supersededProcedureResultIds);
        self::assertSame(0, $result->supersededCount());
    }

    /**
     * New-row correction variant: two physical rows, the corrected one (rank 3)
     * beats the final one (rank 2) regardless of insertion order.
     */
    public function testCorrectedBeatsFinal(): void
    {
        $result = SupersessionResolver::resolve([
            new SupersessionCandidate(10, 2), // final
            new SupersessionCandidate(11, 3), // corrected
        ]);

        self::assertSame(11, $result->winnerProcedureResultId);
        self::assertSame([10], $result->supersededProcedureResultIds);
        self::assertSame(1, $result->supersededCount());
    }

    public function testFinalBeatsUnstated(): void
    {
        $result = SupersessionResolver::resolve([
            new SupersessionCandidate(20, 1), // ''
            new SupersessionCandidate(21, 2), // final
        ]);

        self::assertSame(21, $result->winnerProcedureResultId);
    }

    public function testUnstatedBeatsPreliminary(): void
    {
        $result = SupersessionResolver::resolve([
            new SupersessionCandidate(30, 0), // preliminary
            new SupersessionCandidate(31, 1), // ''
        ]);

        self::assertSame(31, $result->winnerProcedureResultId);
    }

    /**
     * Tie-break: same rank, highest procedure_result_id wins -- and must win
     * regardless of which order the candidates are supplied in.
     */
    public function testTiedRankBreaksOnHighestId(): void
    {
        $ascending = SupersessionResolver::resolve([
            new SupersessionCandidate(100, 2),
            new SupersessionCandidate(101, 2),
        ]);
        $descending = SupersessionResolver::resolve([
            new SupersessionCandidate(101, 2),
            new SupersessionCandidate(100, 2),
        ]);

        self::assertSame(101, $ascending->winnerProcedureResultId);
        self::assertSame(101, $descending->winnerProcedureResultId);
    }

    public function testThreeWayGroupSupersedesAllLosers(): void
    {
        $result = SupersessionResolver::resolve([
            new SupersessionCandidate(1, 0), // preliminary
            new SupersessionCandidate(2, 2), // final
            new SupersessionCandidate(3, 3), // corrected
        ]);

        self::assertSame(3, $result->winnerProcedureResultId);
        self::assertEqualsCanonicalizing([1, 2], $result->supersededProcedureResultIds);
        self::assertSame(2, $result->supersededCount());
    }

    public function testEmptyCandidateListThrows(): void
    {
        $this->expectException(\DomainException::class);

        SupersessionResolver::resolve([]);
    }
}
