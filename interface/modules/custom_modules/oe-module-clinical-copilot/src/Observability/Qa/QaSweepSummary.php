<?php

/**
 * The return value of QaReviewer::sweep().
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Qa;

final readonly class QaSweepSummary
{
    /**
     * @param list<QaSweepOutcome> $outcomes
     */
    public function __construct(
        public int $swept,
        public int $ok,
        public int $low,
        public int $unavailable,
        public int $errors,
        public array $outcomes,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, []);
    }

    /**
     * @return list<QaSweepOutcome> doc-target outcomes only (U9's T22 rerun
     *         decision has nothing to do with chat_turn outcomes)
     */
    public function docOutcomes(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (QaSweepOutcome $o): bool => $o->targetType === QaTargetType::Doc,
        ));
    }
}
