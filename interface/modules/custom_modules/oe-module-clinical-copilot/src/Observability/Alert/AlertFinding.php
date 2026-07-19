<?php

/**
 * One AlertEvaluator check outcome (fired or not).
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
 * The evaluator always returns one of these per {@see AlertName} case, fired
 * or not -- the dashboard shows the full set (a green row is as informative
 * as a red one), and only fired findings get logged/notified/spanned as
 * `error`.
 */
final readonly class AlertFinding
{
    public function __construct(
        public AlertName $name,
        public bool $fired,
        public string $message,
        public float $metricValue,
        public float $threshold,
    ) {
    }
}
