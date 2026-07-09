<?php

/**
 * REST controller for AI-driven differential diagnosis suggestions.
 *
 * Resolves the patient by UUID, then delegates to
 * DiagnosisSuggestionService which returns either advisory differential
 * diagnosis suggestions or an "insufficient data" result. The suggestions
 * are clinical decision support only and require clinician review.
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
use OpenEMR\Services\AI\DiagnosisSuggestionService;
use OpenEMR\Services\AI\DiagnosisSuggestionServiceInterface;
use OpenEMR\Services\AI\OpenAiException;
use Symfony\Component\HttpFoundation\Response;

class DiagnosisSuggestionRestController
{
    private readonly PatientPidResolverInterface $pidResolver;
    private readonly DiagnosisSuggestionServiceInterface $suggestionService;

    public function __construct(
        ?PatientPidResolverInterface $pidResolver = null,
        ?DiagnosisSuggestionServiceInterface $suggestionService = null,
    ) {
        $this->pidResolver = $pidResolver ?? new PatientServicePidResolver();
        $this->suggestionService = $suggestionService ?? new DiagnosisSuggestionService();
    }

    public function getSuggestions(string $puuid, HttpRestRequest $request): Response
    {
        $pid = $this->pidResolver->resolvePidByUuid($puuid);
        if ($pid === null) {
            return RestControllerHelper::responseHandler(
                ['error' => 'Patient not found.'],
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            $result = $this->suggestionService->suggestByPid($pid);
        } catch (OpenAiException) {
            // Message is already generic and user-safe; details are logged in the client.
            return RestControllerHelper::responseHandler(
                ['error' => 'The AI diagnosis service is currently unavailable.'],
                null,
                Response::HTTP_BAD_GATEWAY
            );
        }

        return RestControllerHelper::responseHandler($result->toArray(), null, Response::HTTP_OK);
    }
}
