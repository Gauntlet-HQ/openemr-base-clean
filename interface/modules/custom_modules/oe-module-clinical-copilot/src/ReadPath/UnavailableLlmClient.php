<?php

/**
 * LlmClientInterface implementation for "no credentials configured" (I6 default).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;

/**
 * docs/build-notes.md ("LLM platform"): "the module must degrade cleanly
 * with NO credentials configured (dev/test default): the LLM client detects
 * missing ADC and reports 'unavailable'." {@see VertexLlmClient}'s
 * constructor throws a plain {@see \DomainException} on an empty
 * `projectId`/`location` -- the right behavior for a genuine configuration
 * bug, but the WRONG one for "nobody has set up Vertex yet," which must
 * degrade through the normal I6 path, not crash the read path. {@see LlmClientFactory}
 * therefore never constructs {@see VertexLlmClient} with empty config; it
 * hands back this class instead, which always reports unavailable via the
 * same {@see LlmUnavailableException} contract every other implementation
 * uses -- {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer} cannot tell
 * the difference between "no ADC" and "no Vertex configured at all."
 *
 * T23 (build-notes.md "dev/test Gemini API-key fast-path"): this is also
 * the outcome when the dev/test `CLINICAL_COPILOT_GEMINI_API_KEY` fast-path
 * is unset too -- see {@see LlmClientFactory}'s three-way precedence. This
 * class's message is the last thing standing when NOTHING is configured
 * (the default in this environment).
 */
final class UnavailableLlmClient implements LlmClientInterface
{
    public function generateStructured(PromptRequest $req): LlmResponse
    {
        throw LlmUnavailableException::noCredentials(new \RuntimeException(
            'Clinical Co-Pilot: no LLM provider configured in this environment '
            . '(neither CLINICAL_COPILOT_GCP_PROJECT_ID for Vertex AI production '
            . 'nor CLINICAL_COPILOT_GEMINI_API_KEY for the dev/test fast-path is set)'
        ));
    }
}
