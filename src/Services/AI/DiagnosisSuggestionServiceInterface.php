<?php

/**
 * Contract for producing advisory differential-diagnosis suggestions.
 *
 * Depending on this interface (rather than the concrete, final
 * DiagnosisSuggestionService) lets callers such as REST controllers be
 * unit-tested with a test double in place of the real service.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI;

interface DiagnosisSuggestionServiceInterface
{
    /**
     * Suggest differential diagnoses for the given patient pid.
     *
     * @throws OpenAiException when the AI service is unavailable or errors
     */
    public function suggestByPid(int $pid): DiagnosisSuggestionResult;
}
