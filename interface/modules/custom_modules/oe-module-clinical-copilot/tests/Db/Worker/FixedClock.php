<?php

/**
 * A PSR-20 clock stub that always reports one fixed instant -- for U9 worker evals.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Worker;

use Psr\Clock\ClockInterface;

/**
 * {@see \OpenEMR\Modules\ClinicalCopilot\Worker} depends on {@see ClockInterface}
 * (CLAUDE.md: "Clock injection (PSR-20) ... instead of calling
 * `new \DateTimeImmutable()` or `time()` directly") rather than the real
 * system clock, precisely so tests can pin "now" and make the T22 T-5min
 * appointment-cutoff math deterministic.
 */
final readonly class FixedClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
