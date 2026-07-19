<?php

/**
 * The six deterministic checks (ARCHITECTURE.md §2.2), in the order they run.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

/**
 * Enum declaration order matches the table in ARCHITECTURE.md §2.2 and the
 * order {@see Verifier::verify()} runs them in -- V1 gates everything else
 * (malformed output makes V2-V6 meaningless), the rest run unconditionally so
 * every attempt always has all six verdicts recorded (ARCHITECTURE_COMPLETE.md
 * U10 acceptance: "verdicts recorded per check").
 */
enum CheckId: string
{
    case SchemaGate = 'V1';
    case CitationResolution = 'V2';
    case PatientIdentity = 'V3';
    case NumericGrounding = 'V4';
    case BannedClaimLint = 'V5';
    case ConflictPassthrough = 'V6';

    /**
     * A human-readable name for the observability dashboard (the V-codes are an
     * internal shorthand; the ledger and the dashboard should read plainly).
     * Exhaustive match, no default — a new check forces a name here.
     */
    public function label(): string
    {
        return match ($this) {
            self::SchemaGate => 'Schema gate',
            self::CitationResolution => 'Citation grounding',
            self::PatientIdentity => 'Patient identity',
            self::NumericGrounding => 'Numeric grounding',
            self::BannedClaimLint => 'Safe-language lint',
            self::ConflictPassthrough => 'Conflict surfacing',
        };
    }
}
