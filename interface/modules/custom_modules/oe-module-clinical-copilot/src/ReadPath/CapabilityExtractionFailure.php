<?php

/**
 * One capability's extract() throwing during the read path's extraction step.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;

/**
 * ARCHITECTURE.md §6.1 / ARCHITECTURE_COMPLETE.md's capability-crash rule:
 * carries exactly what the error trace span and the physician-facing banner
 * need -- which capability, and a structural error class/message (never the
 * raw exception's full stack trace, and never PHI: capability extraction
 * failures in this module are data-shape/DB-shape errors, not
 * patient-content errors).
 */
final readonly class CapabilityExtractionFailure
{
    public function __construct(
        public Capability $capability,
        public string $errorClass,
        public string $errorMessage,
    ) {
    }

    public static function fromThrowable(Capability $capability, \Throwable $e): self
    {
        return new self($capability, $e::class, $e->getMessage());
    }
}
