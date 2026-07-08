<?php

/**
 * A hand-written ChatLlmClientInterface stub returning a fixed sequence of responses.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat;

use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmRequest;
use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;

/**
 * Mirrors `tests/Isolated/Verify/QueuedLlmClient.php`'s pattern one layer up
 * (tool-calling rounds instead of one-shot reduce): entry `0` is what
 * {@see \OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop}'s first round gets,
 * entry `1` the second, and so on. {@see self::down()} makes every call
 * throw {@see LlmUnavailableException} instead (the I6 degradation path).
 * Calling past the end of a finite sequence throws -- deliberately, so a
 * test asserting "the loop stops after N rounds" fails loudly if the
 * implementation asks for one round too many.
 */
final class QueuedChatLlmClient implements ChatLlmClientInterface
{
    private int $callIndex = 0;

    /** @var list<ChatLlmRequest> */
    private array $calls = [];

    /**
     * @param list<ChatLlmResponse> $sequence
     */
    private function __construct(
        private readonly array $sequence,
        private readonly bool $down,
    ) {
    }

    /**
     * @param list<ChatLlmResponse> $sequence
     */
    public static function up(array $sequence): self
    {
        return new self($sequence, false);
    }

    public static function down(): self
    {
        return new self([], true);
    }

    public function converse(ChatLlmRequest $req): ChatLlmResponse
    {
        $this->calls[] = $req;

        if ($this->down) {
            throw LlmUnavailableException::noCredentials(new \RuntimeException('stub: no ADC in this environment'));
        }

        if (!array_key_exists($this->callIndex, $this->sequence)) {
            throw new \LogicException('QueuedChatLlmClient: sequence exhausted -- an unexpected extra round was requested');
        }

        return $this->sequence[$this->callIndex++];
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /**
     * @return list<ChatLlmRequest>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function lastCall(): ?ChatLlmRequest
    {
        return $this->calls[array_key_last($this->calls)] ?? null;
    }
}
