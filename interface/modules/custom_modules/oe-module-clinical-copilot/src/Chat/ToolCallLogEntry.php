<?php

/**
 * One executed tool call, paired with its outcome -- what AgentLoop persists per tool turn.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallOutcome;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallRequest;

final readonly class ToolCallLogEntry
{
    public function __construct(
        public ToolCallRequest $request,
        public ToolCallOutcome $outcome,
    ) {
    }
}
