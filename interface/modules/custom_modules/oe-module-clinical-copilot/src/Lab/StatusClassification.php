<?php

/**
 * The result of C2 status-semantics classification for one lab row.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;

final readonly class StatusClassification
{
    private function __construct(
        public bool $presented,
        public bool $resetsClock,
        public bool $inFlight,
        public FactStatus $factStatus,
        public ?ExclusionReason $exclusionReason,
        /**
         * Supersession rank used only among presented statuses: corrected (3)
         * > final (2) > '' (1) > preliminary (0). Meaningless for excluded
         * rows (they never enter a supersession group).
         */
        public int $supersessionRank,
    ) {
    }

    public static function presented(FactStatus $factStatus, bool $resetsClock, bool $inFlight, int $supersessionRank): self
    {
        return new self(true, $resetsClock, $inFlight, $factStatus, null, $supersessionRank);
    }

    public static function excluded(ExclusionReason $reason): self
    {
        return new self(false, false, false, FactStatus::Excluded, $reason, -1);
    }
}
