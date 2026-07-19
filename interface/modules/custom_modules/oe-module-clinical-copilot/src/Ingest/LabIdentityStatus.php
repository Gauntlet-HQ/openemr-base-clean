<?php

/**
 * The verdict of matching an uploaded lab's patient identity against the chart.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

/**
 * Backed because the value is persisted on `mod_copilot_extraction.identity_status`
 * and read back to render the review banner. `Unknown` is a first-class case, not
 * an error: when the document carries no name/DOB to compare (or the chart lacks
 * them), we cannot confirm identity — and "cannot confirm" is louder than a false
 * "matched", because an unverified lab is exactly the PHI-mixing risk to surface.
 */
enum LabIdentityStatus: string
{
    case Match = 'match';
    case Mismatch = 'mismatch';
    case Unknown = 'unknown';

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
