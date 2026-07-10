<?php

/**
 * The result of evaluating C3's two admissible out-of-range proofs.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

final readonly class OutOfRangeResult
{
    public function __construct(
        /** null = proof (a) unavailable (no threshold, no parsed value, or a censored value); true/false = proof (a)'s verdict */
        public ?bool $byValue,
        /** null = proof (b) unavailable (no recognized abnormal flag); true/false = proof (b)'s verdict */
        public ?bool $byLabFlag,
        /** true when both proofs are available and disagree -- I8: presented flagged, adjudicated by no one */
        public bool $conflict,
    ) {
    }

    public function isOutOfRangeByValue(): bool
    {
        return $this->byValue === true;
    }

    public function isOutOfRangeByLabFlag(): bool
    {
        return $this->byLabFlag === true;
    }
}
