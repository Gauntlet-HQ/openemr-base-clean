<?php

/**
 * DB-backed U12 acceptance eval: the four case-study questions answered from the trace table alone.
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
use OpenEMR\Modules\ClinicalCopilot\Observability\Metrics\MetricsService;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §3: "the four case-study questions -- what did the agent
 * do and in what order, how long did each step take, did tools fail and why,
 * what did it cost -- answerable from stored data at any time, for any
 * request, by correlation ID alone." ARCHITECTURE_COMPLETE.md's U12
 * acceptance criterion asks for exactly this spot-check: seed one
 * realistic chat-turn scenario (a tool call that fails, a retried LLM call, a
 * final verify) and answer all four questions using ONLY
 * {@see MetricsService::spanWaterfall()} -- no other data source.
 */
final class CaseStudyQuestionsFromTraceTest extends TestCase
{
    private const SYNTHETIC_PID = 999501;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    public function testFourCaseStudyQuestionsAnswerableFromTheSpanWaterfallAlone(): void
    {
        $correlationId = 'ccp-case-study-' . bin2hex(random_bytes(8));
        $tracer = new TraceRecorder();
        $t0 = new \DateTimeImmutable('2026-01-15 09:00:00.000000');

        // 1. extract (a capability's fresh read, I2).
        $tracer->record($this->span($correlationId, 'extract', $t0, 300, 'ok'));
        // 2. digest (content-addressing).
        $tracer->record($this->span($correlationId, 'digest', $t0->modify('+300 ms'), 10, 'ok'));
        // 3. a tool call that FAILS, named and why (never silently absorbed).
        $tracer->record($this->span(
            $correlationId,
            'tool_call',
            $t0->modify('+310 ms'),
            1500,
            'error',
            errorClass: 'VitalsLookupFailed',
            errorDetail: 'vitals lookup failed -- answering from labs and meds only',
        ));
        // 4. an LLM call that gets RETRIED once (cost/tokens attributed).
        $tracer->record($this->span(
            $correlationId,
            'llm_reduce',
            $t0->modify('+1810 ms'),
            4200,
            'retried',
            model: 'gemini-2.5-pro',
            tokensIn: 1200,
            tokensOut: 300,
            costUsd: 0.0083,
        ));
        // 5. verify (passed on the retried attempt).
        $tracer->record($this->span($correlationId, 'verify', $t0->modify('+6010 ms'), 50, 'ok'));
        // 6. render (final).
        $tracer->record($this->span($correlationId, 'render', $t0->modify('+6060 ms'), 5, 'ok'));

        $waterfall = (new MetricsService())->spanWaterfall($correlationId);

        // Q1: what did the agent do, and in what order?
        self::assertSame(
            ['extract', 'digest', 'tool_call', 'llm_reduce', 'verify', 'render'],
            array_column($waterfall, 'kind'),
            'span order (started_at ASC) must reconstruct exactly what happened, in order',
        );

        // Q2: how long did each step take, and overall?
        $totalMs = array_sum(array_column($waterfall, 'duration_ms'));
        self::assertSame(300 + 10 + 1500 + 4200 + 50 + 5, $totalMs);
        $llmSpan = self::byKind($waterfall, 'llm_reduce');
        self::assertSame(4200, $llmSpan['duration_ms']);

        // Q3: did any tool fail, and why?
        $toolSpan = self::byKind($waterfall, 'tool_call');
        self::assertSame('error', $toolSpan['status']);
        self::assertSame('VitalsLookupFailed', $toolSpan['error_class']);
        self::assertStringContainsString('vitals lookup failed', $toolSpan['error_detail']);

        // Q4: what did it cost?
        self::assertSame(1200, $llmSpan['tokens_in']);
        self::assertSame(300, $llmSpan['tokens_out']);
        self::assertEqualsWithDelta(0.0083, $llmSpan['cost_usd'], 0.00001);

        // Bonus, since the fixture is already retried: the llm_reduce span's
        // own `status` records the retry -- "an LLM call needed a retry" is
        // ALSO answerable from the trace table alone, without a live model.
        self::assertSame('retried', $llmSpan['status']);
    }

    /**
     * @param list<array<string, mixed>> $waterfall
     * @return array<string, mixed>
     */
    private static function byKind(array $waterfall, string $kind): array
    {
        foreach ($waterfall as $span) {
            if ($span['kind'] === $kind) {
                return $span;
            }
        }

        throw new \RuntimeException("no span of kind {$kind} in waterfall");
    }

    private function span(
        string $correlationId,
        string $kind,
        \DateTimeImmutable $startedAt,
        int $durationMs,
        string $status,
        ?string $errorClass = null,
        ?string $errorDetail = null,
        ?string $model = null,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?float $costUsd = null,
    ): TraceSpan {
        return new TraceSpan(
            $correlationId,
            TraceSpan::newSpanId(),
            null,
            $kind,
            $startedAt,
            $durationMs,
            $status,
            self::SYNTHETIC_PID,
            null,
            $errorClass,
            $errorDetail,
            $model,
            $tokensIn,
            $tokensOut,
            $costUsd,
        );
    }
}
