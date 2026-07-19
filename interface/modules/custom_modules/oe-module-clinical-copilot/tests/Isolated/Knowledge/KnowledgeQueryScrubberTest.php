<?php

/**
 * KnowledgeQueryScrubber — nothing patient-identifying crosses to the external store.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeQueryScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a raw chat question carrying PHI ("why is Jane's A1c 9.4
 * on 3/2?") being forwarded verbatim to the non-BAA knowledge Postgres. The
 * scrubber must strip every patient-identifying token — names, numbers, dates,
 * emails — and keep only non-PHI clinical keywords (plus the trusted tags).
 */
final class KnowledgeQueryScrubberTest extends TestCase
{
    private KnowledgeQueryScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new KnowledgeQueryScrubber();
    }

    public function testDropsNamesNumbersDatesAndKeepsClinicalTerms(): void
    {
        $out = $this->scrubber->scrub("why is Jane's A1c 9.4 on 3/2 cholesterol high", []);
        $tokens = explode(' ', $out);

        self::assertContains('cholesterol', $tokens);
        self::assertContains('high', $tokens);
        // Names, the 9.4 value, and the 3/2 date must all be gone.
        self::assertNotContains('jane', $tokens);
        self::assertNotContains("jane's", $tokens);
        self::assertStringNotContainsString('9.4', $out);
        self::assertStringNotContainsString('3/2', $out);
        self::assertStringNotContainsString('jane', strtolower($out));
    }

    public function testKeepsAnalyteCodesThatContainDigitsButDropsValuesAndDates(): void
    {
        // a1c/B12/HbA1c/sglt2 are non-PHI clinical terms even though they carry a
        // digit — the free-text chat path must not lose them — while the 9.4
        // value and the 2/3 date are dropped.
        $out = explode(' ', $this->scrubber->scrub('A1c 9.4 and B12 low on 2/3, start SGLT2', []));

        self::assertContains('a1c', $out);
        self::assertContains('b12', $out);
        self::assertContains('sglt2', $out);
        self::assertNotContains('9.4', $out);
        self::assertNotContains('2/3', $out);
    }

    public function testKeepsClinicalTermsButDropsNamesAndNonClinicalWords(): void
    {
        $out = explode(' ', $this->scrubber->scrub('LDL cholesterol for patient Smith', []));

        self::assertContains('ldl', $out);          // clinical term survives (lowercased)
        self::assertContains('cholesterol', $out);  // clinical term survives
        self::assertNotContains('smith', $out);     // name dropped
        self::assertNotContains('patient', $out);   // non-clinical word dropped (allowlist)
    }

    public function testLowercaseNamesAreDroppedByTheAllowlist(): void
    {
        // The regression this fix closes: the old capitalization blacklist let a
        // LOWERCASE name ("jane's" -> "janes") leak straight to the non-BAA store.
        // The allowlist drops anything that is not a recognized clinical term.
        $out = $this->scrubber->scrub("why is jane's a1c high", []);
        $tokens = explode(' ', $out);

        self::assertContains('a1c', $tokens);
        self::assertContains('high', $tokens);
        self::assertStringNotContainsString('jane', strtolower($out));
    }

    public function testTagsAreAlwaysKeptAndComeFirst(): void
    {
        $out = $this->scrubber->scrub('lipids', ['a1c', 'ldl']);
        $tokens = explode(' ', $out);

        self::assertContains('a1c', $tokens);
        self::assertContains('ldl', $tokens);
        self::assertContains('lipids', $tokens);
        self::assertSame('a1c', $tokens[0], 'tags lead the query');
    }

    public function testUnrecognizedTagsAreDroppedNotForwarded(): void
    {
        // The finding this closes (SECURITY.md #12): tags used to bypass the
        // scrubber entirely on the retrieval path, so a caller-supplied tag
        // could carry PHI ("jane", an MRN) straight to the non-BAA store.
        // Tags now go through the same allowlist discipline as free text.
        $out = $this->scrubber->scrub('', ['jane', 'MRN12345678', 'a1c']);

        self::assertSame('a1c', $out);
        self::assertSame(['a1c'], $this->scrubber->scrubTags(['jane', 'MRN12345678', 'a1c']));
    }

    #[DataProvider('safeTagProvider')]
    public function testCorpusAndTopicTagVocabularySurvivesTagScrubbing(string $rawTag, string $expected): void
    {
        self::assertSame([$expected], $this->scrubber->scrubTags([$rawTag]));
    }

    /**
     * @return array<string, array{string, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function safeTagProvider(): array
    {
        return [
            // every producer of retrieval tags draws from this closed set:
            // corpus chunk tags + PatientEvidenceService topics + analyte codes
            'analyte code' => ['a1c', 'a1c'],
            'analyte code, cased' => ['GLP1', 'glp1'],
            'clinical term' => ['triglycerides', 'triglycerides'],
            'corpus-only tag' => ['glycemic', 'glycemic'],
            'corpus-only tag (uacr)' => ['uacr', 'uacr'],
            'topic tag with underscore' => ['blood_pressure', 'bloodpressure'],
            'hypoglycemia topic tag' => ['hypoglycemia', 'hypoglycemia'],
        ];
    }

    #[DataProvider('unsafeTagProvider')]
    public function testPhiShapedTagsNeverSurviveTagScrubbing(string $rawTag): void
    {
        self::assertSame([], $this->scrubber->scrubTags([$rawTag]));
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function unsafeTagProvider(): array
    {
        return [
            'lowercase name' => ['jane'],
            'capitalized name' => ['Smith'],
            'mrn-shaped identifier' => ['MRN12345678'],
            'date' => ['2024-01-01'],
            'bare number' => ['55'],
            'free text' => ['call her about the result'],
        ];
    }

    public function testDropsEmailsAndStopwordsAndShortNoise(): void
    {
        $out = explode(' ', $this->scrubber->scrub('the a1c for jdoe@example.com is', []));

        self::assertNotContains('the', $out);                 // stopword
        self::assertNotContains('is', $out);                  // sub-3-char noise
        self::assertNotContains('jdoe@example.com', $out);    // email
        self::assertContains('a1c', $out);
    }

    public function testAllPhiInputYieldsEmptyOutput(): void
    {
        // A question that is nothing but a name + numbers must scrub to empty, so
        // the retriever sends no free text at all.
        self::assertSame('', $this->scrubber->scrub('John 55 2024-01-01', []));
    }

    public function testDeduplicatesAcrossTagsAndText(): void
    {
        $out = explode(' ', $this->scrubber->scrub('cholesterol cholesterol', ['cholesterol']));

        self::assertSame(['cholesterol'], $out);
    }
}
