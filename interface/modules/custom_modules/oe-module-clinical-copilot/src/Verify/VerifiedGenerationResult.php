<?php

/**
 * The final, single outcome VerifiedGeneration hands back to its caller.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

use OpenEMR\Modules\ClinicalCopilot\Doc\RegenReason;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\RedactionMap;

/**
 * Reuses U6's own {@see VerifyStatus} (`passed`/`degraded`) and
 * {@see RegenReason} enums rather than minting parallel ones -- this result
 * maps directly onto the `mod_copilot_doc.verify_status`/`regen_reason`
 * columns U8 writes and the analogous fields U11 persists on
 * `mod_copilot_chat_turn.verification_verdict` (ARCHITECTURE_COMPLETE.md
 * "Module-owned tables").
 *
 * Exactly one of `claims`/`degradedReason` is meaningful, discriminated by
 * `verifyStatus`: `Passed` => `claims` is the verified list and
 * `redactionMap` is set (the caller rehydrates the rendered narrative with
 * it, ARCHITECTURE.md §4, AFTER this result is used to render); `Degraded`
 * => `claims` is null and `degradedReason`/`degradedMessage` explain why
 * (LLM unavailable, I6, or verification failed twice, I11). `frozen` is
 * V3's sev-1 escape hatch (ARCHITECTURE.md §2.3): when true, `sev1Signal` is
 * always set and the caller (U11) MUST NOT continue the chat session --
 * freeze it and route the signal to U12's alerting.
 *
 * `usage` ({@see ReduceUsage}) carries the LAST attempt's token/latency/model
 * metrics -- present whenever a provider call actually happened (every
 * outcome except {@see VerifiedGenerationResult::degradedLlmUnavailable()},
 * where the LLM was never reached). U8 persists these onto
 * `mod_copilot_doc.llm_latency_ms`/`tokens_in`/`tokens_out`; U11 onto the
 * analogous `mod_copilot_chat_turn` columns.
 */
final readonly class VerifiedGenerationResult
{
    private const REASON_LLM_UNAVAILABLE = 'llm_unavailable';
    private const REASON_VERIFICATION_FAILED = 'verification_failed';

    /**
     * @param list<Claim>|null $claims
     * @param list<Verdict> $verdicts verdicts from the LAST attempt made (empty when the LLM was unavailable on attempt 1)
     */
    private function __construct(
        public VerifyStatus $verifyStatus,
        public RegenReason $regenReason,
        public ?array $claims,
        public array $verdicts,
        public int $attempts,
        public bool $frozen,
        public ?Sev1Signal $sev1Signal,
        public ?string $degradedReason,
        public ?string $degradedMessage,
        public ?RedactionMap $redactionMap,
        public ReduceUsage $usage,
    ) {
    }

    /**
     * @param list<Claim> $claims
     * @param list<Verdict> $verdicts
     */
    public static function passed(array $claims, array $verdicts, int $attempts, RedactionMap $redactionMap, ReduceUsage $usage): self
    {
        return new self(
            VerifyStatus::Passed,
            $attempts > 1 ? RegenReason::VerifyRetry : RegenReason::None,
            $claims,
            $verdicts,
            $attempts,
            false,
            null,
            null,
            null,
            $redactionMap,
            $usage,
        );
    }

    public static function degradedLlmUnavailable(int $attempts): self
    {
        return new self(
            VerifyStatus::Degraded,
            $attempts > 1 ? RegenReason::VerifyRetry : RegenReason::None,
            null,
            [],
            $attempts,
            false,
            null,
            self::REASON_LLM_UNAVAILABLE,
            'narrative unavailable',
            null,
            ReduceUsage::none(),
        );
    }

    /**
     * @param list<Verdict> $verdicts
     */
    public static function degradedVerificationFailed(array $verdicts, int $attempts, string $message, ReduceUsage $usage): self
    {
        return new self(
            VerifyStatus::Degraded,
            RegenReason::VerifyRetry,
            null,
            $verdicts,
            $attempts,
            false,
            null,
            self::REASON_VERIFICATION_FAILED,
            $message,
            null,
            $usage,
        );
    }

    /**
     * @param list<Verdict> $verdicts
     */
    public static function frozen(array $verdicts, int $attempts, Sev1Signal $signal, ReduceUsage $usage): self
    {
        return new self(
            VerifyStatus::Degraded,
            $attempts > 1 ? RegenReason::VerifyRetry : RegenReason::None,
            null,
            $verdicts,
            $attempts,
            true,
            $signal,
            'patient_identity_sev1',
            "couldn't produce a verifiable answer",
            null,
            $usage,
        );
    }
}
