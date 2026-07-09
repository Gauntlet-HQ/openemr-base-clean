<?php

/**
 * Immutable timing for a single step within an AI transaction.
 *
 * The label identifies the step (for example, "fetch_recent_encounter" or
 * "openai_chat"); durationMs is the wall-clock time that step took,
 * measured with a monotonic timer so it is unaffected by clock changes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI\Observability;

final readonly class StepTiming
{
    public function __construct(
        public string $label,
        public float $durationMs,
    ) {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('Step label cannot be empty.');
        }
        if ($durationMs < 0.0) {
            throw new \InvalidArgumentException('Step duration cannot be negative.');
        }
    }
}
