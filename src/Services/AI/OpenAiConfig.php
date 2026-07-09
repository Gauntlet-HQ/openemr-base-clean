<?php

/**
 * Immutable configuration for the OpenAI client.
 *
 * The API key is a server-side secret and is read from the environment
 * (never from the database, $GLOBALS, or client input). This keeps the
 * credential outside of version control and out of the browser.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI;

use OpenEMR\Core\OEEnvBag;
use SensitiveParameter;

final readonly class OpenAiConfig
{
    public const DEFAULT_MODEL = 'gpt-4o-mini';
    public const DEFAULT_BASE_URI = 'https://api.openai.com/v1';
    public const DEFAULT_TIMEOUT_SECONDS = 30.0;

    public function __construct(
        #[SensitiveParameter]
        public string $apiKey,
        public string $model = self::DEFAULT_MODEL,
        public string $baseUri = self::DEFAULT_BASE_URI,
        public float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
        if (trim($this->apiKey) === '') {
            throw new OpenAiException('OpenAI API key is not configured.');
        }
    }

    /**
     * Build the configuration from environment variables.
     *
     * Reads OPENAI_API_KEY (required) and OPENAI_MODEL (optional).
     */
    public static function fromEnvironment(): self
    {
        $env = OEEnvBag::getInstance();

        return new self(
            apiKey: $env->getString('OPENAI_API_KEY'),
            model: $env->getString('OPENAI_MODEL') ?: self::DEFAULT_MODEL,
        );
    }

    public function chatCompletionsUrl(): string
    {
        return rtrim($this->baseUri, '/') . '/chat/completions';
    }
}
