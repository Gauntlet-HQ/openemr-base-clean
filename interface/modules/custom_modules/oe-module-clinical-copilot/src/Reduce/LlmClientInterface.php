<?php

/**
 * The one seam between the reduce pass and any concrete LLM provider.
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
 * {@see Reducer} depends on this interface, never on {@see VertexLlmClient}
 * directly -- every isolated test in `tests/Isolated/Reduce` and
 * `tests/Isolated/Verify` binds a hand-written stub implementation instead
 * (docs/build-notes.md: "No live LLM calls anywhere in tests"). Implementations
 * MUST throw {@see LlmUnavailableException} rather than return a degraded
 * response -- this is what lets {@see Reducer} distinguish "the model said
 * nothing citable" (a normal generation the verifier gates) from "there is no
 * model to ask" (I6 degradation), which are structurally different outcomes.
 */
interface LlmClientInterface
{
    /**
     * Issues one structured-output generation call. Implementations MUST
     * request provider-enforced constrained decoding against
     * `$req->responseSchema` where the provider supports it (Vertex
     * `responseMimeType: application/json` + `responseSchema`, ARCHITECTURE.md
     * "LLM platform") -- client-side reject-and-retry (U10's V1) remains the
     * backstop, never the primary mechanism.
     *
     * @throws LlmUnavailableException when credentials cannot be resolved
     *         (no ADC in this environment) or the provider endpoint cannot be
     *         reached -- the default outcome in dev/test, per build-notes.md's
     *         "LLM platform" section: "the module must degrade cleanly with
     *         NO credentials configured."
     */
    public function generateStructured(PromptRequest $req): LlmResponse;
}
