<?php

/**
 * No-op TraceRecorderInterface default, until U12 supplies the real writer.
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
 * Lets {@see SynthesisReadPath} call {@see TraceRecorderInterface::record()}
 * unconditionally at every step today, with zero behavior until U12 wires
 * `mod_copilot_trace` -- swapping this for the real writer at composition
 * time is the only change U12 needs to make.
 */
final class NullTraceRecorder implements TraceRecorderInterface
{
    public function record(TraceSpan $span): void
    {
        // Intentionally empty -- U12 supplies the mod_copilot_trace writer.
    }
}
