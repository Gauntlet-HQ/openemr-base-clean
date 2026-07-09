<?php

/**
 * Thin server-side client for the OpenAI Chat Completions API.
 *
 * All calls originate from PHP (never the browser) so the API key stays
 * server-side. Failures are logged with PSR-3 context and re-thrown as a
 * generic OpenAiException so provider internals never reach the user.
 *
 * NOTE: Before sending any Protected Health Information (PHI) through this
 * client in production, a Business Associate Agreement (BAA) must be in
 * place with OpenAI, with zero-data-retention and training opt-out enabled.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Http\oeHttp;
use OpenEMR\Services\AI\Observability\TokenUsage;
use Psr\Log\LoggerInterface;

final class OpenAiChatClient implements OpenAiChatClientInterface
{
    private readonly OpenAiConfig $config;
    private readonly LoggerInterface $logger;

    public function __construct(
        ?OpenAiConfig $config = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->config = $config ?? OpenAiConfig::fromEnvironment();
        $this->logger = $logger ?? ServiceContainer::getLogger();
    }

    /**
     * Send a chat completion request and return the assistant's text.
     *
     * Thin wrapper over chatWithUsage() for callers that only need the text.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @throws OpenAiException when the request fails or the response is unusable
     */
    public function chat(array $messages, float $temperature = 0.2): string
    {
        return $this->chatWithUsage($messages, $temperature)->content;
    }

    /**
     * Send a chat completion request and return the text together with the
     * model used and the token usage reported by the provider.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @throws OpenAiException when the request fails or the response is unusable
     */
    public function chatWithUsage(array $messages, float $temperature = 0.2): OpenAiChatResult
    {
        try {
            $response = oeHttp::usingHeaders([
                'Authorization' => 'Bearer ' . $this->config->apiKey,
            ])->setOptions([
                'timeout' => $this->config->timeoutSeconds,
            ])->post($this->config->chatCompletionsUrl(), [
                'model' => $this->config->model,
                'messages' => $messages,
                'temperature' => $temperature,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI chat request failed to send', ['exception' => $e]);

            throw new OpenAiException('Unable to reach the AI service.', 0, $e);
        }

        $status = $response->status();
        if ($status < 200 || $status >= 300) {
            $this->logger->error('OpenAI chat returned a non-success status', ['status' => $status]);

            throw new OpenAiException('The AI service returned an error.');
        }

        $decoded = $response->json();
        $content = null;
        $usage = TokenUsage::none();
        $model = $this->config->model;
        if (is_array($decoded)) {
            $candidate = $decoded['choices'][0]['message']['content'] ?? null;
            if (is_string($candidate)) {
                $content = trim($candidate);
            }
            $usage = TokenUsage::fromResponse($decoded);
            $reportedModel = $decoded['model'] ?? null;
            if (is_string($reportedModel) && $reportedModel !== '') {
                $model = $reportedModel;
            }
        }

        if ($content === null || $content === '') {
            $this->logger->error('OpenAI chat returned an unexpected payload', ['status' => $status]);

            throw new OpenAiException('The AI service returned an unexpected response.');
        }

        return new OpenAiChatResult($content, $model, $usage);
    }
}
