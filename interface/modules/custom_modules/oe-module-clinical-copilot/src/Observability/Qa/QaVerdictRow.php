<?php

/**
 * A row read back from mod_copilot_qa, typed.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Qa;

final readonly class QaVerdictRow
{
    /**
     * @param list<QaFlag> $flags
     */
    public function __construct(
        public int $id,
        public QaTargetType $targetType,
        public int $targetId,
        public string $correlationId,
        public int $pid,
        public ?int $userId,
        public ?string $model,
        public ?bool $concurs,
        public ?bool $salienceOk,
        public array $flags,
        public ?float $densityRatio,
        public ?float $factUtilizationRate,
        public ?string $reviewerNote,
        public ?int $tokensIn,
        public ?int $tokensOut,
        public ?float $costUsd,
        public string $status,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
