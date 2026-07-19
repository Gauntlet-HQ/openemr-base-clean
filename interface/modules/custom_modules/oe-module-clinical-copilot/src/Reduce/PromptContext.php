<?php

/**
 * Per-request metadata that shapes a reduce prompt without being a Fact itself.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

/**
 * `promptVersion` is a digest input (ARCHITECTURE_COMPLETE.md "Compute
 * model"): bump it on any change to the system instructions or
 * {@see Claim::jsonSchema()} so every affected doc regenerates (E5).
 * `model` is the pinned Gemini version string (T18) -- also folded into
 * `promptVersion` by the caller building it, not by this class.
 *
 * `maxOutputTokens` caps *total* generation, and on Gemini 2.5 (`-pro` and
 * `-flash` alike) that budget includes the model's internal reasoning
 * ("thinking") tokens, which are emitted before the visible claim JSON. At the
 * former 8192 ceiling a long reasoning pass could exhaust the budget and
 * truncate the JSON mid-array, failing the V1 schema gate on every affected
 * turn. The ceiling is a cap, not a target (only tokens actually produced are
 * billed), so it is set generously to guarantee headroom for reasoning + a
 * multi-claim array; well within Gemini 2.5's 65536 output limit.
 */
final readonly class PromptContext
{
    public function __construct(
        public string $docType,
        public string $promptVersion,
        public string $model = 'gemini-2.5-pro',
        public float $temperature = 0.0,
        public int $maxOutputTokens = 24576,
        // Cap on Gemini 2.5 "thinking" tokens. Because generateContent is
        // non-streaming, unbounded (dynamic) thinking makes the model sit for
        // 20-30s returning nothing, which surfaces as a stall/timeout. These
        // are deterministic (temperature 0), schema-constrained, explicitly
        // grounded extractions -- they need a little reasoning, not a lot -- so
        // a modest cap keeps turns fast. 512 is valid for both -pro (min 128)
        // and -flash (0-24576); raise it if the verification-degrade rate
        // climbs. A future per-office control-panel knob (see PromptFactWindow).
        public ?int $thinkingBudget = 512,
    ) {
        if ($this->docType === '') {
            throw new \DomainException('PromptContext.docType must not be empty');
        }

        if ($this->promptVersion === '') {
            throw new \DomainException('PromptContext.promptVersion must not be empty');
        }
    }
}
