<?php

/**
 * REST controller for the AI-generated patient history summary.
 *
 * Resolves the patient by UUID, then delegates to
 * PatientHistorySummaryService which returns either a concise summary of
 * the most recent history or a "new patient" result when no history exists.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\RestControllers;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Services\AI\OpenAiException;
use OpenEMR\Services\AI\PatientHistorySummaryService;
use OpenEMR\Services\PatientService;
use Symfony\Component\HttpFoundation\Response;

class PatientHistorySummaryRestController
{
    private readonly PatientService $patientService;
    private readonly PatientHistorySummaryService $summaryService;

    public function __construct(
        ?PatientService $patientService = null,
        ?PatientHistorySummaryService $summaryService = null,
    ) {
        $this->patientService = $patientService ?? new PatientService();
        $this->summaryService = $summaryService ?? new PatientHistorySummaryService();
    }

    public function getSummary(string $puuid, HttpRestRequest $request): Response
    {
        $patientResult = $this->patientService->getOne($puuid);
        if (!$patientResult->hasData()) {
            return RestControllerHelper::responseHandler(
                ['error' => 'Patient not found.'],
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        $patient = $patientResult->getData()[0];
        $pid = (int) ($patient['pid'] ?? 0);
        if ($pid <= 0) {
            return RestControllerHelper::responseHandler(
                ['error' => 'Patient not found.'],
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            $result = $this->summaryService->summarizeByPid($pid);
        } catch (OpenAiException) {
            // Message is already generic and user-safe; details are logged in the client.
            return RestControllerHelper::responseHandler(
                ['error' => 'The AI summary service is currently unavailable.'],
                null,
                Response::HTTP_BAD_GATEWAY
            );
        }

        return RestControllerHelper::responseHandler($result->toArray(), null, Response::HTTP_OK);
    }
}
