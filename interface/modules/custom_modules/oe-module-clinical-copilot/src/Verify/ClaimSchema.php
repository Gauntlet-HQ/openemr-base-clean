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
            $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
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
}
