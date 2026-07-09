<?php

/**
 * FactDisplayFormatter: human-readable labels for doc fact rows.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\ReadPath\FactDisplayFormatter;
use PHPUnit\Framework\TestCase;

final class FactDisplayFormatterTest extends TestCase
{
    public function testFlagLabelsAreHumanReadable(): void
    {
        self::assertSame('Out of range', FactDisplayFormatter::flagLabel('out_of_range_by_value'));
        self::assertSame('Superseded (2 older)', FactDisplayFormatter::flagLabel('superseded_2'));
        self::assertSame('Missing units', FactDisplayFormatter::flagLabel('excluded_reason:unitless'));
    }

    public function testCapabilityAndKindLabelsAreHumanReadable(): void
    {
        self::assertSame('Diabetes control (A1c)', FactDisplayFormatter::capabilityLabel('control_proxy'));
        self::assertSame('Trend point', FactDisplayFormatter::kindLabel('trend_point'));
        self::assertSame('Excluded', FactDisplayFormatter::statusLabel('excluded'));
    }
}
