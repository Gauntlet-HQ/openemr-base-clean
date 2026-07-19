<?php

/**
 * DB-backed: TelemetryRetention prunes only telemetry older than the horizon.
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
use OpenEMR\Modules\ClinicalCopilot\Observability\TelemetryRetention;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: the retention cron either not deleting stale telemetry
 * (unbounded growth) or deleting rows still inside the window (losing the
 * dashboard's own recent data). Each row is tagged with a unique correlation
 * id so the assertions are exact on a shared dev database with ambient rows,
 * and the whole thing runs in a rolled-back transaction so nothing persists.
 */
final class TelemetryRetentionDbTest extends TestCase
{
    private const PID = 999401;

    private string $tag;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->tag = 'ccp-retention-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    public function testPrunesRowsOlderThanTheHorizonAndKeepsRecentOnes(): void
    {
        $now = new \DateTimeImmutable('2026-07-14 12:00:00');
        $old = $now->sub(new \DateInterval('P10D'))->format('Y-m-d H:i:s.u'); // well outside 3d
        $fresh = $now->sub(new \DateInterval('PT1H'))->format('Y-m-d H:i:s.u'); // inside 3d

        // One stale + one fresh row in every telemetry table.
        $this->insertTrace($old);
        $this->insertTrace($fresh);
        $this->insertTracePayload($old);
        $this->insertTracePayload($fresh);
        $this->insertUiEvent($old);
        $this->insertUiEvent($fresh);
        $this->insertQa($old);
        $this->insertQa($fresh);

        // Sanity: 2 of each of mine present before the prune.
        self::assertSame(2, $this->countMine('mod_copilot_trace'));
        self::assertSame(2, $this->countMine('mod_copilot_trace_payload'));
        self::assertSame(2, $this->countMine('mod_copilot_ui_event'));
        self::assertSame(2, $this->countMine('mod_copilot_qa'));

        $deleted = (new TelemetryRetention())->prune($now, 3);

        // Every table reports at least my one stale row deleted (>= 1 because a
        // shared dev DB may also have other ambient stale rows).
        self::assertGreaterThanOrEqual(1, $deleted['mod_copilot_trace']);
        self::assertGreaterThanOrEqual(1, $deleted['mod_copilot_trace_payload']);
        self::assertGreaterThanOrEqual(1, $deleted['mod_copilot_ui_event']);
        self::assertGreaterThanOrEqual(1, $deleted['mod_copilot_qa']);

        // Exactly the fresh row of mine survives in each.
        self::assertSame(1, $this->countMine('mod_copilot_trace'), 'stale trace pruned, fresh kept');
        self::assertSame(1, $this->countMine('mod_copilot_trace_payload'), 'stale payload pruned, fresh kept');
        self::assertSame(1, $this->countMine('mod_copilot_ui_event'), 'stale ui_event pruned, fresh kept');
        self::assertSame(1, $this->countMine('mod_copilot_qa'), 'stale qa pruned, fresh kept');
    }

    public function testDoesNotTouchTelemetryInsideTheWindow(): void
    {
        $now = new \DateTimeImmutable('2026-07-14 12:00:00');
        // 2 days old, horizon 3 days -> must be kept.
        $inside = $now->sub(new \DateInterval('P2D'))->format('Y-m-d H:i:s.u');

        $this->insertTrace($inside);
        $this->insertUiEvent($inside);

        (new TelemetryRetention())->prune($now, 3);

        self::assertSame(1, $this->countMine('mod_copilot_trace'));
        self::assertSame(1, $this->countMine('mod_copilot_ui_event'));
    }

    private function countMine(string $table): int
    {
        return (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `{$table}` WHERE `correlation_id` = ?",
            'c',
            [$this->tag],
        );
    }

    private function insertTrace(string $startedAt): void
    {
        QueryUtils::sqlInsert(
            'INSERT INTO `mod_copilot_trace`
                (`correlation_id`, `span_id`, `kind`, `started_at`, `duration_ms`, `status`, `pid`)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->tag, bin2hex(random_bytes(8)), 'render', $startedAt, 12, 'ok', self::PID],
        );
    }

    private function insertTracePayload(string $createdAt): void
    {
        QueryUtils::sqlInsert(
            'INSERT INTO `mod_copilot_trace_payload`
                (`payload_ref`, `correlation_id`, `kind`, `payload_json`, `created_at`)
             VALUES (?, ?, ?, ?, ?)',
            [bin2hex(random_bytes(16)), $this->tag, 'prompt', '{}', $createdAt],
        );
    }

    private function insertUiEvent(string $createdAt): void
    {
        QueryUtils::sqlInsert(
            'INSERT INTO `mod_copilot_ui_event`
                (`correlation_id`, `pid`, `user_id`, `event_type`, `created_at`)
             VALUES (?, ?, ?, ?, ?)',
            [$this->tag, self::PID, 1, 'facts_panel_open', $createdAt],
        );
    }

    private function insertQa(string $createdAt): void
    {
        QueryUtils::sqlInsert(
            'INSERT INTO `mod_copilot_qa`
                (`target_type`, `target_id`, `correlation_id`, `pid`, `status`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?)',
            ['doc', random_int(1, 2_000_000_000), $this->tag, self::PID, 'unavailable', $createdAt],
        );
    }
}
