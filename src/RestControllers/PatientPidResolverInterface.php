<?php

/**
 * Resolves an external patient UUID into an internal patient pid.
 *
 * Narrow boundary port so REST controllers can parse the untrusted UUID
 * from a request into a typed pid without depending on the full
 * PatientService surface (which keeps them unit-testable).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\RestControllers;

interface PatientPidResolverInterface
{
    /**
     * Return the internal pid for the given patient UUID, or null if the
     * patient does not exist or has no valid pid.
     */
    public function resolvePidByUuid(string $puuid): ?int;
}
