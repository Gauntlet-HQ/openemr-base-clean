<?php

/**
 * Clinical Co-Pilot background worker entry point.
 *
 * Registered against the `clinical_copilot_worker` background_services row
 * (`function` = clinicalCopilotWorkerRun, `require_once` = this file). The
 * host background-services runner requires this file and calls the function
 * by name on logged-in AJAX ticks / cron (build-notes.md "Background
 * services"). U9 replaces the original stub body with the real warm/QA/
 * rerun/alert tick ({@see \OpenEMR\Modules\ClinicalCopilot\Worker::runTick()})
 * without changing the background_services row itself (name, interval,
 * function, require_once are all unchanged from U1's install row).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Worker;

if (!function_exists('clinicalCopilotWorkerRun')) {
    /**
     * One warm/QA/rerun/alert tick (ARCHITECTURE_COMPLETE.md "Compute model"
     * WORKER block; {@see Worker::runTick()} for the exact stage ordering).
     * I7: worker failure degrades latency only, never correctness -- this
     * function itself never lets an exception escape (every stage inside
     * {@see Worker::runTick()} is already individually guarded), so the
     * host's `background_services` runner always sees a clean, successful
     * tick even on a bad day for one stage.
     *
     * @return bool always true -- see the docblock above for why a thrown
     *         exception here would be a bug in {@see Worker::runTick()}'s
     *         own per-stage guarding, not an expected outcome
     */
    function clinicalCopilotWorkerRun(): bool
    {
        try {
            Worker::createDefault()->runTick();
        } catch (\Throwable $e) {
            // Unreachable in the intended design (every stage inside
            // Worker::runTick() already catches \Throwable on its own), but
            // this function is the host framework's contract point -- never
            // let a worker-side defect surface as an uncaught exception on
            // a logged-in user's AJAX tick.
            (new SystemLogger())->error('ClinicalCopilot: worker tick threw unexpectedly', ['exception' => $e]);
        }

        return true;
    }
}
