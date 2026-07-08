<?php

/**
 * Content-addressing digest over a fact set and its version inputs.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

/**
 * `digest = hash(canonical_facts ‖ capability_versions ‖ config/cadence
 * version ‖ code-set version ‖ doc_type ‖ reduce prompt+schema version)`
 * (ARCHITECTURE_COMPLETE.md "Compute model", T5). This IS the freshness
 * mechanism (I1/I4): no timestamps ever participate. Every version input is
 * a caller-supplied parameter, never read from a clock or a global -- so a
 * config bump (E5) or a fact change (E1-E3) always changes the digest, and
 * an irrelevant change (E4) never does.
 *
 * Pure and deterministic (E6): same facts + same versions, in any order,
 * always produce the same digest.
 */
final class Digest
{
    private function __construct()
    {
        // static-only
    }

    /**
     * @param list<Fact> $facts recomputed fresh at read time (I2); never a cached fact set
     * @param array<string, string> $capabilityVersions capability => capability_version, one entry per capability that contributed a fact
     * @param array<string, string> $configVersions cadence/threshold/unit-conversion/code-set config rows, keyed by their `mod_copilot_cadence.code_set` (or an equivalent stable key); every version that participated in producing $facts must be listed here (E5)
     * @param string $codeSetVersion version of the LOINC/analyte code-set mapping itself (distinct from per-analyte cadence/threshold versions)
     * @param string $docType digest input from v1 onward (T13) so future doc shapes coexist in one ledger with independent invalidation
     * @param string $promptVersion reduce prompt + response-schema version (a no-op for facts-only reads, but still composed in so a prompt/schema bump invalidates every doc of that doc_type)
     */
    public static function compute(
        array $facts,
        array $capabilityVersions,
        array $configVersions,
        string $codeSetVersion,
        string $docType,
        string $promptVersion,
    ): string {
        $envelope = [
            'facts' => CanonicalSerializer::canonicalizeFacts($facts),
            'capability_versions' => $capabilityVersions,
            'config_versions' => $configVersions,
            'code_set_version' => $codeSetVersion,
            'doc_type' => $docType,
            'prompt_version' => $promptVersion,
        ];

        return hash('sha256', CanonicalSerializer::serializeValue($envelope));
    }
}
