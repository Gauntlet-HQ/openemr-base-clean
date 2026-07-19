<?php

/**
 * LlmClientFactory: env-var selection (Gemini API key, else Unavailable).
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
use PHPUnit\Framework\TestCase;

/**
 * The Vertex/ADC path was removed — this deployment uses the Gemini API key
 * exclusively. So selection is two-way: a key present => {@see GeminiApiLlmClient},
 * otherwise {@see UnavailableLlmClient} (facts-only degrade), and any leftover
 * GCP project id is ignored. Only `getenv()` is stubbed (via `putenv()`); the
 * constructed client's concrete type is asserted without a network call, since
 * both implementations validate constructor arguments synchronously and neither
 * contacts a provider until `generateStructured()` is called.
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

    public function testAGcpProjectIdAloneNoLongerSelectsAClient(): void
    {
        // Vertex removed: a GCP project id with no API key degrades to Unavailable.
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID=test-project');
        putenv('CLINICAL_COPILOT_GCP_LOCATION');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY');

        self::assertInstanceOf(UnavailableLlmClient::class, LlmClientFactory::create());
    }

    public function testTheApiKeyPathIsUsedEvenWhenAGcpProjectIdIsSet(): void
    {
        // Vertex removed: the Gemini API key is the only provider, selected
        // regardless of any leftover GCP project id.
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID=test-project');
        putenv('CLINICAL_COPILOT_GCP_LOCATION=us-central1');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY=test-ai-studio-key');

        self::assertInstanceOf(GeminiApiLlmClient::class, LlmClientFactory::create());
    }
}
