<?php

/**
 * Egress redaction: direct identifiers -> stable per-session pseudonym tokens.
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
 * ARCHITECTURE.md §4: "direct identifiers (name, MRN, DOB, address) are
 * replaced with stable per-session pseudonym tokens before any Vertex call
 * and re-hydrated in the rendered answer after verification." This class is
 * the one place that does both directions.
 *
 * Tokens are derived from `(sessionId, field)` only -- NEVER from the
 * identifier's own value -- so the same field always maps to the same token
 * within one session (stable, as the same physician re-reading the same
 * session should see the same pseudonym throughout a synthesis or
 * conversation) while carrying no information about the value itself. A
 * distinctive delimiter (`⟦token⟧`, mathematical white square
 * brackets -- vanishingly unlikely to appear in chart free-text) keeps
 * {@see self::rehydrate()}'s replacement unambiguous.
 *
 * Honest scope (§4, restated): this redacts the FOUR direct identifiers on
 * {@see PatientIdentifiers}. Quasi-identifiers embedded in Fact values
 * (clinical dates, rare lab values) are NOT touched here -- minimization, not
 * full de-identification.
 */
final class Redactor
{
    private const TOKEN_PREFIX = "\u{27E6}PSN:";
    private const TOKEN_SUFFIX = "\u{27E7}";

    /**
     * Scans `$request`'s system instructions and user content for every
     * non-empty value on `$identifiers` and replaces each occurrence with its
     * stable per-session token. Returns a NEW PromptRequest (this class never
     * mutates its input) plus the {@see RedactionMap} needed to reverse it.
     */
    public function redactPrompt(string $sessionId, PatientIdentifiers $identifiers, PromptRequest $request): RedactedPrompt
    {
        $tokenByField = [];
        $valueByToken = [];
        foreach ($identifiers->nonEmptyFields() as $field => $value) {
            $token = self::tokenFor($sessionId, $field);
            $tokenByField[$field] = $token;
            $valueByToken[$token] = $value;
        }

        $search = array_values($valueByToken);
        $replace = array_keys($valueByToken);

        $redactedRequest = new PromptRequest(
            str_replace($search, $replace, $request->systemInstructions),
            str_replace($search, $replace, $request->userContent),
            $request->responseSchema,
            $request->model,
            $request->promptVersion,
            $request->temperature,
            $request->maxOutputTokens,
            $request->thinkingBudget,
        );

        return new RedactedPrompt($redactedRequest, new RedactionMap($sessionId, $tokenByField, $valueByToken));
    }

    /**
     * Restores every token in `$text` to its original identifier value.
     * Called AFTER verification, on the final rendered answer only
     * (ARCHITECTURE.md §4) -- never on anything shown to the model.
     */
    public function rehydrate(string $text, RedactionMap $map): string
    {
        return str_replace(array_keys($map->valueByToken), array_values($map->valueByToken), $text);
    }

    private static function tokenFor(string $sessionId, string $field): string
    {
        $digest = substr(hash('sha256', $sessionId . '|copilot-egress-redaction|' . $field), 0, 16);

        return self::TOKEN_PREFIX . strtoupper($field) . ':' . $digest . self::TOKEN_SUFFIX;
    }
}
