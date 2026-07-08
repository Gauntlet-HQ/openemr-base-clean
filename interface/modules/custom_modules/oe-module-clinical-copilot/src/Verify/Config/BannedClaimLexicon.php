<?php

/**
 * V5's version-pinned trigger-phrase lexicon.
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
 * ARCHITECTURE.md §2.2, V5 row: "deterministic pattern classes: causation
 * ..., treatment recommendations ..., diagnoses, dosage advice,
 * drug-interaction assertions not present as facts. The lexicon (trigger
 * patterns + analyte/drug terms drawn from the module's own code sets and
 * med facts, not a general medical dictionary) is version-pinned config."
 *
 * Five closed trigger-phrase categories, matched as case-insensitive
 * substrings against claim text -- deliberately lexical, not semantic (§2.2:
 * "V5 rejects the lexical class; paraphrased violations are a named
 * residual" -- §2.4). Bump {@see self::VERSION} on any change to a category
 * list; it is NOT currently a digest input (banned-claim lint is a
 * verification-time check, not a fact-extraction input) but is recorded here
 * so a future digest/eval tie-in has one version string to read.
 *
 * Resolved ambiguity (documented per the U10 report): "drug-interaction
 * assertions not present as facts" -- the Fact schema (ARCHITECTURE_COMPLETE.md)
 * has no interaction-fact `kind` at all, so an interaction assertion can
 * never be "present as a fact" in this system. The `interaction` category
 * below is therefore an unconditional lexical ban, not a fact-set lookup.
 *
 * The `causation` category's "after" trigger is intentionally scoped
 * (ARCHITECTURE.md §2.2, V5 row: "'after…' over med<->lab pairings") --
 * see {@see self::violations()}: bare "after" is not banned; "after" *and*
 * both a drug term and an analyte term in the same claim is.
 */
final class BannedClaimLexicon
{
    public const VERSION = '1';

    /** @var array<string, list<string>> */
    private const TRIGGERS = [
        'causation' => [
            'because', 'due to', 'caused by', 'caused', 'causing',
            'leads to', 'led to', 'resulting in', 'as a result of',
            'which explains', 'which caused', 'accounts for the rise',
            'accounts for the drop', 'is why',
        ],
        'recommendation' => [
            'should start', 'should increase', 'should decrease', 'should stop',
            'should discontinue', 'should switch', 'should be started',
            'should be increased', 'should be decreased', 'should be stopped',
            'consider starting', 'consider increasing', 'consider stopping',
            'recommend starting', 'recommend increasing', 'recommend stopping',
            'needs to start', 'needs to increase', 'needs to stop',
        ],
        'diagnosis' => [
            'diagnosed with', 'diagnosis of', 'consistent with a diagnosis',
            'suggestive of', 'indicates a diagnosis', 'is diabetic nephropathy',
            'is nephropathy', 'is retinopathy', 'is neuropathy', 'has diabetes',
        ],
        'dosage' => [
            'increase the dose', 'decrease the dose', 'increase to',
            'reduce the dose', 'reduce to', 'titrate to',
            'take twice daily', 'take once daily', 'mg twice daily', 'mg once daily',
        ],
        'interaction' => [
            'interacts with', 'drug interaction', 'should not be combined with',
            'contraindicated with', 'unsafe to combine',
        ],
    ];

    private const AFTER_TRIGGER = 'after';

    private function __construct()
    {
        // static-only
    }

    /**
     * @return list<string> human-readable, per-hit findings; empty when clean
     */
    public static function violations(string $text): array
    {
        $lower = strtolower($text);
        $hits = [];

        foreach (self::TRIGGERS as $category => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($lower, $phrase)) {
                    $hits[] = "{$category}: matched banned trigger phrase \"{$phrase}\"";
                }
            }
        }

        if (
            str_contains($lower, self::AFTER_TRIGGER)
            && ClinicalMentionLexicon::mentionsDrugTerm($text)
            && ClinicalMentionLexicon::mentionsAnalyteTerm($text)
        ) {
            $hits[] = 'causation: "after" used alongside both a medication and an analyte reference implies an unproven causal claim';
        }

        return $hits;
    }
}
