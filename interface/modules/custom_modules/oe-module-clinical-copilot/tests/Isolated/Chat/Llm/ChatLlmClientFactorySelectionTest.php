<?php

/**
 * ChatLlmClientFactory: T23's three-way env-var precedence (Vertex > Gemini API key > Unavailable).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat\Llm;

use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmClientFactory;
use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\GeminiApiChatLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\UnavailableChatLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\VertexChatLlmClient;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors {@see \OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\ReadPath\LlmClientFactorySelectionTest}
 * exactly, one surface over: the chat agent loop must resolve the SAME
 * precedence as the synthesis reduce path, since both read the identical
 * three environment variables (build-notes.md: "there is no reason chat and
 * synthesis would ever target different Vertex deployments").
 */
final class ChatLlmClientFactorySelectionTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID');
        putenv('CLINICAL_COPILOT_GCP_LOCATION');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY');
    }

    public function testNothingConfiguredDegradesToUnavailable(): void
    {
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID');
        putenv('CLINICAL_COPILOT_GCP_LOCATION');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY');

        self::assertInstanceOf(UnavailableChatLlmClient::class, ChatLlmClientFactory::create());
    }

    public function testGeminiApiKeyAloneSelectsTheDevTestFastPath(): void
    {
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID');
        putenv('CLINICAL_COPILOT_GCP_LOCATION');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY=test-ai-studio-key');

        self::assertInstanceOf(GeminiApiChatLlmClient::class, ChatLlmClientFactory::create());
    }

    public function testVertexProjectIdAloneSelectsVertex(): void
    {
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID=test-project');
        putenv('CLINICAL_COPILOT_GCP_LOCATION');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY');

        self::assertInstanceOf(VertexChatLlmClient::class, ChatLlmClientFactory::create());
    }

    public function testVertexWinsOverGeminiApiKeyWhenBothAreConfigured(): void
    {
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID=test-project');
        putenv('CLINICAL_COPILOT_GCP_LOCATION=us-central1');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY=test-ai-studio-key');

        self::assertInstanceOf(VertexChatLlmClient::class, ChatLlmClientFactory::create());
    }
}
