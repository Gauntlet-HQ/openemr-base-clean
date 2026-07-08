<?php

/**
 * LlmClientFactory: T23's three-way env-var precedence (Vertex > Gemini API key > Unavailable).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\UnavailableLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiApiLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Reduce\VertexLlmClient;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a misordered or missed env-var check here would
 * silently swap the production Vertex path for the dev/test-only Gemini
 * API-key fast-path (or vice versa) -- exactly the kind of config-surface
 * regression docs/configuration.md warns is NOT HIPAA-eligible if it happens
 * to the wrong site. Only `getenv()` is stubbed (via `putenv()`); the
 * constructed client's concrete type is asserted without ever making a
 * network call, since all three implementations validate their constructor
 * arguments synchronously and none contact a provider until
 * `generateStructured()` is called.
 */
final class LlmClientFactorySelectionTest extends TestCase
{
    protected function tearDown(): void
    {
        // Never let one test's env stubbing bleed into the next (this suite
        // runs in-process, not per-test process isolation).
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID');
        putenv('CLINICAL_COPILOT_GCP_LOCATION');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY');
    }

    public function testNothingConfiguredDegradesToUnavailable(): void
    {
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID');
        putenv('CLINICAL_COPILOT_GCP_LOCATION');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY');

        self::assertInstanceOf(UnavailableLlmClient::class, LlmClientFactory::create());
    }

    public function testGeminiApiKeyAloneSelectsTheDevTestFastPath(): void
    {
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID');
        putenv('CLINICAL_COPILOT_GCP_LOCATION');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY=test-ai-studio-key');

        self::assertInstanceOf(GeminiApiLlmClient::class, LlmClientFactory::create());
    }

    public function testVertexProjectIdAloneSelectsVertex(): void
    {
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID=test-project');
        putenv('CLINICAL_COPILOT_GCP_LOCATION');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY');

        self::assertInstanceOf(VertexLlmClient::class, LlmClientFactory::create());
    }

    public function testVertexWinsOverGeminiApiKeyWhenBothAreConfigured(): void
    {
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID=test-project');
        putenv('CLINICAL_COPILOT_GCP_LOCATION=us-central1');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY=test-ai-studio-key');

        self::assertInstanceOf(VertexLlmClient::class, LlmClientFactory::create());
    }
}
