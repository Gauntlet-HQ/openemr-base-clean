<?php

/**
 * The seam AgentLoop depends on, so isolated tests can stub tool execution without a database.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Tool;

/**
 * {@see \OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop} depends on this
 * interface, never on {@see ToolExecutor} directly -- {@see ToolExecutor}
 * dispatches to the five REAL, DB-backed capability classes (ControlProxy,
 * MedResponse, VitalsTrend, OverdueTests, PendingResults), so exercising it
 * genuinely requires a database (its own tests live in
 * `tests/Db/Chat/ToolExecutorTest.php`, mirroring `tests/Db/Capability/`).
 * The agent loop's OWN behavior -- when it decides to call a tool, how it
 * assembles the chaining budget, how a tool failure surfaces in the answer,
 * how a forged/unrecognized tool call is handled -- is independent of which
 * capabilities back the executor, so `tests/Isolated/Chat/` binds a
 * hand-written stub implementation instead (build-notes.md: "No live LLM
 * calls anywhere in tests" -- the same "stub the seam, not the whole
 * subsystem" discipline {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface}
 * already establishes for the model itself).
 */
interface ToolExecutorInterface
{
    public function execute(ToolCallRequest $request): ToolCallOutcome;
}
