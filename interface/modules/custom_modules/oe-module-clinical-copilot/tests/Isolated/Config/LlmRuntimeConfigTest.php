<?php

/**
 * LlmRuntimeConfig: model selection for reduce/chat surfaces.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Config;

use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use PHPUnit\Framework\TestCase;

final class LlmRuntimeConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY');
        putenv('CLINICAL_COPILOT_GEMINI_API_MODEL');
    }

    public function testVertexUsesPro(): void
    {
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID=test-project');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY');
        putenv('CLINICAL_COPILOT_GEMINI_API_MODEL');

        self::assertSame('gemini-2.5-pro', LlmRuntimeConfig::reduceAndChatModel());
    }

    public function testApiKeyDefaultsToFlash(): void
    {
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY=test-key');
        putenv('CLINICAL_COPILOT_GEMINI_API_MODEL');

        self::assertSame('gemini-2.5-flash', LlmRuntimeConfig::reduceAndChatModel());
    }

    public function testApiKeyModelOverride(): void
    {
        putenv('CLINICAL_COPILOT_GCP_PROJECT_ID');
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY=test-key');
        putenv('CLINICAL_COPILOT_GEMINI_API_MODEL=gemini-2.0-flash');

        self::assertSame('gemini-2.0-flash', LlmRuntimeConfig::reduceAndChatModel());
    }
}
