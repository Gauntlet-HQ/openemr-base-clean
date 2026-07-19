<?php

/**
 * One candidate row within a (patient, result_code, clinical_date) group.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

final readonly class SupersessionCandidate
{
    public function __construct(
        public int $procedureResultId,
        /** corrected > final > '' (unstated) > preliminary, per {@see ResultStatusClassifier} */
        public int $supersessionRank,
    ) {
    }
}
