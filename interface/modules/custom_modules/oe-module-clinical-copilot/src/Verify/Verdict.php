<?php

/**
 * The per-check outcome of one verification check.
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
 * `findings` are specific, not summary strings -- ARCHITECTURE.md §2.3's
 * example ("claim 3 cites fact F17 which does not contain the value 8.4") is
 * the bar every check's finding text is written to meet, since these strings
 * are what {@see VerifiedGeneration} appends verbatim to the regeneration
 * prompt. `skipped` marks a check that never ran because V1 (schema gate)
 * failed first -- still recorded (never a missing row), just not evaluated.
 */
final readonly class Verdict
{
    /**
     * @param list<string> $findings
     */
    public function __construct(
        public CheckId $checkId,
        public bool $passed,
        public array $findings,
        public bool $skipped = false,
    ) {
    }

    public static function pass(CheckId $checkId): self
    {
        return new self($checkId, true, []);
    }

    /**
     * @param non-empty-list<string> $findings
     */
    public static function fail(CheckId $checkId, array $findings): self
    {
        return new self($checkId, false, $findings);
    }

    public static function skip(CheckId $checkId, string $reason): self
    {
        return new self($checkId, false, [$reason], true);
    }
}
