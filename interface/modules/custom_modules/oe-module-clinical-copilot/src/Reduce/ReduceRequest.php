<?php

/**
 * One call to Reducer::reduce().
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

/**
 * `sessionId` scopes the Redactor's stable pseudonym tokens (a synthesis read
 * and a chat session are each their own "session" for this purpose -- the
 * caller decides what string identifies one). `priorFindings` is set only on
 * U10's one-and-only regeneration attempt (ARCHITECTURE.md §2.3): the
 * verifier's specific findings from the first attempt, appended to the
 * prompt verbatim by {@see PromptAssembler}.
 */
final readonly class ReduceRequest
{
    /**
     * @param list<Fact> $facts
     */
    public function __construct(
        public string $sessionId,
        public string $correlationId,
        public array $facts,
        public PatientIdentifiers $identifiers,
        public PromptContext $context,
        public ?string $priorFindings = null,
    ) {
        if ($this->sessionId === '') {
            throw new \DomainException('ReduceRequest.sessionId must not be empty');
        }

        if ($this->correlationId === '') {
            throw new \DomainException('ReduceRequest.correlationId must not be empty');
        }
    }
}
