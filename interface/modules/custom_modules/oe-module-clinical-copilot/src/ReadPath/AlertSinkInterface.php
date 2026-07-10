<?php

/**
 * The seam U12's alert delivery plugs into for the V3 sev-1 signal.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Verify\Sev1Signal;

/**
 * ARCHITECTURE.md §2.3/§3.5: a V3 (patient identity) failure is a
 * severity-1 incident, not an ordinary retry -- {@see \OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGeneration}
 * raises the {@see Sev1Signal} but does not itself deliver an alert or
 * write an audit-log entry (that's out of U10's scope, per that class's own
 * docblock). {@see SynthesisReadPath} routes the signal here on the
 * synthesis path (there is no chat session to freeze on this path -- that's
 * U11's job); U12 supplies the real alert delivery + `EventAuditLogger`
 * entry. {@see NullAlertSink} is the default no-op until then.
 */
interface AlertSinkInterface
{
    public function sev1PatientIdentity(Sev1Signal $signal): void;
}
