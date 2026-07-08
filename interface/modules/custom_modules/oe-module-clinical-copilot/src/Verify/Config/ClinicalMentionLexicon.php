<?php

/**
 * Detects whether free text carries clinical content, regardless of a claim's declared type.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify\Config;

/**
 * ARCHITECTURE.md §2.2, V2 row: "any claim mentioning an analyte,
 * medication, numeric value, date, or patient attribute is clinical
 * regardless of its declared type and must cite." This is that re-check's
 * lexicon -- version-pinned config, not a general medical dictionary
 * (docs/build-notes.md discipline for V5's lexicon applies equally here):
 * the analyte terms are the module's own {@see \OpenEMR\Modules\ClinicalCopilot\Capability\Config\AnalyteCodeSets}
 * monitoring domain (A1c, glucose, the lipid panel, ACR) restated as prose
 * words a narrated claim would actually use, plus the small, bounded set of
 * diabetes-medication classes MedResponse's fixtures exercise -- NOT an
 * attempt at a comprehensive drug/analyte ontology.
 *
 * Also used by V5's causation check: "after" only implies causation over a
 * medication<->lab pairing (ARCHITECTURE.md §2.2, V5 row), so V5 asks this
 * class specifically whether a claim mentions BOTH a drug term and an
 * analyte term, not merely "clinical content" in general.
 */
final class ClinicalMentionLexicon
{
    public const VERSION = '1';

    // Lookbehind/lookahead exclude a digit run touching a letter on either
    // side, so a digit embedded in an alphanumeric token (analyte/drug names
    // like "A1c", "SGLT2") is never mistaken for an asserted number.
    private const NUMBER_PATTERN = '/(?<![A-Za-z])-?\d+(?:\.\d+)?(?![A-Za-z])/';
    private const DATE_PATTERN = '/\b\d{4}-\d{2}-\d{2}\b/';

    /** @var list<string> */
    private const ANALYTE_TERMS = [
        'a1c', 'hba1c', 'glucose', 'cholesterol', 'ldl', 'hdl',
        'triglyceride', 'triglycerides', 'acr', 'microalbumin',
        'weight', 'bmi', 'blood pressure', 'bp',
    ];

    /** @var list<string> */
    private const DRUG_TERMS = [
        'metformin', 'insulin', 'glipizide', 'glyburide', 'sitagliptin',
        'empagliflozin', 'dapagliflozin', 'liraglutide', 'semaglutide',
        'sulfonylurea', 'sglt2', 'glp-1',
    ];

    /** @var list<string> */
    private const OTHER_CLINICAL_TERMS = [
        'dose', 'dosage', 'mg', 'overdue', 'pending', 'preliminary',
        'corrected', 'age', 'gender', 'years old',
    ];

    private function __construct()
    {
        // static-only
    }

    public static function mentionsClinicalContent(string $text): bool
    {
        if (preg_match(self::NUMBER_PATTERN, $text) === 1) {
            return true;
        }

        if (preg_match(self::DATE_PATTERN, $text) === 1) {
            return true;
        }

        return self::mentionsAnalyteTerm($text)
            || self::mentionsDrugTerm($text)
            || self::containsAny($text, self::OTHER_CLINICAL_TERMS);
    }

    public static function mentionsAnalyteTerm(string $text): bool
    {
        return self::containsAny($text, self::ANALYTE_TERMS);
    }

    public static function mentionsDrugTerm(string $text): bool
    {
        return self::containsAny($text, self::DRUG_TERMS);
    }

    /**
     * Every ISO-8601 date substring found in `$text` (V4 grounds each one
     * against cited facts' `clinical_date`).
     *
     * @return list<string>
     */
    public static function extractDates(string $text): array
    {
        preg_match_all(self::DATE_PATTERN, $text, $matches);

        return $matches[0];
    }

    /**
     * Every numeric substring found in `$text`, with any date substrings
     * removed first so a date's own digit groups (year/month/day) are never
     * double-counted as stray ungrounded numbers.
     *
     * @return list<float>
     */
    public static function extractNumbers(string $text): array
    {
        $withoutDates = preg_replace(self::DATE_PATTERN, ' ', $text) ?? $text;
        preg_match_all(self::NUMBER_PATTERN, $withoutDates, $matches);

        return array_map(static fn (string $match): float => (float)$match, $matches[0]);
    }

    /**
     * @param list<string> $terms
     */
    private static function containsAny(string $text, array $terms): bool
    {
        $lower = strtolower($text);
        foreach ($terms as $term) {
            if (str_contains($lower, $term)) {
                return true;
            }
        }

        return false;
    }
}
