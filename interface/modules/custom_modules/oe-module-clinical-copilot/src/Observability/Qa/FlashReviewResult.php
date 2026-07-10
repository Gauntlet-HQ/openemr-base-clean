<?php

/**
 * One Gemini Flash second-pass review outcome (ARCHITECTURE.md §2.5).
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
 * Discriminated by `status`: `ok` => `concurs`/`salienceOk`/`flags` are
 * meaningful and a real Flash call happened (`model`/token/cost fields set);
 * `unavailable` => no ADC/LLM configured, the honest degraded default
 * (docs/build-notes.md: "When ADC/LLM is unavailable it writes
 * status='unavailable' and moves on"); `error` => a call was attempted but
 * the response could not be parsed/used -- also advisory-safe (never blocks
 * anything), just distinguished from `unavailable` for dashboard triage.
 */
final readonly class FlashReviewResult
{
    /**
     * @param list<QaFlag> $flags
     */
    private function __construct(
        public string $status,
        public ?bool $concurs,
        public ?bool $salienceOk,
        public array $flags,
        public ?string $reviewerNote,
        public ?string $model,
        public ?int $tokensIn,
        public ?int $tokensOut,
        public ?float $costUsd,
    ) {
    }

    /**
     * @param list<QaFlag> $flags
     */
    public static function ok(bool $concurs, bool $salienceOk, array $flags, string $reviewerNote, string $model, int $tokensIn, int $tokensOut, ?float $costUsd): self
    {
        return new self('ok', $concurs, $salienceOk, $flags, $reviewerNote, $model, $tokensIn, $tokensOut, $costUsd);
    }

    public static function unavailable(): self
    {
        return new self('unavailable', null, null, [], null, null, null, null, null);
    }

    public static function error(string $reviewerNote): self
    {
        return new self('error', null, null, [], $reviewerNote, null, null, null, null);
    }
}
