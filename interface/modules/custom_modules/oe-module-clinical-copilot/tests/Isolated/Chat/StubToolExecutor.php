<?php

/**
 * A hand-written ToolExecutorInterface stub -- programmable outcomes per tool name, no database.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat;

use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallOutcome;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallRequest;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolExecutorInterface;

/**
 * Programmed with a FIFO queue of outcomes per tool name (so a test can make
 * the SAME tool succeed then later fail, or return different facts across
 * chained calls) via {@see self::enqueue()}. A tool name with no queued
 * outcome left returns a generic failure -- deliberately never a fabricated
 * success, so an unprogrammed call is loud in test output rather than
 * silently green.
 */
final class StubToolExecutor implements ToolExecutorInterface
{
    /** @var array<string, list<ToolCallOutcome>> */
    private array $queues = [];

    /** @var list<ToolCallRequest> */
    private array $received = [];

    public function enqueue(string $toolName, ToolCallOutcome $outcome): void
    {
        $this->queues[$toolName][] = $outcome;
    }

    public function execute(ToolCallRequest $request): ToolCallOutcome
    {
        $this->received[] = $request;

        $queue = $this->queues[$request->name] ?? [];
        if ($queue === []) {
            return ToolCallOutcome::failed($request->name, "StubToolExecutor: no outcome queued for '{$request->name}'");
        }

        return array_shift($this->queues[$request->name]);
    }

    /**
     * @return list<ToolCallRequest>
     */
    public function received(): array
    {
        return $this->received;
    }
}
