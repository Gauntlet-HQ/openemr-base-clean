<?php

/**
 * ReadyCheck's Week-2 dependency-state mappers (document store, knowledge, reranker).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Observability;

use OpenEMR\Modules\ClinicalCopilot\Observability\ReadyCheck;
use PHPUnit\Framework\TestCase;

/**
 * Failure modes guarded:
 *
 *  - /ready claiming 'ok' for a documents directory that is missing or
 *    unwritable (uploads would dead-end while the probe stays green), or
 *    pretending an on-disk check covers a CouchDB-configured site instead of
 *    reporting 'remote-unprobed' honestly.
 *  - the knowledge mapping folding "not configured" (a healthy offline-corpus
 *    deployment) into a failure state -- or the reverse: reporting a down
 *    Postgres as ok. The enum mapping is the ONE place /ready and the
 *    dashboard semantics could diverge, so it is pinned here.
 *  - the reranker state drifting away from the documented static
 *    "in-process, no probe needed" contract.
 */
final class ReadyCheckDependencyStateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/copilot-ready-probe-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            @chmod($this->dir, 0755);
            @rmdir($this->dir);
        }
    }

    public function testHardDiskStorageWithWritableDirectoryIsOk(): void
    {
        self::assertSame('ok', ReadyCheck::documentStoreState('0', $this->dir));
    }

    public function testUnsetStorageMethodMeansTheHardDiskDefaultAndStillProbesTheDirectory(): void
    {
        self::assertSame(
            'ok',
            ReadyCheck::documentStoreState(null, $this->dir),
            "an unset document_storage_method is the '0' Hard Disk default and must still be probed",
        );
    }

    public function testMissingDocumentsDirectoryIsReportedMissing(): void
    {
        self::assertSame('missing', ReadyCheck::documentStoreState('0', $this->dir . '/does-not-exist'));
    }

    public function testUnwritableDocumentsDirectoryIsReportedNotWritable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root bypasses directory write permissions, so the access(2) check cannot fail');
        }

        chmod($this->dir, 0555);

        self::assertSame('not_writable', ReadyCheck::documentStoreState('0', $this->dir));
    }

    public function testRemoteStorageMethodIsHonestlyReportedUnprobedNotOk(): void
    {
        // '1' is CouchDB in core globals; ANY non-hard-disk method must report
        // remote-unprobed rather than pretend the on-disk check covered it --
        // even when a local documents dir also happens to exist and be writable.
        self::assertSame('remote-unprobed', ReadyCheck::documentStoreState('1', $this->dir));
    }

    public function testUnresolvableDirectoryDegradesToUnknownNotError(): void
    {
        self::assertSame('unknown', ReadyCheck::documentStoreState('0', null));
        self::assertSame('unknown', ReadyCheck::documentStoreState('0', ''));
    }

    public function testKnowledgeSnapshotStatesMapOntoTheReadyEnums(): void
    {
        self::assertSame('ok', ReadyCheck::knowledgeStateFromSnapshot(['state' => 'ok', 'configured' => true, 'chunk_count' => 42]));
        self::assertSame(
            'offline-corpus',
            ReadyCheck::knowledgeStateFromSnapshot(['state' => 'not_configured', 'configured' => false, 'chunk_count' => null]),
            'no external knowledge DB is a NORMAL healthy state (in-repo corpus), never a failure enum',
        );
        self::assertSame('driver-missing', ReadyCheck::knowledgeStateFromSnapshot(['state' => 'driver_missing', 'configured' => true, 'chunk_count' => null]));
        self::assertSame('unreachable', ReadyCheck::knowledgeStateFromSnapshot(['state' => 'unreachable', 'configured' => true, 'chunk_count' => null]));
    }

    public function testUnrecognisedKnowledgeSnapshotDegradesToUnknown(): void
    {
        self::assertSame('unknown', ReadyCheck::knowledgeStateFromSnapshot([]));
        self::assertSame('unknown', ReadyCheck::knowledgeStateFromSnapshot(['state' => 'something-new']));
    }

    public function testRerankerIsAStaticInProcessConfiguredState(): void
    {
        // The production reranker (HeuristicReranker, W7) is in-process code
        // behind the RerankerInterface seam: it cannot be down independently of
        // the process serving /ready, so its readiness is a constant -- only a
        // remote reranker would warrant a live probe.
        self::assertSame('in-process', ReadyCheck::RERANKER_STATE);
    }
}
