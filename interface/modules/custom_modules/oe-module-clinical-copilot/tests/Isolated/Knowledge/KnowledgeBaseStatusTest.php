<?php

/**
 * KnowledgeBaseStatus — the readiness snapshot distinguishes its failure modes.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeBaseConfig;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeQueryRunner;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeBaseStatus;
use PHPUnit\Framework\TestCase;

/**
 * The snapshot must tell an operator WHICH thing is wrong. Collapsing "no env
 * set" and "env set but the pdo_pgsql driver isn't loaded in this SAPI" into
 * one "set DATABASE_URL" message sent debugging down the wrong path in
 * production — the env was fine; the web server's PHP just lacked the driver.
 */
final class KnowledgeBaseStatusTest extends TestCase
{
    private function configured(): KnowledgeBaseConfig
    {
        return new KnowledgeBaseConfig('db.internal', 5432, 'knowledge', 'reader', 'pw', 'prefer', 'guideline_chunks');
    }

    public function testBlankConfigReportsNotConfigured(): void
    {
        $config = new KnowledgeBaseConfig('', 5432, '', '', '', 'prefer', 'guideline_chunks');
        $status = new KnowledgeBaseStatus($config, new StubKnowledgeRunner(available: false));

        self::assertSame('not_configured', $status->snapshot()['state']);
    }

    public function testConfiguredButUnavailableRunnerReportsDriverMissing(): void
    {
        // Env is set (host+db present) but the runner is unavailable — the only
        // remaining factor is the missing pdo_pgsql driver in this SAPI.
        $status = new KnowledgeBaseStatus($this->configured(), new StubKnowledgeRunner(available: false));

        $snapshot = $status->snapshot();
        self::assertSame('driver_missing', $snapshot['state']);
        self::assertTrue($snapshot['configured']);
    }

    public function testConfiguredAndAvailableWithRowsReportsOk(): void
    {
        $status = new KnowledgeBaseStatus(
            $this->configured(),
            new StubKnowledgeRunner(available: true, rows: [['n' => 42]]),
        );

        $snapshot = $status->snapshot();
        self::assertSame('ok', $snapshot['state']);
        self::assertSame(42, $snapshot['chunk_count']);
    }

    public function testConfiguredAndAvailableButProbeReturnsNothingReportsUnreachable(): void
    {
        $status = new KnowledgeBaseStatus(
            $this->configured(),
            new StubKnowledgeRunner(available: true, rows: []),
        );

        self::assertSame('unreachable', $status->snapshot()['state']);
    }
}

/**
 * @internal test double — no DB, no PDO.
 */
final class StubKnowledgeRunner implements KnowledgeQueryRunner
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private readonly bool $available,
        private readonly array $rows = [],
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * @param array<string, scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->rows;
    }
}
