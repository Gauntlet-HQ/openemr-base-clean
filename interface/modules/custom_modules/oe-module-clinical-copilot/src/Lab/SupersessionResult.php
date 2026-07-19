<?php

/**
 * The winner and superseded losers within a supersession group.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

final readonly class SupersessionResult
{
    /**
     * @param list<int> $supersededProcedureResultIds
     */
    public function __construct(
        public int $winnerProcedureResultId,
        public array $supersededProcedureResultIds,
    ) {
    }

    public function supersededCount(): int
    {
        return count($this->supersededProcedureResultIds);
    }
}
