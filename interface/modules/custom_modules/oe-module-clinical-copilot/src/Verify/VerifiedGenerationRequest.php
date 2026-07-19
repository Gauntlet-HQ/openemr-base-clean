<?php

/**
 * One call to VerifiedGeneration::generate().
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

use OpenEMR\Modules\ClinicalCopilot\Reduce\ReduceRequest;

/**
 * Bundles what U7's {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer}
 * needs (`reduceRequest`) with what U10's {@see Verifier} needs
 * (`verificationContext`) into the one argument U8 (synthesis read path) and
 * U11 (chat turn controller) pass to {@see VerifiedGeneration::generate()}.
 * `reduceRequest->facts` and `verificationContext->factSet` MUST be built
 * from the same session fact set -- the caller's responsibility; this DTO
 * does not re-derive one from the other.
 */
final readonly class VerifiedGenerationRequest
{
    public function __construct(
        public ReduceRequest $reduceRequest,
        public VerificationContext $verificationContext,
    ) {
    }
}
