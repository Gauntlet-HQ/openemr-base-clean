<?php

/**
 * What SynthesisReadPath::read()/regenerate() hands back to the controller/template.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Doc\QaStatus;
use OpenEMR\Modules\ClinicalCopilot\Doc\RegenReason;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verdict;

/**
 * `facts` is ALWAYS the freshly re-extracted set (I2 -- facts are never
 * cached, only narratives are), whether this attempt was a cache hit, a
 * fresh generation, or a capability crash. `claims`/`verifyStatus`/etc. come
 * from the SERVED doc row (which may be older than this read's own facts,
 * on a hit -- staleness is cache addressing, not a check, I1).
 */
final readonly class SynthesisReadResult
{
    /**
     * @param list<Fact> $facts
     * @param list<Claim>|null $claims
     * @param list<Verdict> $verdicts
     */
    private function __construct(
        public string $correlationId,
        public int $pid,
        public bool $capabilityCrash,
        public ?string $crashBanner,
        public array $facts,
        public ?string $factDigest,
        public ?VerifyStatus $verifyStatus,
        public ?RegenReason $regenReason,
        public ?array $claims,
        public ?string $degradedReason,
        public ?string $degradedMessage,
        public array $verdicts,
        public int $attempts,
        public bool $servedFromCache,
        public ?\DateTimeImmutable $computedAt,
        public ?QaStatus $qaStatus,
        public ?float $qaScore,
        public ?int $docId,
    ) {
    }

    /**
     * @param list<Fact> $survivingFacts
     */
    public static function capabilityCrash(string $correlationId, int $pid, array $survivingFacts, string $banner): self
    {
        return new self(
            $correlationId,
            $pid,
            true,
            $banner,
            $survivingFacts,
            null,
            null,
            null,
            null,
            null,
            null,
            [],
            0,
            false,
            null,
            null,
            null,
            null,
        );
    }

    /**
     * Cache miss when the caller forbids background LLM (worker warm). Facts
     * are fresh (I2); narration is deferred until a user-facing read.
     *
     * @param list<Fact> $facts
     */
    public static function cacheMissLlmDeferred(
        string $correlationId,
        int $pid,
        array $facts,
        string $factDigest,
    ): self {
        return new self(
            $correlationId,
            $pid,
            false,
            null,
            $facts,
            $factDigest,
            null,
            null,
            null,
            null,
            null,
            [],
            0,
            false,
            null,
            null,
            null,
            null,
        );
    }

    /**
     * @param list<Fact> $facts fresh facts, recomputed this read (I2)
     * @param list<Claim>|null $claims from the served doc row's payload
     * @param list<Verdict> $verdicts from the served doc row's payload
     */
    public static function served(
        string $correlationId,
        int $pid,
        array $facts,
        string $factDigest,
        VerifyStatus $verifyStatus,
        RegenReason $regenReason,
        ?array $claims,
        ?string $degradedReason,
        ?string $degradedMessage,
        array $verdicts,
        int $attempts,
        bool $servedFromCache,
        \DateTimeImmutable $computedAt,
        QaStatus $qaStatus,
        ?float $qaScore,
        int $docId,
    ): self {
        return new self(
            $correlationId,
            $pid,
            false,
            null,
            $facts,
            $factDigest,
            $verifyStatus,
            $regenReason,
            $claims,
            $degradedReason,
            $degradedMessage,
            $verdicts,
            $attempts,
            $servedFromCache,
            $computedAt,
            $qaStatus,
            $qaScore,
            $docId,
        );
    }
}
