<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\AI\Observability;

use OpenEMR\Services\AI\Observability\TokenPricing;
use OpenEMR\Services\AI\Observability\TokenUsage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
final class TokenPricingTest extends TestCase
{
    public function testEstimatesKnownModelCost(): void
    {
        $pricing = new TokenPricing();

        // gpt-4o-mini: input 0.15/1M, output 0.60/1M.
        $cost = $pricing->estimateCostUsd('gpt-4o-mini', new TokenUsage(1_000_000, 1_000_000, 2_000_000));

        $this->assertNotNull($cost);
        $this->assertEqualsWithDelta(0.75, $cost, 1e-9);
    }

    public function testModelLookupIsCaseInsensitive(): void
    {
        $pricing = new TokenPricing();

        $this->assertTrue($pricing->hasRateFor('GPT-4O-MINI'));
        $this->assertNotNull($pricing->estimateCostUsd('GPT-4O-MINI', new TokenUsage(100, 0, 100)));
    }

    public function testUnknownModelReturnsNull(): void
    {
        $pricing = new TokenPricing();

        $this->assertFalse($pricing->hasRateFor('some-local-model'));
        $this->assertNull($pricing->estimateCostUsd('some-local-model', new TokenUsage(100, 100, 200)));
    }

    public function testCustomRatesOverrideDefaults(): void
    {
        $pricing = new TokenPricing(['custom-model' => [1.0, 2.0]]);

        // 1M input * 1.0 + 1M output * 2.0 = 3.0.
        $cost = $pricing->estimateCostUsd('custom-model', new TokenUsage(1_000_000, 1_000_000, 2_000_000));

        $this->assertEqualsWithDelta(3.0, $cost, 1e-9);
    }
}
