<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\AI;

use OpenEMR\Services\AI\OpenAiConfig;
use OpenEMR\Services\AI\OpenAiException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
final class OpenAiConfigTest extends TestCase
{
    public function testThrowsWhenApiKeyIsEmpty(): void
    {
        $this->expectException(OpenAiException::class);

        new OpenAiConfig('   ');
    }

    public function testAppliesDefaults(): void
    {
        $config = new OpenAiConfig('sk-test');

        $this->assertSame(OpenAiConfig::DEFAULT_MODEL, $config->model);
        $this->assertSame(
            'https://api.openai.com/v1/chat/completions',
            $config->chatCompletionsUrl()
        );
    }

    public function testChatCompletionsUrlTrimsTrailingSlash(): void
    {
        $config = new OpenAiConfig('sk-test', 'gpt-4o', 'https://proxy.example.com/v1/');

        $this->assertSame(
            'https://proxy.example.com/v1/chat/completions',
            $config->chatCompletionsUrl()
        );
    }
}
