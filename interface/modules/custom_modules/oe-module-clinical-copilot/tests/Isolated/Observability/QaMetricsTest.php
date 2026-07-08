<?php

/**
 * Isolated evals: QaMetrics' deterministic (LLM-free) density/utilization math.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Observability;

use OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaMetrics;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ClaimType;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * docs/build-notes.md "U12 additions": "density/utilization ... are pure
 * trace math (no LLM)." These evals guard the two formulas with plain
 * fixtures, independent of any DB or Flash call -- the QaReviewer orchestrator
 * itself is exercised separately under tests/Db (it needs mod_copilot_doc/qa
 * rows).
 */
final class QaMetricsTest extends TestCase
{
    /**
     * density_ratio = unique cited fact ids / narrative word count. Two
     * claims citing the SAME fact still count that fact once.
     */
    public function testDensityRatioCountsUniqueCitationsOverWordCount(): void
    {
        $fact = FactTestFactory::a1cTrendPoint();

        $claims = [
            new Claim('alpha bravo charlie', ClaimType::LabValue, [$fact->factId], [7.2], [], 0),
            new Claim('delta echo', ClaimType::LabValue, [$fact->factId], [], [], 1),
        ];

        // 3 words + 2 words = 5 words total; the SAME fact cited by both
        // claims counts once -- 1 unique cited fact / 5 words.
        self::assertEqualsWithDelta(1 / 5, QaMetrics::densityRatio($claims), 0.0001);
    }

    public function testDensityRatioIsZeroForNoClaims(): void
    {
        self::assertSame(0.0, QaMetrics::densityRatio([]));
    }

    /**
     * Guards a degenerate case: claims exist but every one is a
     * zero-citation conversational claim with empty text word count of zero
     * would be impossible (Claim rejects empty text) -- this asserts the
     * more realistic all-uncited-but-nonzero-word-count shape instead:
     * greeting-only claims still count toward word length but cite nothing,
     * so density is 0 (numerator 0), not a division error.
     */
    public function testDensityRatioIsZeroWhenNothingIsCited(): void
    {
        $claims = [new Claim('Good morning, doctor', ClaimType::Greeting, [], [], [], 0)];

        self::assertSame(0.0, QaMetrics::densityRatio($claims));
    }

    /**
     * fact_utilization_rate is defined (verbatim, build-notes.md) as the
     * UNCITED fraction: 3 facts, 1 cited => 2/3 left uncited.
     */
    public function testFactUtilizationRateIsTheUncitedFraction(): void
    {
        $cited = FactTestFactory::a1cTrendPoint(pid: 1, resultPk: 1);
        $uncitedA = FactTestFactory::a1cTrendPoint(pid: 1, resultPk: 2, raw: '7.5');
        $uncitedB = FactTestFactory::medEvent(pid: 1, rxPk: 3);

        $claims = [new Claim('A1c is 7.2%', ClaimType::LabValue, [$cited->factId], [7.2], [], 0)];

        self::assertEqualsWithDelta(
            2 / 3,
            QaMetrics::factUtilizationRate([$cited, $uncitedA, $uncitedB], $claims),
            0.0001,
        );
    }

    public function testFactUtilizationRateIsZeroWhenAllFactsAreCited(): void
    {
        $fact = FactTestFactory::a1cTrendPoint();
        $claims = [new Claim('A1c is 7.2%', ClaimType::LabValue, [$fact->factId], [7.2], [], 0)];

        self::assertSame(0.0, QaMetrics::factUtilizationRate([$fact], $claims));
    }

    public function testFactUtilizationRateIsZeroWhenThereAreNoFacts(): void
    {
        self::assertSame(0.0, QaMetrics::factUtilizationRate([], []));
    }

    /**
     * A degraded (facts-only) target has facts but no claims at all --
     * everything extracted is, by definition, uncited.
     */
    public function testFactUtilizationRateIsOneWhenThereAreFactsButNoClaims(): void
    {
        $fact = FactTestFactory::a1cTrendPoint();

        self::assertSame(1.0, QaMetrics::factUtilizationRate([$fact], []));
    }
}
