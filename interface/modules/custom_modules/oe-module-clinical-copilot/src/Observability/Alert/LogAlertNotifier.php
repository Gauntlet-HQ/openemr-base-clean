<?php

/**
 * SystemLogger-only AlertNotifierInterface -- production default until email/webhook is wired.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Alert;

use OpenEMR\Common\Logging\SystemLogger;

/**
 * A stub deploy-time can swap for a real email/webhook sender (ARCHITECTURE.md
 * §3.5: "pluggable email/webhook at deploy time"). {@see AlertEvaluator}
 * already logs at `error` severity itself for every fired finding, so this
 * class's contribution is a SEPARATE, deliberately loud `critical`-severity
 * line meant to be greppable/alertable by an external log-shipping pipeline
 * independent of the dashboard.
 */
final class LogAlertNotifier implements AlertNotifierInterface
{
    public function __construct(
        private readonly SystemLogger $logger = new SystemLogger(),
    ) {
    }

    public function notify(AlertFinding $finding): void
    {
        $this->logger->critical('ClinicalCopilot: ALERT FIRED', [
            'alert' => $finding->name->value,
            'message' => $finding->message,
            'metric_value' => $finding->metricValue,
            'threshold' => $finding->threshold,
            'meaning' => $finding->name->meaning(),
            'on_call_response' => $finding->name->onCallResponse(),
        ]);
    }
}
