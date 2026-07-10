<?php

/**
 * Lab contract C3: out-of-range needs exactly one of two admissible proofs.
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
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\Threshold;

/**
 * Pure, DB-independent (threshold is injected). Proof (a): parsed numeric
 * vs. a versioned threshold -- restricted to an EXACT (non-censored) parsed
 * value, since a censored bound ("<7.0") only supports the claim its
 * direction proves, and whether that direction crosses a given threshold is
 * not, in general, decidable without a per-threshold directional analysis
 * this reader does not have (documented ambiguity resolution, U4 report).
 * Proof (b): the lab's own `abnormal` in {yes, high, low} plus a non-empty
 * `range` (both cited). When both proofs are available and disagree, this
 * is a `conflict` (I8): both are reported, nothing here adjudicates.
 */
final class OutOfRangeEvaluator
{
    private const POSITIVE_ABNORMAL_VALUES = ['yes', 'high', 'low'];

    private function __construct()
    {
        // static-only
    }

    public static function evaluate(
        ?float $parsed,
        Comparator $comparator,
        ?Threshold $threshold,
        string $abnormal,
        string $range,
    ): OutOfRangeResult {
        $byValue = null;
        if ($threshold !== null && $parsed !== null && $comparator === Comparator::None) {
            $byValue = $threshold->isOutOfRange($parsed);
        }

        $normalizedAbnormal = strtolower(trim($abnormal));
        $hasRange = trim($range) !== '';

        $byLabFlag = match (true) {
            in_array($normalizedAbnormal, self::POSITIVE_ABNORMAL_VALUES, true) && $hasRange => true,
            $normalizedAbnormal === 'no' => false,
            default => null,
        };

        $conflict = $byValue !== null && $byLabFlag !== null && $byValue !== $byLabFlag;

        return new OutOfRangeResult($byValue, $byLabFlag, $conflict);
    }
}
