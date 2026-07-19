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
        foreach ([WorkerConfig::ENV_BACKGROUND_LLM_ENABLED, WorkerConfig::ENV_QA_REVIEW_ENABLED] as $env) {
            putenv($env);
            unset($_ENV[$env], $_SERVER[$env]);
        }
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

    public function testQaReviewDisabledByDefault(): void
    {
        self::assertFalse(WorkerConfig::qaReviewEnabled(), 'the second-model QA reviewer is off unless explicitly opted in');
    }

    public function testQaReviewEnabledWhenEnvTrue(): void
    {
        putenv(WorkerConfig::ENV_QA_REVIEW_ENABLED . '=true');

        self::assertTrue(WorkerConfig::qaReviewEnabled());
    }
}
