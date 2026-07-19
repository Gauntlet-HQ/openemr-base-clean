<?php

/**
 * LlmEnv: resolves LLM credentials from process env and optional local file.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Config;

use OpenEMR\Modules\ClinicalCopilot\Config\LlmEnv;
use PHPUnit\Framework\TestCase;

final class LlmEnvTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY');
        unset($_ENV['CLINICAL_COPILOT_GEMINI_API_KEY'], $_SERVER['CLINICAL_COPILOT_GEMINI_API_KEY']);
    }

    public function testReadsFromPutenv(): void
    {
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY=test-key-from-putenv');

        self::assertSame('test-key-from-putenv', LlmEnv::geminiApiKey());
    }

    public function testReadsFromServerSuperglobal(): void
    {
        $_SERVER['CLINICAL_COPILOT_GEMINI_API_KEY'] = 'test-key-from-server';

        self::assertSame('test-key-from-server', LlmEnv::geminiApiKey());
    }
}
