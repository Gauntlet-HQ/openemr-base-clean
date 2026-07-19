<?php

/**
 * Calls the Gemini Flash second-pass reviewer over one target's rendered answer + fact set.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Qa;

use OpenEMR\Modules\ClinicalCopilot\Fact\CanonicalSerializer;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor;

/**
 * ARCHITECTURE.md §2.5 (user decision of record, docs/build-notes.md "U12
 * additions"): "A separate model instance (Gemini Flash) ... re-reads the
 * rendered response against the session fact set from scratch and
 * annotates." T18 pins the model string here, exactly as
 * {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath} pins
 * `gemini-2.5-pro` for the reduce pass.
 *
 * Egress redaction applies here too (ARCHITECTURE.md §4): this is a Vertex
 * call like any other, so direct identifiers are tokenized before the prompt
 * leaves the process, exactly like {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer}'s
 * own redaction step -- the only difference is nothing needs to be
 * rehydrated afterward, because the Flash verdict never quotes the
 * patient's identifiers back (its output is concurrence/flags/notes about
 * claim structure, not prose that repeats patient data).
 *
 * Never throws on a degraded LLM: {@see LlmUnavailableException} is caught
 * and turned into {@see FlashReviewResult::unavailable()} (docs/build-notes.md:
 * "it writes status='unavailable' and moves on -- QA lag is acceptable and
 * honest"). A malformed/unparseable response is caught separately and turned
 * into {@see FlashReviewResult::error()} -- neither ever propagates, because
 * this whole subsystem is advisory and must never be able to affect the
 * serving path (T15).
 */
final class FlashReviewer
{
    public const MODEL = 'gemini-2.5-flash';
    private const PROMPT_VERSION = 'qa-review-v1';

    private const SYSTEM_INSTRUCTIONS = <<<'PROMPT'
        You are an independent second-pass reviewer for a clinical pre-visit
        co-pilot. You did NOT write the narrative below and have no stake in
        it being correct. Your job is purely advisory: read the RENDERED
        NARRATIVE against the SESSION FACTS it was supposed to be grounded in,
        from scratch, and report your own read.

        You are NOT a gate -- nothing you say blocks or changes what the
        physician already saw. Be honest and specific.

        Evaluate exactly two things:
        1. `concurs`: overall, does the narrative's account of the facts match
           what you would say reading the same facts yourself? Consider
           misleading emphasis, ordering, and subtly-wrong paraphrase (not
           just outright factual errors -- those are already caught upstream).
        2. `salience_ok`: is any HIGH-PRIORITY out-of-range or critical fact
           (flagged out-of-range, a conflict, or an urgent overdue/pending
           item) buried instead of appearing near the top of the narrative?
           If so, salience_ok is false.

        For anything you flag, add one entry to `flags`: `claim_ref` (which
        claim, by its position, e.g. "claim 2"), `class` (one of emphasis,
        paraphrase, omission, salience, other), and a one-sentence `note`.

        Output contract: respond with ONLY a JSON object matching the
        supplied response schema: {concurs, salience_ok, flags, reviewer_note}.
        No prose outside the JSON object; no markdown fencing.
        PROMPT;

    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly Redactor $redactor = new Redactor(),
    ) {
    }

    /**
     * @param list<Fact> $facts the target's own stored fact set (T22: "the
     *        SAME stored session fact set", never a fresh re-extraction --
     *        this is a post-mortem review of what was actually served)
     * @param list<Claim> $claims the rendered narrative's claims (empty for a
     *        fully degraded/facts-only target -- callers should not invoke
     *        this at all in that case, see {@see QaReviewer})
     */
    public function review(
        string $reviewSessionId,
        array $facts,
        array $claims,
        PatientIdentifiers $identifiers,
    ): FlashReviewResult {
        $userContent = self::buildUserContent($facts, $claims);

        $request = new PromptRequest(
            self::SYSTEM_INSTRUCTIONS,
            $userContent,
            self::responseSchema(),
            self::MODEL,
            self::PROMPT_VERSION,
        );

        $redacted = $this->redactor->redactPrompt($reviewSessionId, $identifiers, $request);

        try {
            $response = $this->llmClient->generateStructured($redacted->request);
        } catch (LlmUnavailableException) {
            return FlashReviewResult::unavailable();
        }

        return self::parseResponse($response->rawJson, $response->modelVersion, $response->tokensIn, $response->tokensOut);
    }

    /**
     * @param list<Fact> $facts
     * @param list<Claim> $claims
     */
    private static function buildUserContent(array $facts, array $claims): string
    {
        $narrativeLines = [];
        foreach ($claims as $index => $claim) {
            $narrativeLines[] = "claim {$index} ({$claim->claimType->value}): {$claim->text}";
        }

        $sections = [
            '=== SESSION FACTS (canonical JSON -- what the narrative was supposed to be grounded in) ===',
            CanonicalSerializer::serializeFacts($facts),
            '',
            '=== RENDERED NARRATIVE (already shown to the physician; you are reviewing it post-hoc) ===',
            $narrativeLines === [] ? '(no narrative claims -- this target was served facts-only)' : implode("\n", $narrativeLines),
        ];

        return implode("\n", $sections);
    }

    /**
     * @return array<string, mixed>
     */
    private static function responseSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Clinical Co-Pilot QA Review',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['concurs', 'salience_ok', 'flags', 'reviewer_note'],
            'properties' => [
                'concurs' => ['type' => 'boolean'],
                'salience_ok' => ['type' => 'boolean'],
                'flags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['claim_ref', 'class', 'note'],
                        'properties' => [
                            'claim_ref' => ['type' => 'string'],
                            'class' => [
                                'type' => 'string',
                                'enum' => array_map(static fn (QaFlagClass $c): string => $c->value, QaFlagClass::cases()),
                            ],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
                'reviewer_note' => ['type' => 'string'],
            ],
        ];
    }

    private static function parseResponse(string $rawJson, string $model, int $tokensIn, int $tokensOut): FlashReviewResult
    {
        try {
            $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return FlashReviewResult::error('Flash reviewer returned unparseable JSON');
        }

        if (!is_array($decoded) || !is_bool($decoded['concurs'] ?? null) || !is_bool($decoded['salience_ok'] ?? null)) {
            return FlashReviewResult::error('Flash reviewer response missing required fields');
        }

        $flags = [];
        $flagsRaw = $decoded['flags'] ?? [];
        if (is_array($flagsRaw)) {
            foreach ($flagsRaw as $flagData) {
                if (is_array($flagData)) {
                    /** @var array<string, mixed> $flagData */
                    $flag = QaFlag::fromArray($flagData);
                    if ($flag !== null) {
                        $flags[] = $flag;
                    }
                }
            }
        }

        $reviewerNote = $decoded['reviewer_note'] ?? '';

        return FlashReviewResult::ok(
            $decoded['concurs'],
            $decoded['salience_ok'],
            $flags,
            is_string($reviewerNote) ? $reviewerNote : '',
            $model,
            $tokensIn,
            $tokensOut,
            null,
        );
    }
}
