<?php

/**
 * Evaluates the 7 ARCHITECTURE.md §3.5 alerts plus the I14 unaccounted-entity alert, on every worker tick.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Alert;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Observability\Metrics\RateMath;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceConfigStore;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;
use Ramsey\Uuid\Uuid;

/**
 * ARCHITECTURE.md §3.5: "Alert evaluation runs on the module's background-
 * service tick; firing writes an alert_eval span, surfaces a dashboard
 * banner, and logs at error severity." {@see self::run()} is the ONE method
 * U9's worker calls; it ALWAYS returns one {@see AlertFinding} per
 * {@see AlertName} case (fired or not) so the dashboard can render a full
 * status board, not just a list of incidents.
 *
 * Every check reads ONLY `mod_copilot_trace` (plus versioned config in
 * `mod_copilot_cadence`) -- never a live call to any external system --
 * because alert evaluation must be exactly as cheap and dependency-free as
 * the rest of the tick (§3.7: "a chart-churn storm degrades warm coverage,
 * never blows the cap").
 *
 * Known, accepted simplification (documented, not hidden): `tool_call` spans
 * (ARCHITECTURE_COMPLETE.md's `mod_copilot_trace` schema) do not carry the
 * tool's own name -- only `kind='tool_call'` plus `status`/`error_detail`.
 * {@see AlertName::ToolFailureRate} is therefore an AGGREGATE failure rate
 * across all tools, not truly per-tool as ARCHITECTURE.md's table describes;
 * a genuine per-tool breakdown needs the tool name captured onto the span
 * (or its `payload_ref`), which is out of this build unit's owned files (the
 * call sites live in U11's `ChatController`) -- see the U12 report.
 */
final class AlertEvaluator
{
    public function __construct(
        private readonly TraceRecorderInterface $tracer,
        private readonly AlertNotifierInterface $notifier,
        private readonly CadenceConfigStore $cadenceConfig = new CadenceConfigStore(),
        private readonly SystemLogger $logger = new SystemLogger(),
    ) {
    }

    /**
     * @return list<AlertFinding>
     */
    public function run(): array
    {
        $thresholds = $this->loadThresholds();
        $now = new \DateTimeImmutable();

        $findings = [
            $this->checkWrongPatientTrip($thresholds, $now),
            $this->checkP95Latency($thresholds, $now),
            $this->checkErrorRate($thresholds, $now),
            $this->checkToolFailureRate($thresholds, $now),
            $this->checkVerificationFailureRate($thresholds, $now),
            $this->checkLlmSpend($now),
            $this->checkWorkerHeartbeatStale($thresholds, $now),
            $this->checkUnaccountedEntity($thresholds, $now),
        ];

        foreach ($findings as $finding) {
            $this->recordAndNotify($finding, $now);
        }

        return $findings;
    }

    private function checkWrongPatientTrip(array $thresholds, \DateTimeImmutable $now): AlertFinding
    {
        $windowStart = $now->modify("-{$thresholds['eval_window_minutes']} minutes")->format('Y-m-d H:i:s.u');
        $count = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `kind` = 'chat_turn' AND `status` = 'error' AND `started_at` > ?",
            'c',
            [$windowStart],
        );

        return new AlertFinding(
            AlertName::WrongPatientTrip,
            $count > 0,
            $count > 0
                ? "{$count} chat session(s) frozen on a V3 patient-identity sev-1 trip in the last {$thresholds['eval_window_minutes']} minutes"
                : 'no wrong-patient trips in the evaluation window',
            (float)$count,
            0.0,
        );
    }

    private function checkP95Latency(array $thresholds, \DateTimeImmutable $now): AlertFinding
    {
        $windowStart = $now->modify("-{$thresholds['eval_window_minutes']} minutes")->format('Y-m-d H:i:s.u');
        $durations = self::intColumn(
            "SELECT `duration_ms` AS v FROM `mod_copilot_trace` WHERE `kind` = 'chat_turn' AND `duration_ms` IS NOT NULL AND `started_at` > ?",
            [$windowStart],
        );
        $p95 = RateMath::percentile($durations, 95.0);
        $latencyFired = $durations !== [] && $p95 > $thresholds['p95_latency_ms'];

        $warmMissFired = false;
        $warmMissRate = 0.0;
        if ((int)$now->format('G') === 8) {
            $renderStatuses = self::stringColumn(
                "SELECT `status` AS v FROM `mod_copilot_trace` WHERE `kind` = 'render' AND `started_at` > ?",
                [$windowStart],
            );
            $degraded = count(array_filter($renderStatuses, static fn (string $s): bool => $s === 'degraded'));
            $warmMissRate = RateMath::percentage($degraded, count($renderStatuses));
            $warmMissFired = $renderStatuses !== [] && $warmMissRate > $thresholds['warm_miss_rate_pct'];
        }

        if ($warmMissFired) {
            return new AlertFinding(
                AlertName::P95Latency,
                true,
                sprintf('synthesis warm-miss rate %.1f%% exceeds %.1f%% during the 8:00-9:00 window', $warmMissRate, $thresholds['warm_miss_rate_pct']),
                $warmMissRate,
                $thresholds['warm_miss_rate_pct'],
            );
        }

        return new AlertFinding(
            AlertName::P95Latency,
            $latencyFired,
            $latencyFired
                ? sprintf('chat turn p95 latency %.0fms exceeds %.0fms over the last %d minutes', $p95, $thresholds['p95_latency_ms'], $thresholds['eval_window_minutes'])
                : sprintf('chat turn p95 latency %.0fms is within threshold', $p95),
            $p95,
            (float)$thresholds['p95_latency_ms'],
        );
    }

    private function checkErrorRate(array $thresholds, \DateTimeImmutable $now): AlertFinding
    {
        $windowStart = $now->modify("-{$thresholds['eval_window_minutes']} minutes")->format('Y-m-d H:i:s.u');
        $total = (int)QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `started_at` > ?',
            'c',
            [$windowStart],
        );
        $errors = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `status` = 'error' AND `started_at` > ?",
            'c',
            [$windowStart],
        );
        $rate = RateMath::percentage($errors, $total);
        $fired = $total > 0 && $rate > $thresholds['error_rate_pct'];

        return new AlertFinding(
            AlertName::ErrorRate,
            $fired,
            $fired
                ? sprintf('error rate %.1f%% exceeds %.1f%% over the last %d minutes', $rate, $thresholds['error_rate_pct'], $thresholds['eval_window_minutes'])
                : sprintf('error rate %.1f%% is within threshold', $rate),
            $rate,
            (float)$thresholds['error_rate_pct'],
        );
    }

    private function checkToolFailureRate(array $thresholds, \DateTimeImmutable $now): AlertFinding
    {
        $windowStart = $now->modify('-1 hour')->format('Y-m-d H:i:s.u');
        $total = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `kind` = 'tool_call' AND `started_at` > ?",
            'c',
            [$windowStart],
        );
        $failed = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `kind` = 'tool_call' AND `status` = 'error' AND `started_at` > ?",
            'c',
            [$windowStart],
        );
        $rate = RateMath::percentage($failed, $total);
        $fired = $total > 0 && $rate > $thresholds['tool_failure_rate_pct'];

        return new AlertFinding(
            AlertName::ToolFailureRate,
            $fired,
            $fired
                ? sprintf('tool call failure rate %.1f%% exceeds %.1f%% over the last hour (aggregate across tools)', $rate, $thresholds['tool_failure_rate_pct'])
                : sprintf('tool call failure rate %.1f%% is within threshold', $rate),
            $rate,
            (float)$thresholds['tool_failure_rate_pct'],
        );
    }

    private function checkVerificationFailureRate(array $thresholds, \DateTimeImmutable $now): AlertFinding
    {
        $windowStart = $now->modify('-1 hour')->format('Y-m-d H:i:s.u');
        $total = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `kind` = 'verify' AND `started_at` > ?",
            'c',
            [$windowStart],
        );
        $failed = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `kind` = 'verify' AND `status` != 'ok' AND `started_at` > ?",
            'c',
            [$windowStart],
        );
        $rate = RateMath::percentage($failed, $total);
        $fired = $total > 0 && $rate > $thresholds['verification_failure_rate_pct'];

        return new AlertFinding(
            AlertName::VerificationFailureRate,
            $fired,
            $fired
                ? sprintf('verification failure rate %.1f%% exceeds %.1f%% over the last hour', $rate, $thresholds['verification_failure_rate_pct'])
                : sprintf('verification failure rate %.1f%% is within threshold', $rate),
            $rate,
            (float)$thresholds['verification_failure_rate_pct'],
        );
    }

    private function checkLlmSpend(\DateTimeImmutable $now): AlertFinding
    {
        $limits = $this->cadenceConfig->limits();

        $hourAgo = $now->modify('-1 hour')->format('Y-m-d H:i:s.u');
        $hourlySpend = (float)(QueryUtils::fetchSingleValue(
            'SELECT COALESCE(SUM(`cost_usd`), 0) AS s FROM `mod_copilot_trace` WHERE `started_at` > ?',
            's',
            [$hourAgo],
        ) ?? 0.0);

        $sevenDaysAgo = $now->modify('-7 days')->format('Y-m-d H:i:s.u');
        $trailingSpend = (float)(QueryUtils::fetchSingleValue(
            'SELECT COALESCE(SUM(`cost_usd`), 0) AS s FROM `mod_copilot_trace` WHERE `started_at` > ?',
            's',
            [$sevenDaysAgo],
        ) ?? 0.0);
        $trailingAvgHourly = $trailingSpend / (7 * 24);
        $burnThreshold = $trailingAvgHourly * 2.0;

        $todayStart = $now->setTime(0, 0)->format('Y-m-d H:i:s.u');
        $dailySpend = (float)(QueryUtils::fetchSingleValue(
            'SELECT COALESCE(SUM(`cost_usd`), 0) AS s FROM `mod_copilot_trace` WHERE `started_at` > ?',
            's',
            [$todayStart],
        ) ?? 0.0);

        $burnFired = $burnThreshold > 0.0 && $hourlySpend > $burnThreshold;
        $dailyCapFired = $dailySpend > $limits['daily_llm_spend_cap_usd'];

        if ($dailyCapFired) {
            return new AlertFinding(
                AlertName::LlmSpend,
                true,
                sprintf('daily LLM spend $%.2f exceeds site cap $%.2f', $dailySpend, $limits['daily_llm_spend_cap_usd']),
                $dailySpend,
                $limits['daily_llm_spend_cap_usd'],
            );
        }

        return new AlertFinding(
            AlertName::LlmSpend,
            $burnFired,
            $burnFired
                ? sprintf('hourly LLM burn $%.2f exceeds 2x trailing-7-day average ($%.2f)', $hourlySpend, $burnThreshold)
                : sprintf('hourly LLM burn $%.2f is within trend', $hourlySpend),
            $hourlySpend,
            $burnThreshold,
        );
    }

    private function checkWorkerHeartbeatStale(array $thresholds, \DateTimeImmutable $now): AlertFinding
    {
        $raw = QueryUtils::fetchSingleValue(
            "SELECT `config_json` FROM `mod_copilot_cadence` WHERE `code_set` = 'worker_heartbeat'",
            'config_json',
        );
        $config = is_string($raw) ? json_decode($raw, true) : null;
        $lastTickAt = is_array($config) && is_string($config['last_tick_at'] ?? null) ? $config['last_tick_at'] : null;

        $intervalMinutes = (int)(QueryUtils::fetchSingleValue(
            "SELECT `execute_interval` FROM `background_services` WHERE `name` = 'clinical_copilot_worker'",
            'execute_interval',
        ) ?? 5);
        $staleWindowMinutes = $intervalMinutes * $thresholds['heartbeat_stale_multiplier'];

        if ($lastTickAt === null) {
            return new AlertFinding(AlertName::WorkerHeartbeatStale, true, 'no worker heartbeat has ever been recorded', 999999.0, $staleWindowMinutes);
        }

        try {
            $lastTick = new \DateTimeImmutable($lastTickAt);
        } catch (\Throwable) {
            return new AlertFinding(AlertName::WorkerHeartbeatStale, true, 'worker heartbeat timestamp is unparseable', 999999.0, $staleWindowMinutes);
        }

        $minutesSince = ($now->getTimestamp() - $lastTick->getTimestamp()) / 60.0;
        $fired = $minutesSince > $staleWindowMinutes;

        return new AlertFinding(
            AlertName::WorkerHeartbeatStale,
            $fired,
            $fired
                ? sprintf('no worker heartbeat for %.1f minutes (stale window %.1f minutes)', $minutesSince, $staleWindowMinutes)
                : sprintf('worker heartbeat is fresh (%.1f minutes ago)', $minutesSince),
            $minutesSince,
            $staleWindowMinutes,
        );
    }

    private function checkUnaccountedEntity(array $thresholds, \DateTimeImmutable $now): AlertFinding
    {
        $windowStart = $now->modify("-{$thresholds['eval_window_minutes']} minutes")->format('Y-m-d H:i:s.u');
        $count = (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_trace` WHERE `kind` = 'extract' AND `error_class` = 'UnaccountedRows' AND `started_at` > ?",
            'c',
            [$windowStart],
        );

        return new AlertFinding(
            AlertName::UnaccountedEntity,
            $count > 0,
            $count > 0
                ? "{$count} extraction span(s) reported unaccounted > 0 in the last {$thresholds['eval_window_minutes']} minutes (I14) -- pull the span payload before fixing the mapping"
                : 'no unaccounted-row extraction spans in the evaluation window',
            (float)$count,
            0.0,
        );
    }

    private function recordAndNotify(AlertFinding $finding, \DateTimeImmutable $now): void
    {
        $this->tracer->record(new TraceSpan(
            Uuid::uuid7()->toString(),
            TraceSpan::newSpanId(),
            null,
            'alert_eval',
            $now,
            0,
            $finding->fired ? 'error' : 'ok',
            0,
            null,
            $finding->fired ? $finding->name->value : null,
            $finding->fired ? $finding->message : null,
        ));

        if (!$finding->fired) {
            return;
        }

        $this->logger->error('ClinicalCopilot: alert fired', [
            'alert' => $finding->name->value,
            'message' => $finding->message,
            'metric_value' => $finding->metricValue,
            'threshold' => $finding->threshold,
        ]);

        $this->notifier->notify($finding);
    }

    /**
     * @return array{
     *     eval_window_minutes: int, p95_latency_ms: float, warm_miss_rate_pct: float,
     *     error_rate_pct: float, tool_failure_rate_pct: float,
     *     verification_failure_rate_pct: float, heartbeat_stale_multiplier: float
     * }
     */
    private function loadThresholds(): array
    {
        $raw = QueryUtils::fetchSingleValue(
            "SELECT `config_json` FROM `mod_copilot_cadence` WHERE `code_set` = 'alert_thresholds'",
            'config_json',
        );
        $config = is_string($raw) ? json_decode($raw, true) : null;
        $config = is_array($config) ? $config : [];

        return [
            'eval_window_minutes' => (int)($config['eval_window_minutes'] ?? 15),
            'p95_latency_ms' => (float)($config['p95_latency_ms'] ?? 15000),
            'warm_miss_rate_pct' => (float)($config['warm_miss_rate_pct'] ?? 20.0),
            'error_rate_pct' => (float)($config['error_rate_pct'] ?? 5.0),
            'tool_failure_rate_pct' => (float)($config['tool_failure_rate_pct'] ?? 2.0),
            'verification_failure_rate_pct' => (float)($config['verification_failure_rate_pct'] ?? 10.0),
            'heartbeat_stale_multiplier' => (float)($config['heartbeat_stale_multiplier'] ?? 2.0),
        ];
    }

    /**
     * @param list<mixed> $binds
     * @return list<int>
     */
    private static function intColumn(string $sql, array $binds): array
    {
        $rows = QueryUtils::fetchRecords($sql, $binds);

        return array_map(static fn (array $row): int => (int)$row['v'], $rows);
    }

    /**
     * @param list<mixed> $binds
     * @return list<string>
     */
    private static function stringColumn(string $sql, array $binds): array
    {
        $rows = QueryUtils::fetchRecords($sql, $binds);

        return array_map(static fn (array $row): string => (string)$row['v'], $rows);
    }
}
