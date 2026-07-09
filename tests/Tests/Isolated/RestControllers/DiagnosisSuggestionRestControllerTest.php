<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\RestControllers;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\RestControllers\DiagnosisSuggestionRestController;
use OpenEMR\RestControllers\PatientPidResolverInterface;
use OpenEMR\Services\AI\DiagnosisSuggestionResult;
use OpenEMR\Services\AI\DiagnosisSuggestionServiceInterface;
use OpenEMR\Services\AI\OpenAiException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

#[Group('isolated')]
final class DiagnosisSuggestionRestControllerTest extends TestCase
{
    private PatientPidResolverInterface&MockObject $pidResolver;
    private DiagnosisSuggestionServiceInterface&MockObject $suggestionService;
    private HttpRestRequest $request;
    private DiagnosisSuggestionRestController $controller;

    protected function setUp(): void
    {
        $this->pidResolver = $this->createMock(PatientPidResolverInterface::class);
        $this->suggestionService = $this->createMock(DiagnosisSuggestionServiceInterface::class);
        $this->request = $this->createStub(HttpRestRequest::class);

        $this->controller = new DiagnosisSuggestionRestController(
            $this->pidResolver,
            $this->suggestionService,
        );
    }

    public function testReturnsNotFoundWhenPatientCannotBeResolved(): void
    {
        $this->pidResolver->method('resolvePidByUuid')->willReturn(null);
        $this->suggestionService->expects($this->never())->method('suggestByPid');

        $response = $this->controller->getSuggestions('missing-uuid', $this->request);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame(['error' => 'Patient not found.'], $this->decode($response));
    }

    public function testReturnsSuggestionsForResolvedPatient(): void
    {
        $this->pidResolver->method('resolvePidByUuid')->willReturn(42);
        $this->suggestionService->expects($this->once())
            ->method('suggestByPid')
            ->with(42)
            ->willReturn(DiagnosisSuggestionResult::fromSuggestions('1. Community-acquired pneumonia.'));

        $response = $this->controller->getSuggestions('patient-uuid', $this->request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(
            ['hasSufficientData' => true, 'suggestions' => '1. Community-acquired pneumonia.'],
            $this->decode($response)
        );
    }

    public function testReturnsInsufficientDataResult(): void
    {
        $this->pidResolver->method('resolvePidByUuid')->willReturn(42);
        $this->suggestionService->method('suggestByPid')
            ->willReturn(DiagnosisSuggestionResult::insufficientData());

        $response = $this->controller->getSuggestions('patient-uuid', $this->request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(
            ['hasSufficientData' => false, 'suggestions' => 'insufficient data'],
            $this->decode($response)
        );
    }

    public function testReturnsBadGatewayWhenServiceUnavailable(): void
    {
        $this->pidResolver->method('resolvePidByUuid')->willReturn(42);
        $this->suggestionService->method('suggestByPid')
            ->willThrowException(new OpenAiException('The AI service returned an error.'));

        $response = $this->controller->getSuggestions('patient-uuid', $this->request);

        $this->assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'The AI diagnosis service is currently unavailable.'],
            $this->decode($response)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $content = $response->getContent();
        $this->assertIsString($content);
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
