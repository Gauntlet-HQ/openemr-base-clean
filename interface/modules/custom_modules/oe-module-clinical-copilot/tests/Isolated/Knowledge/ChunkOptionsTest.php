<?php

/**
 * ChunkOptions — operator-supplied sizing is clamped to a safe band.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Knowledge\ChunkOptions;
use PHPUnit\Framework\TestCase;

/**
 * Since these come straight from a form field / CLI flag, the guard is that no
 * input can drive the chunker into a degenerate state: a zero/huge target, or an
 * overlap that meets or exceeds the target (which would keep a chunk from ever
 * advancing).
 */
final class ChunkOptionsTest extends TestCase
{
    public function testTargetIsClampedToTheAllowedBand(): void
    {
        self::assertSame(ChunkOptions::MIN_TARGET, (new ChunkOptions(1, 0))->targetChars);
        self::assertSame(ChunkOptions::MAX_TARGET, (new ChunkOptions(999999, 0))->targetChars);
        self::assertSame(1000, (new ChunkOptions(1000, 0))->targetChars);
    }

    public function testOverlapNeverReachesHalfTheTargetAndNeverGoesNegative(): void
    {
        $huge = new ChunkOptions(1000, 100000);
        self::assertLessThanOrEqual(500, $huge->overlapChars);
        self::assertGreaterThanOrEqual(0, $huge->overlapChars);

        self::assertSame(0, (new ChunkOptions(1000, -50))->overlapChars);
    }

    public function testDefaultIsWithinTheBand(): void
    {
        $default = ChunkOptions::default();
        self::assertSame(ChunkOptions::DEFAULT_TARGET, $default->targetChars);
        self::assertSame(ChunkOptions::DEFAULT_OVERLAP, $default->overlapChars);
        self::assertLessThan($default->targetChars, $default->overlapChars);
    }
}
