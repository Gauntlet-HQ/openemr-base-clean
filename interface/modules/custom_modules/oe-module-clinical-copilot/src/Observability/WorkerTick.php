<?php

/**
 * The single entry point U9's background worker calls on every tick for observability duties.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Observability\Alert\AlertEvaluator;
use OpenEMR\Modules\ClinicalCopilot\Observability\Alert\AlertFinding;
use OpenEMR\Modules\ClinicalCopilot\Observability\Alert\LogAlertNotifier;
use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaReviewer;
use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaSweepSummary;

/**
 * `src/worker_entry.php`'s stub body will be replaced by U9's real warm-sweep
 * loop (see that file's own docblock: "will replace this body without
 * changing the background_services row"). This class is what that future
 * body calls for everything U12 owns, so U9 never has to know
 * {@see AlertEvaluator}/{@see QaReviewer}/heartbeat-recording exist as
 * separate pieces:
 *
 * ```php
 * $tick = WorkerTick::createDefault();
 * $tick->recordHeartbeat();                 // dead-man switch (§3.5)
 * $qaSummary = $tick->runQaSweep(20);        // T22: read $qaSummary->docOutcomes()
 * $alertFindings = $tick->runAlertEvaluation();
 * ```
 *
 * Ordering note for U9: call {@see self::recordHeartbeat()} FIRST on every
 * tick, even if the rest of the tick's own warm work throws -- the heartbeat
 * is the dead-man switch {@see \OpenEMR\Modules\ClinicalCopilot\Observability\ReadyCheck}
 * and the dashboard depend on, so it must land regardless of what else on
 * the tick fails.
 */
final class WorkerTick
{
    public function __construct(
        private readonly QaReviewer $qaReviewer,
        private readonly AlertEvaluator $alertEvaluator,
    ) {
    }

    public static function createDefault(): self
    {
        $tracer = new TraceRecorder();

        return new self(
            QaReviewer::createDefault(),
            new AlertEvaluator($tracer, new LogAlertNotifier()),
        );
    }

    /**
     * ARCHITECTURE.md §3.5: the worker-heartbeat "dead-man switch" -- written
     * here, read by {@see ReadyCheck} and the dashboard, never the other way
     * around. Module-owned config row, UPDATE permitted (I3 exempts config).
     */
    public function recordHeartbeat(): void
    {
        $now = new \DateTimeImmutable();

        $raw = QueryUtils::fetchSingleValue(
            "SELECT `config_json` FROM `mod_copilot_cadence` WHERE `code_set` = 'worker_heartbeat'",
            'config_json',
        );
        $config = is_string($raw) ? json_decode($raw, true) : null;
        $tickCount = is_array($config) ? (int)($config['tick_count'] ?? 0) : 0;

        $updated = [
            'last_tick_at' => $now->format(DATE_ATOM),
            'tick_count' => $tickCount + 1,
        ];

        QueryUtils::sqlStatementThrowException(
            "UPDATE `mod_copilot_cadence` SET `config_json` = ?, `updated_at` = ? WHERE `code_set` = 'worker_heartbeat'",
            [
                json_encode($updated, JSON_THROW_ON_ERROR),
                $now->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * T22 (docs/build-notes.md "Warm timing + QA-driven rerun"): U9 reads
     * `$summary->docOutcomes()` afterward -- for each outcome where
     * `qaStatus === QaStatus::Low`, U9 applies the T22 rules (freshness guard,
     * max 2 reruns per (pid, fact_digest), breaker check) to decide whether
     * to enqueue exactly one regeneration. This class only supplies the
     * qa_score/qaStatus signal; the enqueue decision and the rerun itself are
     * entirely U9's.
     */
    public function runQaSweep(int $limit): QaSweepSummary
    {
        return $this->qaReviewer->sweep($limit);
    }

    /**
     * @return list<AlertFinding>
     */
    public function runAlertEvaluation(): array
    {
        return $this->alertEvaluator->run();
    }
}
