<?php

/**
 * LlmCostEstimate: rough, observability-only USD estimate for one Gemini call.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Observability;

use OpenEMR\Modules\ClinicalCopilot\Observability\LlmCostEstimate;
use PHPUnit\Framework\TestCase;

/**
 * This is an anomaly-detection aid, not a bill -- the tests pin the wiring
 * (correct rate per model, thinking already folded into tokensOut by the
 * caller, honest null when nothing was metered, priciest-tier fallback for an
 * unknown model) rather than any exact dollar figure.
 */
final class LlmCostEstimateTest extends TestCase
{
    public function testProRate(): void
    {
        // 1M in @ $1.25 + 1M out @ $10.00
        self::assertSame(11.25, LlmCostEstimate::estimateUsd('gemini-2.5-pro', 1_000_000, 1_000_000));
    }

    public function testFlashRate(): void
    {
        // 1M in @ $0.30 + 1M out @ $2.50
        self::assertSame(2.8, LlmCostEstimate::estimateUsd('gemini-2.5-flash', 1_000_000, 1_000_000));
    }

    public function testMatchesOnModelPrefixSoDatedVariantsStillPrice(): void
    {
        self::assertSame(1.25, LlmCostEstimate::estimateUsd('gemini-2.5-pro-002', 1_000_000, 0));
    }

    /**
     * An unknown/renamed model must not read as cheap -- fall back to the
     * priciest known tier so a runaway on a new model is never under-reported.
     */
    public function testUnknownModelFallsBackToPriciestTier(): void
    {
        self::assertSame(10.0, LlmCostEstimate::estimateUsd('gemini-9-ultra', 0, 1_000_000));
    }

    public function testReturnsNullWhenNothingWasMetered(): void
    {
        self::assertNull(LlmCostEstimate::estimateUsd(null, null, null));
        self::assertNull(LlmCostEstimate::estimateUsd('gemini-2.5-pro', null, 5));
        self::assertNull(LlmCostEstimate::estimateUsd('gemini-2.5-pro', 5, null));
    }

    public function testRoundsToSixDecimals(): void
    {
        self::assertSame(
            round((3000 / 1_000_000) * 1.25 + (800 / 1_000_000) * 10.0, 6),
            LlmCostEstimate::estimateUsd('gemini-2.5-pro', 3000, 800),
        );
    }
}
