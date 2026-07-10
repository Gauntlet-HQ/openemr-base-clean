<?php

/**
 * Validates, pid-pins, and runs one tool call against the real capabilities (I10, I13).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Tool;

use OpenEMR\Modules\ClinicalCopilot\Capability\ControlProxy;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedResponse;
use OpenEMR\Modules\ClinicalCopilot\Capability\OverdueTests;
use OpenEMR\Modules\ClinicalCopilot\Capability\PendingResults;
use OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\AlertSinkInterface;
use OpenEMR\Modules\ClinicalCopilot\Verify\Sev1Signal;

/**
 * ARCHITECTURE.md §1.2/I10, the whole reason this class exists: "No tool
 * takes a patient identifier. The tool executor injects the session's
 * pinned pid server-side; on return, it asserts every produced fact carries
 * that same pid before the fact enters the session fact set (defense in
 * depth with §2.3)." Concretely, per {@see self::execute()} call:
 *
 *   1. resolve the tool name against {@see ToolCatalog} -- unrecognized name
 *      is a failure, never a fatal error (ARCHITECTURE.md §1.3)
 *   2. {@see ToolArgumentValidator::validate()} the arguments against that
 *      tool's strict schema (no `pid` property exists on ANY schema -- a
 *      forged `pid` argument is rejected here as an "unrecognized argument",
 *      never silently dropped and never reaching a capability call)
 *   3. inject `$this->pinnedPid` (constructor argument, never read from the
 *      tool call) into the ONE matching capability method
 *   4. assert every returned Fact's `pid` equals `$this->pinnedPid` -- a
 *      fact that fails this assertion is dropped from the result AND raises
 *      a {@see Sev1Signal} via {@see AlertSinkInterface} (the SAME sev-1
 *      severity V3 uses on the verifier's side, ARCHITECTURE.md §2.3/§3.5):
 *      a pid mismatch surviving to this point means a capability queried
 *      the wrong patient, which is a bug upstream of the LLM entirely --
 *      exactly the class of failure V3's "something is wrong upstream"
 *      reasoning describes, just caught one layer earlier
 *
 * `$pinnedPid` is a CONSTRUCTOR argument, never derived from the tool call
 * or from any session-mutable state visited during {@see self::execute()} --
 * the same "structural, not incidental" pinning discipline
 * {@see \OpenEMR\Modules\ClinicalCopilot\Verify\SessionFactSet} documents for
 * its own `$pinnedPid`.
 */
final class ToolExecutor implements ToolExecutorInterface
{
    public function __construct(
        private readonly int $pinnedPid,
        private readonly string $correlationId,
        private readonly ControlProxy $controlProxy,
        private readonly MedResponse $medResponse,
        private readonly VitalsTrend $vitalsTrend,
        private readonly OverdueTests $overdueTests,
        private readonly PendingResults $pendingResults,
        private readonly AlertSinkInterface $alertSink,
    ) {
    }

    public function execute(ToolCallRequest $request): ToolCallOutcome
    {
        $definition = ToolCatalog::find($request->name);
        if ($definition === null) {
            return ToolCallOutcome::failed($request->name, "unrecognized tool '{$request->name}' -- no such tool is declared");
        }

        $findings = ToolArgumentValidator::validate($definition, $request->arguments);
        if ($findings !== []) {
            return ToolCallOutcome::failed($request->name, 'invalid arguments: ' . implode('; ', $findings));
        }

        try {
            $facts = $this->dispatch($definition->name, $request->arguments);
        } catch (\Throwable $e) {
            // A capability throwing mid-turn is a tool failure, not a fatal
            // request error (ARCHITECTURE.md §1.3's "vitals lookup failed --
            // answering from labs and meds only"). The real exception is
            // logged (it can carry SQL/internal detail); the agent loop and
            // the user get only a generic reason -- never $e->getMessage(),
            // which chat.php reflects verbatim into the JSON response (repo
            // error-handling standard: no exception text in user-facing output).
            (new \OpenEMR\Common\Logging\SystemLogger())->error(
                'Clinical Co-Pilot: chat tool capability threw during extraction',
                ['tool' => $request->name, 'correlation_id' => $this->correlationId, 'exception' => $e],
            );
            return ToolCallOutcome::failed($request->name, "the '{$request->name}' lookup failed");
        }

        $assertedFacts = [];
        foreach ($facts as $fact) {
            if ($fact->pid !== $this->pinnedPid) {
                // I10 defense-in-depth: dropped, never added to the session
                // fact set, and escalated with the same severity V3 uses --
                // a Sev1Signal, even though this trips before the verifier
                // ever runs.
                $this->alertSink->sev1PatientIdentity(new Sev1Signal(
                    $this->correlationId,
                    $this->pinnedPid,
                    ["tool '{$request->name}' returned a fact with pid {$fact->pid}, which does not match the session's pinned pid {$this->pinnedPid}"],
                    new \DateTimeImmutable(),
                ));
                continue;
            }
            $assertedFacts[] = $fact;
        }

        if (count($assertedFacts) !== count($facts)) {
            return ToolCallOutcome::failed(
                $request->name,
                'one or more returned facts failed the patient-identity assertion and were discarded; this call is treated as failed',
            );
        }

        return ToolCallOutcome::ok($request->name, $assertedFacts);
    }

    /**
     * @param array<string, mixed> $arguments already schema-validated by {@see ToolArgumentValidator}
     * @return list<Fact>
     */
    private function dispatch(ToolName $name, array $arguments): array
    {
        // allFacts() (presented + exclusions), never presented() alone --
        // ARCHITECTURE.md §1.2: "Tool results are facts + citations from the
        // same deterministic code paths as the synthesis -- including the
        // exclusion accounting (I5: 'N excluded (reason)' facts pass
        // through chat answers too)."
        return match ($name) {
            ToolName::GetControlTrend => $this->controlProxy->extractForAnalyte(
                $this->pinnedPid,
                (string)$arguments['analyte'],
                (int)$arguments['window_months'],
            )->allFacts(),
            ToolName::GetMedHistory => $this->medResponse->extractFiltered(
                $this->pinnedPid,
                isset($arguments['drug_filter']) ? (string)$arguments['drug_filter'] : null,
                (int)$arguments['window_months'],
            )->allFacts(),
            ToolName::GetVitalsTrend => $this->vitalsTrend->extractForMetric(
                $this->pinnedPid,
                (string)$arguments['metric'],
                (int)$arguments['window_months'],
            )->allFacts(),
            ToolName::GetOverdue => $this->overdueTests->extract($this->pinnedPid)->allFacts(),
            ToolName::GetPending => $this->pendingResults->extract($this->pinnedPid)->allFacts(),
        };
    }
}
