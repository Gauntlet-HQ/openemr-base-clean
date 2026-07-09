<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\AI;

use OpenEMR\Services\AI\DiagnosisSuggestionResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
final class DiagnosisSuggestionResultTest extends TestCase
{
    public function testInsufficientDataResult(): void
    {
        $result = DiagnosisSuggestionResult::insufficientData();

        $this->assertFalse($result->hasSufficientData);
        $this->assertSame('insufficient data', $result->suggestions);
        $this->assertSame(
            ['hasSufficientData' => false, 'suggestions' => 'insufficient data'],
            $result->toArray()
        );
    }

    public function testFromSuggestionsResult(): void
    {
        $result = DiagnosisSuggestionResult::fromSuggestions('1. Community-acquired pneumonia.');

        $this->assertTrue($result->hasSufficientData);
        $this->assertSame('1. Community-acquired pneumonia.', $result->suggestions);
        $this->assertSame(
            ['hasSufficientData' => true, 'suggestions' => '1. Community-acquired pneumonia.'],
            $result->toArray()
        );
    }
}
