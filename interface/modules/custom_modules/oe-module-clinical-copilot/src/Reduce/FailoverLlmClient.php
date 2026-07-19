<?php

/**
 * Failover LLM client: try each provider in order until one succeeds.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

use OpenEMR\Common\Logging\SystemLogger;

/**
 * Wraps an ordered list of {@see LlmClientInterface}s (primary first, one or
 * more backups after) and returns the first success. When a client raises
 * {@see LlmUnavailableException} — the module's "provider degraded" signal:
 * bad/expired key, quota exhaustion, a transient transport or provider error —
 * the next client is tried. Only that exception triggers failover; any other
 * throwable (a real bug) propagates immediately, unchanged.
 *
 * This is what backs the optional second Gemini API key
 * ({@see \OpenEMR\Modules\ClinicalCopilot\Config\LlmEnv::geminiApiKeyBackup()}):
 * {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory} wraps
 * `[primary-key client, backup-key client]` in this when a backup is set, so a
 * single key going bad degrades to the backup instead of straight to the
 * facts-only path. With no backup configured the factory uses the single
 * client directly and this class is never involved — zero behaviour change.
 *
 * If EVERY client fails, the last {@see LlmUnavailableException} is rethrown so
 * the caller degrades exactly as it would with a single unavailable client
 * (I6): synthesis serves facts-only, chat becomes a facts browser.
 */
final class FailoverLlmClient implements LlmClientInterface
{
    /** @var non-empty-list<LlmClientInterface> */
    private readonly array $clients;

    /**
     * @param non-empty-list<LlmClientInterface> $clients ordered, primary first
     */
    public function __construct(array $clients, private readonly ?SystemLogger $logger = null)
    {
        if ($clients === []) {
            throw new \DomainException('FailoverLlmClient requires at least one client');
        }
        $this->clients = $clients;
    }

    public function generateStructured(PromptRequest $req): LlmResponse
    {
        $lastFailure = null;
        $total = count($this->clients);

        foreach ($this->clients as $index => $client) {
            try {
                return $client->generateStructured($req);
            } catch (LlmUnavailableException $e) {
                $lastFailure = $e;
                if ($index + 1 < $total) {
                    $this->logger?->warning('ClinicalCopilot: LLM provider failed, trying backup', [
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
