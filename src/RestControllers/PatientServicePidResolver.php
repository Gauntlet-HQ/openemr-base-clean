<?php

/**
 * PatientService-backed implementation of PatientPidResolverInterface.
 *
 * Looks the patient up by UUID and extracts the internal pid, returning
 * null for any patient that cannot be resolved to a positive pid.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\RestControllers;

use OpenEMR\Services\PatientService;

final class PatientServicePidResolver implements PatientPidResolverInterface
{
    private readonly PatientService $patientService;

    public function __construct(?PatientService $patientService = null)
    {
        $this->patientService = $patientService ?? new PatientService();
    }

    public function resolvePidByUuid(string $puuid): ?int
    {
        $result = $this->patientService->getOne($puuid);
        if (!$result->hasData()) {
            return null;
        }

        $data = $result->getData();
        $patient = is_array($data) ? ($data[0] ?? null) : null;
        if (!is_array($patient)) {
            return null;
        }

        $pidValue = $patient['pid'] ?? null;
        $pid = is_numeric($pidValue) ? (int) $pidValue : 0;

        return $pid > 0 ? $pid : null;
    }
}
