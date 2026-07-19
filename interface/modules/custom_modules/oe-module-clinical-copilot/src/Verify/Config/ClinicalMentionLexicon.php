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

    /**
     * Narrative-number patterns that V4 must NOT treat as ungrounded data
     * pulls: a claim's meaning turns on its actual clinical VALUES (lab
     * results, readings, counts that have a fact), but ordinary clinical
     * English is full of numbers that are not data pulls and have no fact to
     * cite -- dates, ages, how often/how long something happens, a disease
     * type or stage, and medication doses. Each span these match is blanked
     * before {@see self::extractGroundableNumbers()} scans for numbers, so the
     * grounding guard stays on the meat and off the scaffolding.
     *
     * Deliberately does NOT exempt concentration values ("98 mg/dL", "7.2 %"):
     * those ARE results and must still ground. A medication dose ("1000 mg")
     * is exempt because it is carried verbatim by the cited prescription row,
     * not by a numeric result fact -- a negative lookahead for a trailing
     * slash keeps a lab unit like "mg/dL" out of that dose exemption.
     *
     * @var list<string>
     */
    private const EXEMPT_NUMBER_PATTERNS = [
        '/\b(?:type|stage|grade|class|phase)\s+\d+(?:\.\d+)?/i',
        '/\b(?:age[ds]?\s+\d+|\d+\s*[-\s]?years?[-\s]old)/i',
        '/\b(?:every|per|past|last|next|over|for|within|prior(?:\s+to)?|about|around|approximately)\s+\d+(?:\.\d+)?/i',
        '/\b\d+(?:\.\d+)?\s*(?:seconds?|minutes?|hours?|days?|weeks?|months?|years?|wks?|hrs?|mins?)\b/i',
        '/\b\d+(?:\.\d+)?\s*(?:x|times?)\b/i',
        '/\b\d+(?:\.\d+)?\s*(?:daily|weekly|monthly|yearly|nightly|hourly|bid|tid|qid|qhs|qd|qod|prn)\b/i',
        '/\b\d+(?:\.\d+)?\s*(?:mg|mcg|g|units?|iu|ml|tablets?|caps?|puffs?)\b(?!\s*\/)/i',
    ];

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
     * The numbers V4 must ground: actual clinical VALUES a claim pulls (lab
     * results, readings), with narrative numbers -- dates, ages, frequencies/
     * durations, disease type/stage, medication doses -- removed first (see
     * {@see self::EXEMPT_NUMBER_PATTERNS}). A stray value the model states in
     * prose but does not exempt (e.g. an ungrounded "7.9") is still returned,
     * so the "every medical pull is verified" guarantee holds while ordinary
     * clinical English no longer trips the check.
     *
     * @return list<float>
     */
    public static function extractGroundableNumbers(string $text): array
    {
        $scrubbed = preg_replace(self::EXEMPT_NUMBER_PATTERNS, ' ', $text) ?? $text;

        return self::extractNumbers($scrubbed);
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
