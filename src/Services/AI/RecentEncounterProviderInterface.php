<?php

/**
 * Supplies a patient's most recent encounter to AI services.
 *
 * Narrow read-only contract so callers (and their unit tests) depend on just
 * the one operation they need rather than the full EncounterService surface.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\AI;

interface RecentEncounterProviderInterface
{
    /**
     * Return the most recent encounter for the patient, or null if none.
     *
     * @param int $pid The unique public id (pid) for the patient.
     *
     * @return array<array-key, mixed>|null
     */
    public function getMostRecentEncounterForPatient($pid): ?array;
}
