<?php

/**
 * Everything Verifier::verify() needs about the session beyond the raw claims JSON.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

final readonly class VerificationContext
{
    public function __construct(
        public SessionFactSet $factSet,
        public VerificationPath $path,
    ) {
    }
}
