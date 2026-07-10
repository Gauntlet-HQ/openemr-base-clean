<?php

/**
 * Digest determinism and sensitivity (E5/E6): the sole freshness mechanism
 * for served narratives (I1/I4, T5).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact;

use OpenEMR\Modules\ClinicalCopilot\Fact\Digest;
use PHPUnit\Framework\TestCase;

/**
 * Failure modes guarded:
 * - E6: two extractions of the same DB state ever disagreeing on a digest
 *   would make the cache non-addressable (every read would miss, or worse,
 *   two different physical states could collide on one row).
 * - E5: a threshold/unit/cadence config bump that silently failed to
 *   invalidate previously-served docs would let a physician see a narrative
 *   computed under a superseded clinical rule with no visible sign of it.
 * - The mirror failure of E5/E4: a digest that was *too* sensitive (changing
 *   on data the read path never depended on) would defeat caching entirely
 *   and mask the one thing worth alerting on -- a real fact change.
 */
final class DigestTest extends TestCase
{
    private function baseArgs(): array
    {
        return [
            'facts' => [FactTestFactory::a1cTrendPoint(), FactTestFactory::medEvent()],
            'capabilityVersions' => [
                'control_proxy' => '1',
                'med_response' => '1',
                'vitals_trend' => '1',
                'overdue_tests' => '1',
                'pending_results' => '1',
            ],
            'configVersions' => [
                'cadence:a1c' => 'v1',
                'unit_conversion' => 'v1',
            ],
            'codeSetVersion' => 'v1',
            'docType' => 'endo-previsit-v1',
            'promptVersion' => 'v1',
        ];
    }

    public function testSameStateProducesIdenticalDigestTwice(): void
    {
        $args = $this->baseArgs();

        $first = Digest::compute(...$args);
        $second = Digest::compute(...$args);

        self::assertSame($first, $second);
    }

    public function testDigestIsIndependentOfFactExtractionOrder(): void
    {
        $args = $this->baseArgs();
        $reorderedArgs = $args;
        $reorderedArgs['facts'] = array_reverse($args['facts']);

        self::assertSame(Digest::compute(...$args), Digest::compute(...$reorderedArgs));
    }

    public function testConfigVersionBumpChangesDigest(): void
    {
        $args = $this->baseArgs();
        $before = Digest::compute(...$args);

        $bumped = $args;
        $bumped['configVersions']['unit_conversion'] = 'v2';
        $after = Digest::compute(...$bumped);

        self::assertNotSame($before, $after);
    }

    /**
     * A capability version bump must invalidate the digest even for a
     * capability that produced zero facts for this patient -- e.g. a
     * VitalsTrend logic fix could turn a previously-excluded row into a
     * presented fact even though today's raw data hasn't changed. This is
     * why capability_versions is a top-level digest component, not merely
     * inferred from each Fact's own capability_version field.
     */
    public function testCapabilityVersionBumpChangesDigestEvenForACapabilityWithNoFacts(): void
    {
        $args = $this->baseArgs();
        $before = Digest::compute(...$args);

        $bumped = $args;
        $bumped['capabilityVersions']['vitals_trend'] = '2';
        $after = Digest::compute(...$bumped);

        self::assertNotSame($before, $after);
    }

    public function testDocTypeChangeChangesDigest(): void
    {
        $args = $this->baseArgs();
        $before = Digest::compute(...$args);

        $changed = $args;
        $changed['docType'] = 'rooming-checklist-v1';
        $after = Digest::compute(...$changed);

        self::assertNotSame($before, $after);
    }

    public function testPromptVersionChangeChangesDigest(): void
    {
        $args = $this->baseArgs();
        $before = Digest::compute(...$args);

        $changed = $args;
        $changed['promptVersion'] = 'v2';
        $after = Digest::compute(...$changed);

        self::assertNotSame($before, $after);
    }

    public function testCodeSetVersionChangeChangesDigest(): void
    {
        $args = $this->baseArgs();
        $before = Digest::compute(...$args);

        $changed = $args;
        $changed['codeSetVersion'] = 'v2';
        $after = Digest::compute(...$changed);

        self::assertNotSame($before, $after);
    }

    /**
     * A late-arriving/backdated result (E1) is a genuine fact-set change and
     * must change the digest.
     */
    public function testAddingAFactChangesDigest(): void
    {
        $args = $this->baseArgs();
        $before = Digest::compute(...$args);

        $withExtraFact = $args;
        $withExtraFact['facts'][] = FactTestFactory::unitlessExclusion();
        $after = Digest::compute(...$withExtraFact);

        self::assertNotSame($before, $after);
    }

    /**
     * A corrected value (E2, in-place correction) changes fact_id via T19's
     * value-inclusion rule, which in turn changes the digest.
     */
    public function testCorrectedValueChangesDigest(): void
    {
        $args = $this->baseArgs();
        $args['facts'] = [FactTestFactory::a1cTrendPoint(resultPk: 1, raw: '7.9')];
        $before = Digest::compute(...$args);

        $corrected = $args;
        $corrected['facts'] = [FactTestFactory::a1cTrendPoint(resultPk: 1, raw: '8.1')];
        $after = Digest::compute(...$corrected);

        self::assertNotSame($before, $after);
    }
}
