<?php

/**
 * WorkerConfig isolated tests.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Config;

use OpenEMR\Modules\ClinicalCopilot\Config\WorkerConfig;
use PHPUnit\Framework\TestCase;

final class WorkerConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(WorkerConfig::ENV_BACKGROUND_LLM_ENABLED);
        unset(
            $_ENV[WorkerConfig::ENV_BACKGROUND_LLM_ENABLED],
            $_SERVER[WorkerConfig::ENV_BACKGROUND_LLM_ENABLED],
        );
    }

    public function testBackgroundLlmDisabledByDefault(): void
    {
        self::assertFalse(WorkerConfig::backgroundLlmEnabled());
    }

    public function testBackgroundLlmEnabledWhenEnvTrue(): void
    {
        putenv(WorkerConfig::ENV_BACKGROUND_LLM_ENABLED . '=true');

        self::assertTrue(WorkerConfig::backgroundLlmEnabled());
    }
}
