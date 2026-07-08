<?php

/**
 * The seam U12's mod_copilot_trace writer plugs into (I12).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

/**
 * {@see SynthesisReadPath} calls {@see self::record()} once per step (extract
 * -- one call per capability --, digest, cache_lookup, llm_reduce, verify,
 * render), on both the happy path and every failure/degradation branch, so
 * cache hits, degraded reads, and capability-crash failures all leave a
 * trace (I12) without U12 having to retrofit a single call site. U9's
 * worker warm and U11's chat preload reuse the SAME interface (and the same
 * {@see SynthesisReadPath} entry point for warming), so one implementation
 * covers every surface.
 *
 * {@see NullTraceRecorder} is the default (a no-op) -- wired everywhere
 * until U12 supplies the real `mod_copilot_trace` writer. Deliberately a
 * single `record()` method rather than a start/end span pair: the read path
 * already knows a span's full duration/outcome by the time it is ready to
 * record it (there is no concurrent, still-open span to update later), so a
 * single INSERT-shaped call is the whole contract.
 */
interface TraceRecorderInterface
{
    public function record(TraceSpan $span): void;
}
