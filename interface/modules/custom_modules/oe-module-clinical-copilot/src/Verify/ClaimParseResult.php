<?php

/**
 * The outcome of one ClaimSchema::parse() call.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;

final readonly class ClaimParseResult
{
    /**
     * @param list<Claim> $claims
     * @param list<string> $errors
     */
    private function __construct(
        public bool $valid,
        public array $claims,
        public array $errors,
    ) {
    }

    /**
     * @param list<Claim> $claims
     */
    public static function ok(array $claims): self
    {
        return new self(true, $claims, []);
    }

    /**
     * @param non-empty-list<string> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(false, [], $errors);
    }
}
