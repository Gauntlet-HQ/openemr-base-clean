<?php

/**
 * An in-memory TraceRecorderInterface double that captures every recorded span.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Agent;

use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;

/**
 * Unlike {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\NullTraceRecorder},
 * this double keeps what it was handed, so a test can assert on the emitted
 * span TREE (kinds, parent linkage, statuses) — the supervisor path's
 * "inspectable handoff" guarantee — without a database.
 */
final class RecordingTraceRecorder implements TraceRecorderInterface
{
    /** @var list<TraceSpan> */
    private array $spans = [];

    public function record(TraceSpan $span): void
    {
        $this->spans[] = $span;
    }

    /**
     * @return list<TraceSpan>
     */
    public function spans(): array
    {
        return $this->spans;
    }

    /**
     * @return list<TraceSpan>
     */
    public function spansOfKind(string $kind): array
    {
        return array_values(array_filter(
            $this->spans,
            static fn (TraceSpan $span): bool => $span->kind === $kind,
        ));
    }
}
