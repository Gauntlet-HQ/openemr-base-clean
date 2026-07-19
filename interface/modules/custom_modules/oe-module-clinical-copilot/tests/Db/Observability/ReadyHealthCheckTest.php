<?php

/**
 * DB-backed U12 acceptance evals: /health checks nothing and stays green; /ready fails honestly when the LLM stub is down.
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
use OpenEMR\Modules\ClinicalCopilot\Observability\HealthCheck;
use OpenEMR\Modules\ClinicalCopilot\Observability\ReadyCheck;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE_COMPLETE.md's U12 acceptance criterion: "/ready fails when LLM
 * stub down while /health stays green." This dev/test environment has no
 * `CLINICAL_COPILOT_GCP_PROJECT_ID` configured (docs/build-notes.md's
 * documented default), so {@see ReadyCheck}'s own LLM probe is ALWAYS the
 * "down" state here -- exactly the scenario this eval needs, with no test
 * double required.
 */
final class ReadyHealthCheckTest extends TestCase
{
    // Deliberately no setUp()/tearDown() transaction wrapping here (unlike
    // this suite's other Db evals): {@see ReadyCheck}'s own
    // `tables_writable` probe manages its OWN BeginTrans/RollbackTrans pair
    // against the live connection (that IS the probe, ARCHITECTURE.md §3.4),
    // and ADOdb's raw BeginTrans/RollbackTrans do not nest -- wrapping these
    // tests in an outer transaction would have the probe's own rollback
    // interact with an already-open one instead of the isolated no-op it is
    // designed to be. None of these tests insert any fixture data that would
    // otherwise need cleanup.

    public function testHealthChecksNothingAndIsAlwaysOk(): void
    {
        $result = (new HealthCheck())->check();

        self::assertSame('ok', $result['status']);
        self::assertSame('oe-module-clinical-copilot', $result['module']);
        self::assertNotSame('', $result['version']);
    }

    public function testReadyReportsDbAndTablesWritableOkAgainstALiveDatabase(): void
    {
        $result = (new ReadyCheck())->check();

        self::assertSame('ok', $result['db']);
        self::assertSame('ok', $result['tables_writable']);
    }

    public function testReadyReportsLlmUnreachableWithNoAdcConfiguredAndDegradesTheOverallStatusWithoutFailingHardDependencies(): void
    {
        // Guard the test's own assumption: this eval only proves what it
        // claims to prove if NO provider is actually configured in this
        // environment (the honest dev/test default, build-notes.md) — neither a
        // Vertex project nor a Gemini API key, since the probe now covers both.
        self::assertSame('', trim((string)getenv('CLINICAL_COPILOT_GCP_PROJECT_ID')), 'this eval assumes no Vertex project is configured in this environment');
        self::assertSame('', trim((string)getenv('CLINICAL_COPILOT_GEMINI_API_KEY')), 'this eval assumes no Gemini API key is configured in this environment');

        $result = (new ReadyCheck())->check();

        self::assertSame('unreachable', $result['llm']);
        // Degraded-but-serving (I6): the LLM being down must never turn a
        // perfectly healthy DB/tables state into a hard failure.
        self::assertNotSame('error', $result['status']);
    }

    public function testTablesWritableProbeLeavesNoRowBehind(): void
    {
        $before = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `correlation_id` = 'ready-probe'",
            'c',
        );

        (new ReadyCheck())->check();

        $after = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `correlation_id` = 'ready-probe'",
            'c',
        );

        self::assertSame($before, $after, 'the INSERT+ROLLBACK probe must never leave a row behind');
    }

    public function testReadyResponseNeverIncludesLatenciesConfigOrPhi(): void
    {
        $result = (new ReadyCheck())->check();

        // ARCHITECTURE.md §3.4: "status enums only ... no latencies, no
        // config values, no PHI." Asserted structurally: the response is
        // exactly a boolean `ready` plus these string-valued status keys,
        // nothing else (no latencies/config/PHI).
        self::assertEqualsCanonicalizing(
            ['ready', 'status', 'db', 'tables_writable', 'llm', 'worker_heartbeat', 'breaker', 'document_store', 'knowledge', 'reranker'],
            array_keys($result),
        );
        self::assertIsBool($result['ready']);
        foreach ($result as $key => $value) {
            if ($key === 'ready') {
                continue;
            }
            self::assertIsString($value);
        }
    }
}
