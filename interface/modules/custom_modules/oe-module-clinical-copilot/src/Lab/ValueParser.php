<?php

/**
 * Lab contract C3: numeric value parsing over `procedure_result.result`.
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

/**
 * Pure, DB-independent. `result_data_type` gates whether a numeric claim is
 * even attempted (N, S only; F/E/L never numeric -- ARCHITECTURE_COMPLETE.md
 * C3). Grammar: optional comparator (`<`, `<=`, `>`, `>=`) + decimal +
 * optional trailing unit token, whitespace-tolerant.
 *
 * Ambiguity resolved here (documented per the U4 report): C3 says an
 * unparseable numeric-eligible value becomes "a qualitative fact if the
 * capability accepts qualitative, else excluded-and-flagged" -- a
 * capability-level policy this capability-agnostic reader cannot know in
 * advance (ControlProxy, OverdueTests, and PendingResults all consume the
 * same LabSlice). Resolution: the reader's default is the least-surprising,
 * no-silent-exclusion one (I5) -- it NEVER auto-excludes on unparseability
 * alone; it returns `parsed: null` (so "no numeric claim without a parsed
 * numeric" already holds) and leaves the qualitative-vs-exclude decision to
 * the calling capability, which has the policy context this reader does not.
 */
final class ValueParser
{
    private const GRAMMAR = '/^(?<cmp><=|>=|<|>)?\s*(?<num>-?\d+(?:\.\d+)?)\s*(?<unit>\S*)$/';
    private const NUMERIC_ELIGIBLE_TYPES = ['N', 'S'];

    private function __construct()
    {
        // static-only
    }

    public static function parse(string $result, string $resultDataType): ParsedValue
    {
        $numericEligible = in_array($resultDataType, self::NUMERIC_ELIGIBLE_TYPES, true);

        if (!$numericEligible) {
            return new ParsedValue($result, null, Comparator::None, false);
        }

        $trimmed = trim($result);
        if ($trimmed === '' || preg_match(self::GRAMMAR, $trimmed, $matches) !== 1) {
            return new ParsedValue($result, null, Comparator::None, true);
        }

        $comparator = Comparator::fromToken($matches['cmp'] ?? '');
        $parsed = (float)$matches['num'];

        return new ParsedValue($result, $parsed, $comparator, true);
    }
}
