<?php

/**
 * The reversible token <-> identifier mapping for one session's egress redaction.
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
 * Produced by {@see Redactor::redactPrompt()}, held by the caller for the
 * lifetime of the reduce attempt, and handed back to
 * {@see Redactor::rehydrate()} AFTER verification to restore the real
 * identifiers in the rendered answer (ARCHITECTURE.md §4). Never persisted
 * to `mod_copilot_doc`/`mod_copilot_chat_turn` (out of U7's scope) -- U8/U11
 * own how long a map needs to live for a given surface.
 */
final readonly class RedactionMap
{
    /**
     * @param array<string, string> $tokenByField field name (name|mrn|dob|address) => token
     * @param array<string, string> $valueByToken token => original raw value
     */
    public function __construct(
        public string $sessionId,
        public array $tokenByField,
        public array $valueByToken,
    ) {
    }

    public function tokenFor(string $field): ?string
    {
        return $this->tokenByField[$field] ?? null;
    }
}
