<?php

/**
 * The V3 (patient identity) sev-1 alert payload.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

/**
 * ARCHITECTURE.md §2.3/§3.5: "A V3 failure is different in kind: it is
 * treated as a severity-1 incident ... the event is alerted and
 * audit-logged." {@see VerifiedGeneration} RAISES this signal (attaches it
 * to {@see VerifiedGenerationResult}); it does not itself deliver the alert,
 * freeze a session row, or write to `mod_copilot_trace` -- U11 (chat)
 * freezes its own `mod_copilot_chat_session.status` on this signal, and U12
 * wires the actual alert delivery/audit-log entry. This DTO carries exactly
 * what those two owners need: enough to log the incident and to reconstruct
 * "who was pinned, what did the failing check find, when."
 */
final readonly class Sev1Signal
{
    /**
     * @param list<string> $findings V3's specific findings (e.g. "claim 2
     *        cites fact F9 whose pid (114) does not match the session's
     *        pinned pid (112)")
     */
    public function __construct(
        public string $correlationId,
        public int $pinnedPid,
        public array $findings,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
