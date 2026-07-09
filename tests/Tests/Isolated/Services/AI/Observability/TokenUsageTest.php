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

use OpenEMR\Services\AI\Observability\TokenUsage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
final class TokenUsageTest extends TestCase
{
    public function testFromResponseReadsUsageBlock(): void
    {
        $usage = TokenUsage::fromResponse([
            'usage' => [
                'prompt_tokens' => 40,
                'completion_tokens' => 10,
                'total_tokens' => 50,
            ],
        ]);

        $this->assertSame(40, $usage->promptTokens);
        $this->assertSame(10, $usage->completionTokens);
        $this->assertSame(50, $usage->totalTokens);
        $this->assertFalse($usage->isEmpty());
    }

    public function testFromResponseDerivesTotalWhenMissing(): void
    {
        $usage = TokenUsage::fromResponse([
            'usage' => [
                'prompt_tokens' => 40,
                'completion_tokens' => 10,
            ],
        ]);

        $this->assertSame(50, $usage->totalTokens);
    }

    public function testFromResponseWithoutUsageIsEmpty(): void
    {
        $usage = TokenUsage::fromResponse(['choices' => []]);

        $this->assertTrue($usage->isEmpty());
    }

    public function testRejectsNegativeCounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TokenUsage(-1, 0, 0);
    }
}
