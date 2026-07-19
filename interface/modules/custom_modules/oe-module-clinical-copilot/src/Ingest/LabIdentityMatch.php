<?php

/**
 * The result of comparing a lab document's patient identity to the chart.
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
 * A typed verdict + the compared identities, so the review banner can both state
 * the outcome ({@see LabIdentityStatus}) and show the uploader exactly what
 * differed. `reasons` are short, human-readable comparison lines (e.g. "Name on
 * document (Jane Doe) does not match the chart (John Smith)"); they carry PHI and
 * therefore only ever reach the ACL-gated review screen, never a log or the
 * PHI-free knowledge boundary.
 */
final readonly class LabIdentityMatch
{
    /**
     * @param list<string> $reasons human-readable comparison lines for the banner
     */
    public function __construct(
        public LabIdentityStatus $status,
        public array $reasons = [],
    ) {
    }

    public function isMismatch(): bool
    {
        return $this->status === LabIdentityStatus::Mismatch;
    }

    /**
     * The persisted detail string: the reasons joined for storage on the
     * extraction header, or null when there is nothing to say (a clean match, or
     * nothing on the document to compare).
     */
    public function detail(): ?string
    {
        return $this->reasons === [] ? null : implode(' · ', $this->reasons);
    }
}
