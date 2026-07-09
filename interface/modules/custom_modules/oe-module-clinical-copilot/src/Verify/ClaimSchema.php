<?php

/**
 * V1 -- the schema gate: raw model output must parse against the claim schema.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;

/**
 * ARCHITECTURE.md §2.1/§2.2 (V1): "Free prose without claim structure is
 * schema-rejected before any semantic check runs." This class does exactly
 * that and nothing else -- it does not resolve citations (V2), does not
 * check pids (V3), does not ground numbers (V4); it only answers "is this
 * JSON, is it a list, and does every element satisfy {@see Claim::jsonSchema()}
 * (mirrored here structurally via {@see Claim::fromArray()}, the single
 * source of truth both the schema doc and this parser defer to)."
 *
 * Provider-enforced constrained decoding (Vertex `responseSchema`, T18)
 * makes a V1 rejection rare in practice; this class is the client-side
 * backstop, never the primary mechanism (ARCHITECTURE.md §2.1).
 */
final class ClaimSchema
{
    public function parse(string $rawJson): ClaimParseResult
    {
        try {
            $decoded = json_decode(self::extractJsonPayload($rawJson), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // The raw model output is preserved in the turn/trace payload for
            // debugging; do not reflect the exception text into the verdict,
            // which is user-facing (repo error-handling standard).
            return ClaimParseResult::invalid(['response is not valid JSON']);
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            return ClaimParseResult::invalid(['response must be a JSON array of claim objects']);
        }

        $claims = [];
        $errors = [];
        foreach ($decoded as $index => $item) {
            if (!is_array($item)) {
                $errors[] = "claim[{$index}] is not an object";
                continue;
            }

            try {
                /** @var array<string, mixed> $item */
                $claims[] = Claim::fromArray($item);
            } catch (\InvalidArgumentException) {
                $errors[] = "claim[{$index}] does not conform to the claim schema";
            }
        }

        if ($errors !== []) {
            return ClaimParseResult::invalid($errors);
        }

        return ClaimParseResult::ok($claims);
    }

    /**
     * Extracts the claim-list JSON from the raw model text before decoding.
     *
     * The system prompt asks for a bare JSON array with "no markdown fencing"
     * (ARCHITECTURE.md §2.1), and on the Vertex/Pro path provider-enforced
     * constrained decoding delivers exactly that. But the agent loop's
     * answer-producing round can carry tool declarations, and Gemini's
     * function-calling and `responseSchema` are mutually exclusive -- so that
     * round is unconstrained, and a model (notably the Flash-class dev/test
     * path) will wrap the array in a ```json fence or a short prose preamble.
     * That is precisely the "client-side reject-and-retry backstop"
     * (ARCHITECTURE.md §2.1) this gate exists to be: it unwraps a single
     * surrounding code fence, and -- only when the payload is neither a bare
     * array nor a JSON object -- slices from the first `[` to the last `]` to
     * lift an array embedded in prose.
     *
     * This does NOT relax the schema: whatever survives extraction is still
     * decoded strictly and every element is validated by {@see Claim::fromArray()}.
     * Genuine free prose (no array) still fails, and a JSON object (`{...}`) is
     * left untouched so it is rejected as "not a JSON array of claim objects"
     * exactly as before.
     */
    private static function extractJsonPayload(string $rawJson): string
    {
        $text = trim($rawJson);

        // Unwrap a single surrounding Markdown code fence: ```json\n...\n```
        // (or ``` with no language tag). Only strip a fence that opens the
        // string, so fenced content is not confused with a stray backtick run
        // inside a value.
        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```[A-Za-z0-9_-]*[ \t]*\r?\n?/', '', $text);
            $text = (string) preg_replace('/\r?\n?```[ \t]*$/', '', $text);
            $text = trim($text);
        }

        // If prose surrounds an array, lift the outermost `[...]`. Skip this
        // when the payload already starts as an array (nothing to strip) or as
        // an object (`{...}` must still be rejected as a non-array, not mined
        // for an inner array).
        if (!str_starts_with($text, '[') && !str_starts_with($text, '{')) {
            $start = strpos($text, '[');
            $end = strrpos($text, ']');
            if ($start !== false && $end !== false && $end > $start) {
                $text = substr($text, $start, $end - $start + 1);
            }
        }

        return $text;
    }
}
