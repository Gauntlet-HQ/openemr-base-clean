<?php

/**
 * Pluggable alert delivery beyond the trace span + SystemLogger baseline (ARCHITECTURE.md §3.5).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Alert;

/**
 * "Firing writes an alert_eval span, surfaces a dashboard banner, and logs at
 * error severity (pluggable email/webhook at deploy time)" (ARCHITECTURE.md
 * §3.5). The span write, dashboard banner, and log line are unconditional
 * (built into {@see \OpenEMR\Modules\ClinicalCopilot\Observability\Alert\AlertEvaluator}
 * directly); this interface is exactly the "pluggable" part -- a site wires
 * a real email/webhook implementation at deploy time without touching the
 * evaluator itself.
 */
interface AlertNotifierInterface
{
    public function notify(AlertFinding $finding): void;
}
