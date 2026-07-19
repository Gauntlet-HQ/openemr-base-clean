<?php

/**
 * A hand-written LlmClientInterface stub returning a fixed sequence of responses.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Verify;

use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;

/**
 * Exercises {@see \OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGeneration}'s
 * two-attempt loop: entry `0` is what the first reduce call gets, entry `1`
 * is what a regeneration (if one happens) gets. Calling past the end of the
 * sequence throws -- deliberately, so a test asserting "no retry occurs"
 * (e.g. the V3 sev-1 path) fails loudly if the implementation retries when
 * it must not.
 */
final class QueuedLlmClient implements LlmClientInterface
{
    private int $callIndex = 0;

    /** @var list<PromptRequest> */
    private array $calls = [];

    /**
     * @param list<LlmResponse> $sequence
     */
    public function __construct(private readonly array $sequence)
    {
    }

    public function generateStructured(PromptRequest $req): LlmResponse
    {
        $this->calls[] = $req;

        if (!array_key_exists($this->callIndex, $this->sequence)) {
            throw new \LogicException('QueuedLlmClient: sequence exhausted -- an unexpected extra call was made');
        }

        return $this->sequence[$this->callIndex++];
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /**
     * @return list<PromptRequest>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
