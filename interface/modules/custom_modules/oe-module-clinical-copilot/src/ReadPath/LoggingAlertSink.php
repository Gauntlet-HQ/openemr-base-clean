<?php

/**
 * Best-effort sev-1 delivery until U12 wires the real alert evaluator.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Verify\Sev1Signal;

/**
 * ARCHITECTURE.md §2.3: "the event is alerted (§3.5) and audit-logged." U12
 * owns the real alert evaluator/dashboard; this is the production default
 * until then -- a sev-1 must never go completely unnoticed in the meantime.
 * Logs a `critical`-severity line (correlation id + pid + findings, never
 * raw prose that might carry PHI beyond the pid) and writes an
 * `EventAuditLogger` entry so the incident is at least visible in the
 * standard EMR audit trail. Swap for U12's real sink at composition time
 * once it lands -- {@see SynthesisReadPath} never changes.
 */
final class LoggingAlertSink implements AlertSinkInterface
{
    private readonly SystemLogger $logger;

    public function __construct(?SystemLogger $logger = null)
    {
        $this->logger = $logger ?? new SystemLogger();
    }

    public function sev1PatientIdentity(Sev1Signal $signal): void
    {
        $this->logger->error('ClinicalCopilot: V3 patient-identity sev-1 incident', [
            'correlation_id' => $signal->correlationId,
            'pinned_pid' => $signal->pinnedPid,
            'findings' => $signal->findings,
            'occurred_at' => $signal->occurredAt->format(DATE_ATOM),
        ]);

        EventAuditLogger::getInstance()->newEvent(
            'security',
            '-',
            '-',
            0,
            'Clinical Co-Pilot sev-1: patient identity check failed, correlation_id=' . $signal->correlationId,
            $signal->pinnedPid,
        );
    }
}
