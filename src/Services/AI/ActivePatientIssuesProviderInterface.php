<?php

/**
 * Supplies a patient's active problem-list entries to AI services.
 *
 * Narrow read-only contract so callers (and their unit tests) depend on just
 * the one operation they need rather than the full PatientIssuesService surface.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI;

use OpenEMR\Validators\ProcessingResult;

interface ActivePatientIssuesProviderInterface
{
    /**
     * Return the patient's active `lists` rows (problems, medications, etc.).
     */
    public function getActiveIssues(int $pid): ProcessingResult;
}
