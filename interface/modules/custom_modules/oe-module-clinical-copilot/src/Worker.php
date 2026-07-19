<?php

/**
 * The background warm/QA/rerun worker (ARCHITECTURE_COMPLETE.md "Compute model" WORKER block).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Chat\RateLimit\CircuitBreakerInterface;
use OpenEMR\Modules\ClinicalCopilot\Config\WorkerConfig;
use OpenEMR\Modules\ClinicalCopilot\Doc\QaStatus;
use OpenEMR\Modules\ClinicalCopilot\Doc\RegenReason;
use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaSweepSummary;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceCircuitBreaker;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceConfigStore;
use OpenEMR\Modules\ClinicalCopilot\Observability\WorkerTick;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath;
use OpenEMR\Modules\ClinicalCopilot\Worker\AppointmentWindowReader;
use OpenEMR\Modules\ClinicalCopilot\Worker\AppointmentWindowReaderInterface;
use OpenEMR\Modules\ClinicalCopilot\Worker\WorkerTickResult;
use Psr\Clock\ClockInterface;

/**
 * `src/worker_entry.php`'s `clinicalCopilotWorkerRun()` is the ONLY caller of
 * {@see self::runTick()} in production -- it is the `background_services`
 * `function` the host framework invokes on logged-in AJAX ticks / cron
 * (build-notes.md "Background services", ARCHITECTURE_COMPLETE.md "WORKER").
 *
 * **Ordering (I7 -- worker failure degrades latency only, never
 * correctness):**
 *
 *   1. {@see \OpenEMR\Modules\ClinicalCopilot\Observability\WorkerTick::recordHeartbeat()}
 *      FIRST, before anything that can throw -- {@see WorkerTick}'s own
 *      docblock: "the dead-man switch ... must land regardless of what else
 *      on the tick fails" ({@see \OpenEMR\Modules\ClinicalCopilot\Observability\ReadyCheck}
 *      and the dashboard read it).
 *   2. Warm the appointment window ({@see self::warm()}).
 *   3. QA sweep ({@see WorkerTick::runQaSweep()}, post-mortem, advisory).
 *   4. T22 QA-driven rerun ({@see self::runQaDrivenReruns()}), acting on the
 *      QA sweep's `QaStatus::Low` doc outcomes.
 *   5. Alert evaluation ({@see WorkerTick::runAlertEvaluation()}).
 *
 * Each stage is wrapped so a thrown exception in one never prevents the
 * later stages from running (the heartbeat, stage 1, has therefore already
 * landed by the time any later stage could fail) -- see {@see self::safely()}.
 * Lease-locking against concurrent ticks is the host `background_services`
 * framework's job (its `running`/`lock_expires_at` columns), not
 * reimplemented here.
 */
final class Worker
{
    /**
     * ARCHITECTURE_COMPLETE.md "WARM POLICY": "full-window passes at T-12h
     * and T-1h, then the 5-min tick." Rather than three separate code paths,
     * one 12-hour lookahead re-swept every 5 minutes gives the same
     * coverage -- see {@see AppointmentWindowReader::dueForWarm()}'s own
     * docblock for why that is equivalent, not a shortcut.
     */
    private const WARM_LOOKAHEAD_HOURS = 12;

    /**
     * T22: "now is before ~T-5min for that appt" is the cutoff after which a
     * QA-driven rerun is no longer attempted (too close to the visit to
     * safely land a new attempt before the physician opens the chart).
     */
    private const QA_RERUN_CUTOFF_MINUTES = 5;

    /**
     * T22: "Bounded: max 2 QA-driven reruns per (pid, digest)."
     */
    private const MAX_QA_RERUNS_PER_DIGEST = 2;

    /**
     * How many not-yet-QA'd targets {@see WorkerTick::runQaSweep()} may
     * review per tick -- matches that class's own docblock example
     * (`$tick->runQaSweep(20)`).
     */
    private const QA_SWEEP_LIMIT = 20;

    /**
     * `mod_copilot_cadence`'s `rate_limit_breaker` config carries
     * `per_tick_worker_llm_budget_usd` (ARCHITECTURE.md §3.7), but no call
     * site in this build yet populates a real `cost_usd` per generation
     * ({@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath}
     * always inserts `NewDoc` with `costUsd: null` -- a pre-existing gap in
     * U7/U8, not introduced here). Until real per-request cost is wired, a
     * literal dollar-budget enforcement is not honestly possible; this
     * constant is a documented, conservative placeholder so the configured
     * USD budget still translates into a concrete, testable cap on the
     * NUMBER of digest-miss generations (LLM calls) this worker will
     * attempt in one tick -- see {@see self::maxGenerationsPerTick()}. The
     * circuit breaker (real signal: error rate today, spend once wired)
     * remains the primary, always-correct enforcement mechanism.
     */
    private const ASSUMED_COST_PER_GENERATION_USD = 0.05;

    public function __construct(
        private readonly WorkerTick $tick,
        private readonly SynthesisReadPath $readPath,
        private readonly AppointmentWindowReaderInterface $appointments,
        private readonly CircuitBreakerInterface $breaker,
        private readonly CadenceConfigStore $cadenceConfig,
        private readonly ClockInterface $clock,
        private readonly SystemLogger $logger = new SystemLogger(),
    ) {
    }

    public static function createDefault(): self
    {
        return new self(
            WorkerTick::createDefault(),
            SynthesisReadPath::createDefault(),
            new AppointmentWindowReader(),
            new CadenceCircuitBreaker(),
            new CadenceConfigStore(),
            ServiceContainer::getClock(),
        );
    }

    public function runTick(): WorkerTickResult
    {
        $now = $this->clock->now();

        $heartbeatOk = $this->safely('heartbeat', function (): void {
            $this->tick->recordHeartbeat();
        });

        $warmedCount = 0;
        $warmSkipped = 0;
        $warmOk = $this->safely('warm', function () use ($now, &$warmedCount, &$warmSkipped): void {
            [$warmedCount, $warmSkipped] = $this->warm($now);
        });

        // The advisory second-pass QA reviewer is a second model call and is
        // no longer used: deterministic verification (V1-V6) is the only gate.
        // Gated off by default via WorkerConfig::qaReviewEnabled() (also still
        // requires the background-LLM master switch); the code stays in place so
        // it can be re-enabled with an env flag alone.
        $qaEnabled = WorkerConfig::backgroundLlmEnabled() && WorkerConfig::qaReviewEnabled();

        $qaSummary = null;
        $qaSweepOk = true;
        if ($qaEnabled) {
            $qaSweepOk = $this->safely('qa_sweep', function () use (&$qaSummary): void {
                $qaSummary = $this->tick->runQaSweep(self::QA_SWEEP_LIMIT);
            });
        }

        $qaReruns = 0;
        $qaRerunOk = true;
        if ($qaSummary !== null && $qaEnabled) {
            $qaRerunOk = $this->safely('qa_rerun', function () use ($qaSummary, $now, &$qaReruns): void {
                $qaReruns = $this->runQaDrivenReruns($qaSummary, $now);
            });
        }

        $alertFindings = [];
        $alertEvalOk = $this->safely('alert_eval', function () use (&$alertFindings): void {
            $alertFindings = $this->tick->runAlertEvaluation();
        });

        // Housekeeping (last, best-effort): prune observability telemetry older
        // than the retention horizon (default 3 days). Keeps the trace/ui-event/
        // qa tables bounded; a failure here degrades nothing on the serving
        // path, so it is not threaded into WorkerTickResult.
        $this->safely('telemetry_prune', function (): void {
            $this->tick->pruneTelemetry();
        });

        return new WorkerTickResult(
            $heartbeatOk,
            $warmOk,
            $warmedCount,
            $warmSkipped,
            $qaSweepOk,
            $qaSummary,
            $qaRerunOk,
            $qaReruns,
            $alertEvalOk,
            $alertFindings,
        );
    }

    /**
     * Sweeps the appointment window and warms each due patient's synthesis
     * via {@see SynthesisReadPath::read()} -- a digest hit serves free (no
     * LLM call); a miss runs the normal one-shot reduce+verify attempt.
     * Respects the circuit breaker AND a per-tick generation cap (§3.7):
     * once either trips, remaining due appointments are simply left
     * unwarmed for this tick -- coverage degrades, the cap is never blown
     * (I7), and an unwarmed patient still gets a correct, fresh read when
     * the physician opens the chart (read-time fallback).
     *
     * @return array{0: int, 1: int} [warmed count, skipped-for-budget-or-breaker count]
     */
    private function warm(\DateTimeImmutable $now): array
    {
        $until = $now->modify('+' . self::WARM_LOOKAHEAD_HOURS . ' hours');
        $due = $this->appointments->dueForWarm($now, $until);
        $allowLlmOnMiss = WorkerConfig::backgroundLlmEnabled();

        $maxGenerations = $allowLlmOnMiss ? $this->maxGenerationsPerTick() : 0;
        $warmed = 0;
        $skipped = 0;
        $generations = 0;

        foreach ($due as $appt) {
            if ($allowLlmOnMiss && ($this->breaker->isOpen() || $generations >= $maxGenerations)) {
                $skipped++;
                continue;
            }

            try {
                $result = $this->readPath->read($appt->pid, null, $allowLlmOnMiss);
                if ($result->capabilityCrash) {
                    continue;
                }

                if ($result->servedFromCache) {
                    $warmed++;
                    continue;
                }

                if ($allowLlmOnMiss && !$result->servedFromCache) {
                    $warmed++;
                    $generations++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('ClinicalCopilot: worker warm read failed', [
                    'pid' => $appt->pid,
                    'exception' => $e,
                ]);
            }
        }

        return [$warmed, $skipped];
    }

    /**
     * T22's QA-driven auto-rerun. Reads {@see QaSweepSummary::docOutcomes()}
     * (this tick's own QA sweep, run just before this method) and, for each
     * `QaStatus::Low` outcome, applies every T22 rule before enqueueing
     * exactly one bounded regeneration:
     *
     *   1. breaker/budget gate -- a QA-fail storm degrades coverage, never
     *      blows the cap (same rule as {@see self::warm()});
     *   2. the appointment-time cutoff -- skip once `$now` is at or past
     *      T-5min for that patient's next appointment (or once there is no
     *      appointment to bound the cutoff against at all);
     *   3. the bound -- at most {@see self::MAX_QA_RERUNS_PER_DIGEST}
     *      QA-driven reruns per `(pid, fact_digest)`, counted from
     *      `mod_copilot_doc.regen_reason = 'qa_low'` rows already at that
     *      digest;
     *   4. the freshness guard -- {@see SynthesisReadPath::currentDigest()}
     *      recomputes the CURRENT digest (fresh extraction, no LLM); if it
     *      no longer matches the low-scored doc's digest, the facts have
     *      drifted and this method skips the QA rerun entirely, letting the
     *      normal warm pass regenerate for the new digest instead (T22:
     *      "re-rolling a stale snapshot would yield a doc wrong on
     *      arrival").
     *
     * Exposed as `public` (rather than folded only into {@see self::runTick()})
     * so DB-backed tests can drive this single rule in isolation from warm
     * timing and appointment-window setup -- see the U9 test suite.
     *
     * @return int number of QA-driven regenerations actually enqueued
     */
    public function runQaDrivenReruns(QaSweepSummary $summary, \DateTimeImmutable $now): int
    {
        $enqueued = 0;

        foreach ($summary->docOutcomes() as $outcome) {
            if ($outcome->qaStatus !== QaStatus::Low || $outcome->factDigest === null) {
                continue;
            }

            if ($this->breaker->isOpen()) {
                // §3.7 / I7: stop enqueueing further QA-driven reruns this
                // tick once the breaker trips -- never blow the cap.
                break;
            }

            $apptAt = $this->appointments->nextApptAt($outcome->pid, $now);
            if ($apptAt === null) {
                // No appointment to bound the T-5min cutoff against --
                // conservatively skip rather than guess (I7: never
                // unbounded background work).
                continue;
            }
            if ($now >= $apptAt->modify('-' . self::QA_RERUN_CUTOFF_MINUTES . ' minutes')) {
                continue;
            }

            if ($this->qaLowRerunCount($outcome->pid, $outcome->factDigest) >= self::MAX_QA_RERUNS_PER_DIGEST) {
                continue;
            }

            $currentDigest = $this->readPath->currentDigest($outcome->pid);
            if ($currentDigest === null || $currentDigest !== $outcome->factDigest) {
                // Capability crash, or the facts have drifted since this
                // low-scored attempt -- skip the QA rerun (freshness guard).
                continue;
            }

            try {
                $this->readPath->regenerate($outcome->pid, null, RegenReason::QaLow);
                $enqueued++;
            } catch (\Throwable $e) {
                $this->logger->error('ClinicalCopilot: T22 QA-driven rerun failed', [
                    'pid' => $outcome->pid,
                    'fact_digest' => $outcome->factDigest,
                    'exception' => $e,
                ]);
            }
        }

        return $enqueued;
    }

    private function qaLowRerunCount(int $pid, string $factDigest): int
    {
        return (int)QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `mod_copilot_doc` WHERE `pid` = ? AND `fact_digest` = ? AND `regen_reason` = 'qa_low'",
            'c',
            [$pid, $factDigest],
        );
    }

    private function maxGenerationsPerTick(): int
    {
        $budget = $this->cadenceConfig->limits()['per_tick_worker_llm_budget_usd'];

        // An explicit zero (or negative) per-tick budget means "suspend
        // worker-driven generation" -- honour it. max(1, ...) would otherwise
        // still run one real generation every tick (~288 LLM calls/day)
        // against a budget the operator deliberately set to 0.
        if ($budget <= 0.0) {
            return 0;
        }

        return max(1, (int)floor($budget / self::ASSUMED_COST_PER_GENERATION_USD));
    }

    private function safely(string $stage, \Closure $fn): bool
    {
        try {
            $fn();

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('ClinicalCopilot: worker tick stage failed', [
                'stage' => $stage,
                'exception' => $e,
            ]);

            return false;
        }
    }
}
