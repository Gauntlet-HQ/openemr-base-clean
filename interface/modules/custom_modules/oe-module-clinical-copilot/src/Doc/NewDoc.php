<?php

/**
 * The input DTO for DocStore::insert() -- one new mod_copilot_doc attempt.
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
 * Bundles every `mod_copilot_doc` column an inserting caller (U7's reduce
 * path, or U12's T22 QA-driven rerun) supplies, so
 * {@see \OpenEMR\Modules\ClinicalCopilot\DocStore::insert()} takes one typed
 * argument instead of an 18-parameter positional call (CLAUDE.md: "Convert
 * shapes to DTOs when they exceed 3-4 keys").
 *
 * `qaStatus`/`qaScore` are NOT constructor parameters here: at insert time
 * (synthesis or rerun) no QA verdict exists yet -- U12's async sweep is the
 * only writer of those two columns, always via a fresh row, never an UPDATE
 * (E7). This DTO therefore always produces a row with
 * `qa_status = 'pending'`, `qa_score = NULL` at insert (the column defaults),
 * which is exactly correct: a brand new attempt has not been QA-swept yet.
 */
final readonly class NewDoc
{
    /**
     * @param array<string, mixed> $doc facts + citations + narrative (or facts-only when $verifyStatus is Degraded, I6)
     * @param array<string, string> $capabilityVersions capability => capability_version, a digest input
     * @param array<string, int>|null $excludedCounts per-analyte exclusion counts incl. unitless-exclusion rate (I5)
     */
    public function __construct(
        public int $pid,
        public string $factDigest,
        public string $docType,
        public ?int $apptId,
        public array $doc,
        public array $capabilityVersions,
        public string $promptVersion,
        public string $correlationId,
        public VerifyStatus $verifyStatus,
        public RegenReason $regenReason,
        public ?int $llmLatencyMs = null,
        public ?int $tokensIn = null,
        public ?int $tokensOut = null,
        public ?float $costUsd = null,
        public ?array $excludedCounts = null,
    ) {
        if ($this->pid <= 0) {
            throw new \DomainException("NewDoc.pid must be positive, got {$this->pid}");
        }

        if ($this->factDigest === '') {
            throw new \DomainException('NewDoc.factDigest must not be empty');
        }

        if ($this->correlationId === '') {
            throw new \DomainException('NewDoc.correlationId must not be empty');
        }
    }
}
