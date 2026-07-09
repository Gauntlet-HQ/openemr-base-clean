<?php

/**
 * Test-only capturing AlertNotifierInterface.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Observability;

use OpenEMR\Modules\ClinicalCopilot\Observability\Alert\AlertFinding;
use OpenEMR\Modules\ClinicalCopilot\Observability\Alert\AlertNotifierInterface;

/**
 * Records every {@see self::notify()} call so
 * {@see AlertEvaluatorTest::testFiredFindingsWriteAnAlertEvalSpanAndNotifyTheNotifier()}
 * can assert it was only ever invoked for FIRED findings.
 */
final class CapturingAlertNotifier implements AlertNotifierInterface
{
    /** @var list<AlertFinding> */
    public array $notified = [];

    public function notify(AlertFinding $finding): void
    {
        $this->notified[] = $finding;
    }
}
