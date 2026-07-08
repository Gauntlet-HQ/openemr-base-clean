<?php

/**
 * DB-backed U12 acceptance evals: TraceRecorder writes every span, append-only, closed-set validation.
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
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;
use PHPUnit\Framework\TestCase;

/**
 * I12: "every invocation leaves a trace ... cache hits, degraded reads, and
 * failures included." These evals prove {@see TraceRecorder} is a faithful,
 * unconditional `TraceRecorderInterface` implementation: every call to
 * {@see TraceRecorder::record()} lands exactly one row, regardless of
 * status; the table is never mutated afterward (append-only, I3/E7 in
 * spirit -- TraceRecorder has no update/delete method at all).
 */
final class TraceRecorderTest extends TestCase
{
    private const SYNTHETIC_PID = 999101;

    private TraceRecorder $recorder;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->recorder = new TraceRecorder();
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    private function span(string $correlationId, string $kind, string $status): TraceSpan
    {
        return new TraceSpan(
            $correlationId,
            TraceSpan::newSpanId(),
            null,
            $kind,
            new \DateTimeImmutable(),
            123,
            $status,
            self::SYNTHETIC_PID,
        );
    }

    public function testEveryRecordCallInsertsExactlyOneRowRegardlessOfStatus(): void
    {
        $correlationId = 'ccp-test-' . bin2hex(random_bytes(8));

        $this->recorder->record($this->span($correlationId, 'extract', 'ok'));
        $this->recorder->record($this->span($correlationId, 'cache_lookup', 'ok'));
        $this->recorder->record($this->span($correlationId, 'llm_reduce', 'degraded'));
        $this->recorder->record($this->span($correlationId, 'verify', 'degraded'));
        $this->recorder->record($this->span($correlationId, 'tool_call', 'error'));

        $count = (int)QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `correlation_id` = ?',
            'c',
            [$correlationId],
        );

        self::assertSame(5, $count, 'degraded and error spans must be recorded exactly like ok spans -- I12');
    }

    public function testRecordedSpanRoundTripsAllFields(): void
    {
        $correlationId = 'ccp-test-' . bin2hex(random_bytes(8));
        $span = new TraceSpan(
            $correlationId,
            TraceSpan::newSpanId(),
            'parent-span-1',
            'llm_reduce',
            new \DateTimeImmutable('2026-01-01 12:00:00.500000'),
            4200,
            'retried',
            self::SYNTHETIC_PID,
            77,
            'SomeErrorClass',
            'some detail',
            'gemini-2.5-pro',
            100,
            200,
            0.0042,
            'payload-ref-1',
        );

        $this->recorder->record($span);

        $row = QueryUtils::querySingleRow('SELECT * FROM `mod_copilot_trace` WHERE `correlation_id` = ?', [$correlationId]);

        self::assertIsArray($row);
        self::assertSame('parent-span-1', $row['parent_span_id']);
        self::assertSame('llm_reduce', $row['kind']);
        self::assertSame(4200, (int)$row['duration_ms']);
        self::assertSame('retried', $row['status']);
        self::assertSame(self::SYNTHETIC_PID, (int)$row['pid']);
        self::assertSame(77, (int)$row['user_id']);
        self::assertSame('SomeErrorClass', $row['error_class']);
        self::assertSame('some detail', $row['error_detail']);
        self::assertSame('gemini-2.5-pro', $row['model']);
        self::assertSame(100, (int)$row['tokens_in']);
        self::assertSame(200, (int)$row['tokens_out']);
        self::assertEqualsWithDelta(0.0042, (float)$row['cost_usd'], 0.00001);
        self::assertSame('payload-ref-1', $row['payload_ref']);
    }

    public function testUnrecognizedKindIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->recorder->record($this->span('ccp-test-bad-kind', 'not_a_real_kind', 'ok'));
    }

    public function testUnrecognizedStatusIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->recorder->record($this->span('ccp-test-bad-status', 'extract', 'not_a_real_status'));
    }

    public function testTraceRecorderHasNoUpdateOrDeleteMethod(): void
    {
        $reflection = new \ReflectionClass(TraceRecorder::class);
        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $m): string => strtolower($m->getName()),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach ($publicMethodNames as $name) {
            self::assertStringNotContainsString('update', $name);
            self::assertStringNotContainsString('delete', $name);
        }
    }
}
