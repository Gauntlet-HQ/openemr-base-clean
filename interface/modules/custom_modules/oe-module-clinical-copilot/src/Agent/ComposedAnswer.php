<?php

/**
 * One composed draft answer awaiting the critic: raw claims JSON plus its grounding fact set.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

/**
 * What an {@see AnswerComposerInterface} hands back to the supervisor: the
 * raw §2.1 claims payload it composed, plus the facts those claims are
 * allowed to cite. The grounding facts become the
 * {@see \OpenEMR\Modules\ClinicalCopilot\Verify\SessionFactSet} the critic
 * ({@see CriticWorker}) resolves `citation_ids` against -- a claim citing
 * anything outside this list is uncited by definition (V2), and a fact whose
 * pid mismatches the request's pid trips the V3 sev-1 freeze.
 */
final readonly class ComposedAnswer
{
    /**
     * @param list<Fact> $groundingFacts
     */
    public function __construct(
        public string $rawClaimsJson,
        public array $groundingFacts,
    ) {
    }
}
