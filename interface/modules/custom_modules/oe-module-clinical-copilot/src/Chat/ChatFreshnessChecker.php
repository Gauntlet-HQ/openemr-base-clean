<?php

/**
 * T19's per-turn freshness check: cheap, LLM-free digest recompute, never a re-seed.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityInterface;
use OpenEMR\Modules\ClinicalCopilot\Fact\Digest;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\LabContractConfigProviderInterface;
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\LabTurnaroundConfigProviderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\ConfigVersionSnapshot;

/**
 * docs/clinical-copilot-tradeoffs.md T19: "every turn runs the cheap half of
 * the machinery -- fact re-extraction + digest compare ... on drift, the
 * answer renders under a visible banner ... one-click re-seed." This class
 * is exactly that cheap half: re-extract all five capabilities fresh (I2)
 * and recompute the SAME digest {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath}
 * computes on the read path, then compare against the session's
 * `fact_digest` (its value AT PRELOAD TIME, {@see ChatSession::$factDigest}).
 * No LLM call, no DocStore lookup, no cache write -- purely a comparison.
 *
 * The three digest-input constants below (`CODE_SET_VERSION`/`DOC_TYPE`/
 * `PROMPT_VERSION`+model) MUST match {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath}'s
 * own private constants exactly, or this check would report false drift on
 * every turn. They are duplicated here rather than reused because
 * `SynthesisReadPath` does not expose them (U8's file, and none of its
 * public methods compute a digest without ALSO attempting a cache lookup and
 * a possible LLM reduce -- exactly the "do NOT re-run the read-path
 * orchestrator mid-turn" the build brief warns against). A follow-up
 * refactor worth doing in a later unit: hoist these three constants into one
 * shared `DigestInputs` class both `SynthesisReadPath` and this class defer
 * to, so they can never drift apart by construction instead of by code
 * review.
 *
 * A capability crash during this check is treated as "cannot determine
 * freshness this turn" -- it does NOT block the chat turn (the turn still
 * answers from what it has) and does NOT itself raise a T19 banner; the
 * next turn tries again. Surfacing this specific failure mode to U12's
 * telemetry is a documented, accepted gap (see the build report).
 */
final class ChatFreshnessChecker
{
    private const CODE_SET_VERSION = '1';
    private const DOC_TYPE = 'endo-previsit-v1';
    private const PROMPT_VERSION = 'reduce-v1';

    private static function model(): string
    {
        return LlmRuntimeConfig::reduceAndChatModel();
    }

    /**
     * @param list<CapabilityInterface> $capabilities all five, same set {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath} runs
     */
    public function __construct(
        private readonly array $capabilities,
        private readonly LabContractConfigProviderInterface $labContractConfigProvider,
        private readonly LabTurnaroundConfigProviderInterface $labTurnaroundConfigProvider,
    ) {
    }

    /**
     * Returns true when the freshly recomputed digest for `$pid` differs
     * from `$sessionFactDigest` (drift => render the T19 banner); false when
     * unchanged OR when freshness could not be determined this turn (a
     * capability crash -- see this class's docblock).
     */
    public function hasDrifted(int $pid, string $sessionFactDigest): bool
    {
        $allFacts = [];
        $capabilityVersions = [];

        foreach ($this->capabilities as $capability) {
            try {
                $result = $capability->extract($pid);
            } catch (\Throwable) {
                return false;
            }
            $capabilityVersions[$capability->capability()->value] = $capability->capabilityVersion();
            $allFacts = [...$allFacts, ...$result->allFacts()];
        }

        $configVersions = ConfigVersionSnapshot::build(
            $this->labContractConfigProvider->load(),
            $this->labTurnaroundConfigProvider->load(),
        );

        $currentDigest = Digest::compute(
            $allFacts,
            $capabilityVersions,
            $configVersions,
            self::CODE_SET_VERSION,
            self::DOC_TYPE,
            self::PROMPT_VERSION . '+' . self::model(),
        );

        return $currentDigest !== $sessionFactDigest;
    }
}
