<?php

/**
 * Contract for a server-side OpenAI Chat Completions client.
 *
 * Depending on this interface (rather than the concrete, final
 * OpenAiChatClient) lets service-layer callers be unit-tested with a test
 * double in place of the real HTTP client.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI;

interface OpenAiChatClientInterface
{
    /**
     * Send a chat completion request and return the assistant's text.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @throws OpenAiException when the request fails or the response is unusable
     */
    public function chat(array $messages, float $temperature = 0.2): string;

    /**
     * Send a chat completion request and return the text together with the
     * model used and the token usage reported by the provider.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @throws OpenAiException when the request fails or the response is unusable
     */
    public function chatWithUsage(array $messages, float $temperature = 0.2): OpenAiChatResult;
}
