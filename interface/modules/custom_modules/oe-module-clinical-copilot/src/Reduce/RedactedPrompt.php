<?php

/**
 * The output of one Redactor::redactPrompt() call.
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
 * Pairs the tokenized {@see PromptRequest} actually sent to the LLM client
 * with the {@see RedactionMap} needed to reverse it. `request` is guaranteed
 * (by {@see Redactor::redactPrompt()}) to contain zero occurrences of any
 * non-empty identifier value from the {@see PatientIdentifiers} it was built
 * from.
 */
final readonly class RedactedPrompt
{
    public function __construct(
        public PromptRequest $request,
        public RedactionMap $map,
    ) {
    }
}
