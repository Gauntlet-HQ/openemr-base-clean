<?php

/**
 * The result of C3 value-parsing over `procedure_result.result`.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;

final readonly class ParsedValue
{
    public function __construct(
        public string $raw,
        public ?float $parsed,
        public Comparator $comparator,
        /**
         * Whether `result_data_type` was in {N, S} at all -- distinguishes a
         * value that is legitimately qualitative by type (F/E/L) from one
         * that was numeric-eligible but failed to parse.
         */
        public bool $numericTypeEligible,
    ) {
    }

    public function isUnparseable(): bool
    {
        return $this->numericTypeEligible && $this->parsed === null;
    }
}
