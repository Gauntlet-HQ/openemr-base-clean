<?php

/**
 * Background worker runtime toggles for Clinical Co-Pilot.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Config;

/**
 * The warm/QA worker must not call Gemini on a cron tick by default — LLM
 * narration belongs on user-facing paths ({@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath::read()}
 * from doc/chat, {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath::regenerate()},
 * chat turns). Set {@see self::ENV_BACKGROUND_LLM_ENABLED} to opt in.
 */
final class WorkerConfig
{
    public const ENV_BACKGROUND_LLM_ENABLED = 'CLINICAL_COPILOT_WORKER_LLM_ENABLED';

    private function __construct()
    {
    }

    public static function backgroundLlmEnabled(): bool
    {
        $raw = LlmEnv::getString(self::ENV_BACKGROUND_LLM_ENABLED);
        if ($raw === '') {
            return false;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL);
    }
}
