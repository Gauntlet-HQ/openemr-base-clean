<?php

/**
 * Guards I6: an unavailable LLM must never produce a silent or fabricated result.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce;

use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ReduceRequest;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a physician silently seeing an empty or fabricated
 * narrative when there is no LLM to ask (the default state of this dev/test
 * environment -- no ADC is configured anywhere here). Reducer must surface
 * the unavailability as an explicit, checkable signal on ReduceResult (I6),
 * never throw an uncaught exception up through the caller and never return a
 * result indistinguishable from a real generation.
 */
final class ReducerDegradationTest extends TestCase
{
    public function testStubClientDownProducesUnavailableResultNotAnException(): void
    {
        $reducer = new Reducer(StubLlmClient::down(), new PromptAssembler(), new Redactor());

        $result = $reducer->reduce(new ReduceRequest(
            'session-1',
            'corr-1',
            ReduceTestFactory::twoFactSet(),
            ReduceTestFactory::patientIdentifiers(),
            ReduceTestFactory::context(),
        ));

        self::assertFalse($result->isAvailable());
        self::assertNull($result->rawClaimsJson);
        self::assertNull($result->redactionMap);
        self::assertNotNull($result->unavailableReason);
    }

    public function testStubClientUpProducesAvailableResultWithUsage(): void
    {
        $reducer = new Reducer(
            StubLlmClient::up(new LlmResponse('[]', 'gemini-2.5-pro', 123, 45, 900)),
            new PromptAssembler(),
            new Redactor(),
        );

        $result = $reducer->reduce(new ReduceRequest(
            'session-1',
            'corr-1',
            ReduceTestFactory::twoFactSet(),
            ReduceTestFactory::patientIdentifiers(),
            ReduceTestFactory::context(),
        ));

        self::assertTrue($result->isAvailable());
        self::assertSame('[]', $result->rawClaimsJson);
        self::assertSame('gemini-2.5-pro', $result->modelVersion);
        self::assertSame(123, $result->tokensIn);
        self::assertSame(45, $result->tokensOut);
        self::assertNotNull($result->redactionMap);
    }
}
