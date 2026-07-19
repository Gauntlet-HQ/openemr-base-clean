<?php

/**
 * No-op AlertNotifierInterface -- the default until a site wires email/webhook.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Alert;

final class NullAlertNotifier implements AlertNotifierInterface
{
    public function notify(AlertFinding $finding): void
    {
        // Intentionally empty -- the trace span + SystemLogger + dashboard
        // banner in AlertEvaluator are the guaranteed delivery paths; this is
        // only the OPTIONAL extra channel.
    }
}
