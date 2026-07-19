<?php

/**
 * Input DTO for QaStore::insert() -- one new mod_copilot_qa verdict row.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Qa;

/**
 * One row per `(target_type, target_id)`, enforced at the DB layer by the
 * table's own UNIQUE key (idempotent sweep) and defended in application code
 * by {@see QaStore::existsFor()} before ever building one of these.
 */
final readonly class NewQaVerdict
{
    /**
     * @param list<QaFlag> $flags
     */
    public function __construct(
        public QaTargetType $targetType,
        public int $targetId,
        public string $correlationId,
        public int $pid,
        public ?int $userId,
        public ?string $model,
        public ?bool $concurs,
        public ?bool $salienceOk,
        public array $flags,
        public float $densityRatio,
        public float $factUtilizationRate,
        public ?string $reviewerNote,
        public ?int $tokensIn,
        public ?int $tokensOut,
        public ?float $costUsd,
        public string $status,
    ) {
        if ($this->pid <= 0) {
            throw new \DomainException("NewQaVerdict.pid must be positive, got {$this->pid}");
        }

        if (!in_array($this->status, ['ok', 'unavailable', 'error'], true)) {
            throw new \DomainException("NewQaVerdict.status must be one of ok|unavailable|error, got {$this->status}");
        }
    }
}
