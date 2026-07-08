<?php

/**
 * A row read back from mod_copilot_doc, typed.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Doc;

/**
 * Returned by {@see \OpenEMR\Modules\ClinicalCopilot\DocStore::findBest()}.
 * Read-only by construction (this class has no setters and DocStore has no
 * UPDATE path, E7) -- callers that want a "new" row insert a fresh
 * {@see NewDoc} instead of mutating one of these.
 */
final readonly class DocRow
{
    /**
     * @param array<string, mixed> $doc
     * @param array<string, string> $capabilityVersions
     * @param array<string, int>|null $excludedCounts
     */
    public function __construct(
        public int $id,
        public int $pid,
        public string $factDigest,
        public string $docType,
        public ?int $apptId,
        public array $doc,
        public array $capabilityVersions,
        public string $promptVersion,
        public \DateTimeImmutable $computedAt,
        public string $correlationId,
        public ?int $llmLatencyMs,
        public ?int $tokensIn,
        public ?int $tokensOut,
        public ?float $costUsd,
        public ?array $excludedCounts,
        public QaStatus $qaStatus,
        public ?float $qaScore,
        public RegenReason $regenReason,
        public VerifyStatus $verifyStatus,
    ) {
    }
}
