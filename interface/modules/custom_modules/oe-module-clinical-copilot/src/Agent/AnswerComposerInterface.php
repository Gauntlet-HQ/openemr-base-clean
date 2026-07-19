<?php

/**
 * The seam through which the supervisor's gathered material becomes a draft answer.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

use OpenEMR\Modules\ClinicalCopilot\Ingest\ParsedExtraction;
use OpenEMR\Modules\ClinicalCopilot\Rag\EvidenceSnippet;

/**
 * Composes a draft answer (§2.1 claims JSON + the facts it may cite) from
 * what the supervisor's workers gathered. Implementations may be LLM-backed
 * (the production wiring) or deterministic stubs (tests); either way the
 * draft is NEVER emitted directly -- the supervisor always routes it through
 * the {@see CriticWorker} hard gate first, and a rejected draft degrades to
 * a refusal ({@see AnswerStatus}).
 */
interface AnswerComposerInterface
{
    /**
     * Returns null when there is nothing to answer (e.g. a document-only
     * request with no question); the supervisor then skips the critic stage
     * entirely rather than verifying an empty draft.
     *
     * @param list<EvidenceSnippet> $evidence
     */
    public function compose(AgentRequest $request, ?ParsedExtraction $extraction, array $evidence): ?ComposedAnswer;
}
