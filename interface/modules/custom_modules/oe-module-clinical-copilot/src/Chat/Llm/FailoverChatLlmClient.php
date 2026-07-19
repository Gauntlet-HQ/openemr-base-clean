<?php

/**
 * Failover chat LLM client: try each provider in order until one succeeds.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Llm;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;

/**
 * The chat-path twin of {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\FailoverLlmClient}:
 * wraps an ordered list of {@see ChatLlmClientInterface}s (primary first) and
 * returns the first success, failing over to the next only on
 * {@see LlmUnavailableException}. Backs the optional second Gemini API key for
 * chat turns; with no backup configured, the factory uses the single client
 * directly and this class is never involved. If every client fails, the last
 * failure rethrows so chat degrades to the facts browser exactly as it would
 * with a single unavailable client (I6/I11).
 */
final class FailoverChatLlmClient implements ChatLlmClientInterface
{
    /** @var non-empty-list<ChatLlmClientInterface> */
    private readonly array $clients;

    /**
     * @param non-empty-list<ChatLlmClientInterface> $clients ordered, primary first
     */
    public function __construct(array $clients, private readonly ?SystemLogger $logger = null)
    {
        if ($clients === []) {
            throw new \DomainException('FailoverChatLlmClient requires at least one client');
        }
        $this->clients = $clients;
    }

    public function converse(ChatLlmRequest $req): ChatLlmResponse
    {
        $lastFailure = null;
        $total = count($this->clients);

        foreach ($this->clients as $index => $client) {
            try {
                return $client->converse($req);
            } catch (LlmUnavailableException $e) {
                $lastFailure = $e;
                if ($index + 1 < $total) {
                    $this->logger?->warning('ClinicalCopilot: chat LLM provider failed, trying backup', [
                        'failed_provider_index' => $index,
                        'remaining' => $total - $index - 1,
                        'exception' => $e,
                    ]);
                }
            }
        }

        // Every element of $clients is non-empty (constructor-enforced), and
        // every loop iteration either returns on success or assigns
        // $lastFailure in its catch block (any other throwable propagates
        // immediately, unchanged) -- so $lastFailure is always set here.
        throw $lastFailure;
    }
}
