<?php

/**
 * Guards the prompt-assembly contract: fact bytes in the prompt == the digest's canonical bytes.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce;

use OpenEMR\Modules\ClinicalCopilot\Fact\CanonicalSerializer;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: the LLM prompt silently drifting from the exact fact
 * set the digest addresses -- e.g. a stray re-serialization step that drops a
 * fact, reorders one, or reformats a decimal -- which would let a served
 * narrative and its own citations disagree about what the underlying data
 * actually was. docs/build-notes.md/U7: "prompt fact bytes == canonical
 * serialization" is asserted here with the SAME function
 * ({@see CanonicalSerializer::serializeFacts()}) the digest uses (U3).
 */
final class PromptAssemblerTest extends TestCase
{
    public function testFactPortionOfPromptIsByteIdenticalToCanonicalSerialization(): void
    {
        $facts = ReduceTestFactory::twoFactSet();
        $expectedBytes = CanonicalSerializer::serializeFacts($facts);

        $assembled = (new PromptAssembler())->assemble(
            $facts,
            ReduceTestFactory::context(),
            ReduceTestFactory::patientIdentifiers(),
        );

        self::assertStringContainsString($expectedBytes, $assembled->userContent);
    }

    public function testFactOrderNeverAffectsAssembledPromptBytes(): void
    {
        $facts = ReduceTestFactory::twoFactSet();
        $reversed = array_reverse($facts);

        $forward = (new PromptAssembler())->assemble($facts, ReduceTestFactory::context(), ReduceTestFactory::patientIdentifiers());
        $backward = (new PromptAssembler())->assemble($reversed, ReduceTestFactory::context(), ReduceTestFactory::patientIdentifiers());

        self::assertSame($forward->userContent, $backward->userContent);
    }

    public function testPromptCarriesTheClaimResponseSchemaAndPinnedModel(): void
    {
        $context = ReduceTestFactory::context('reduce-v3');

        $assembled = (new PromptAssembler())->assemble(
            ReduceTestFactory::twoFactSet(),
            $context,
            ReduceTestFactory::patientIdentifiers(),
        );

        self::assertSame(Claim::jsonSchema(), $assembled->responseSchema);
        self::assertSame($context->model, $assembled->model);
        self::assertSame('reduce-v3', $assembled->promptVersion);
    }

    public function testPriorFindingsAreAppendedOnRetry(): void
    {
        $assembled = (new PromptAssembler())->assemble(
            ReduceTestFactory::twoFactSet(),
            ReduceTestFactory::context(),
            ReduceTestFactory::patientIdentifiers(),
            'claim 3 cites fact F17 which does not contain the value 8.4',
        );

        self::assertStringContainsString('claim 3 cites fact F17', $assembled->userContent);
    }

    public function testSystemInstructionsBakeInTheStage3Discipline(): void
    {
        $assembled = (new PromptAssembler())->assemble(
            ReduceTestFactory::twoFactSet(),
            ReduceTestFactory::context(),
            ReduceTestFactory::patientIdentifiers(),
        );

        self::assertStringContainsString('no data is available', $assembled->systemInstructions);
        self::assertStringContainsString('Never calculate', $assembled->systemInstructions);
        self::assertStringContainsString('cite the fact_id', $assembled->systemInstructions);
        self::assertStringContainsString('causation', $assembled->systemInstructions);
    }
}
