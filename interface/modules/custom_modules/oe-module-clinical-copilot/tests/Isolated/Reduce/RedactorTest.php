<?php

/**
 * Guards egress redaction: no direct identifier ever leaves the process; rehydration is lossless.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce;

use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a patient's name, MRN, DOB, or address leaking into
 * an outbound Vertex payload (ARCHITECTURE.md §4) -- the one place PHI could
 * leave the EMR's process boundary in this module. Also guards the reverse
 * failure: a token surviving into what the physician actually reads, which
 * would make the rendered answer useless.
 */
final class RedactorTest extends TestCase
{
    private const IDENTIFIERS = ['Jane Q. Sampleton', 'MRN-778812', '1968-04-11', '19 Birchwood Ln, Springfield'];

    public function testNoDirectIdentifierAppearsInTheRedactedOutboundPrompt(): void
    {
        $identifiers = new PatientIdentifiers(...self::IDENTIFIERS);
        $assembled = (new PromptAssembler())->assemble(
            ReduceTestFactory::twoFactSet(),
            ReduceTestFactory::context(),
            $identifiers,
        );

        $redacted = (new Redactor())->redactPrompt('session-42', $identifiers, $assembled);

        foreach (self::IDENTIFIERS as $identifierValue) {
            self::assertStringNotContainsString($identifierValue, $redacted->request->systemInstructions);
            self::assertStringNotContainsString($identifierValue, $redacted->request->userContent);
        }
    }

    public function testRedactionLeavesTheCanonicalFactBytesUntouched(): void
    {
        $identifiers = new PatientIdentifiers(...self::IDENTIFIERS);
        $assembled = (new PromptAssembler())->assemble(
            ReduceTestFactory::twoFactSet(),
            ReduceTestFactory::context(),
            $identifiers,
        );

        $redactor = new Redactor();
        $redacted = $redactor->redactPrompt('session-42', $identifiers, $assembled);

        // The Fact schema carries no direct identifiers at all (only the
        // integer pid) -- redaction must be a strict no-op on the facts
        // block: rehydrating the redacted prompt must reproduce the
        // original assembled prompt byte-for-byte, never perturbing
        // clinical values that happen to share characters with an
        // identifier.
        self::assertSame(
            $assembled->userContent,
            $redactor->rehydrate($redacted->request->userContent, $redacted->map),
        );
    }

    public function testTokensAreStablePerSessionAndField(): void
    {
        $identifiers = new PatientIdentifiers(...self::IDENTIFIERS);
        $assembled = (new PromptAssembler())->assemble(
            ReduceTestFactory::twoFactSet(),
            ReduceTestFactory::context(),
            $identifiers,
        );
        $redactor = new Redactor();

        $first = $redactor->redactPrompt('session-42', $identifiers, $assembled);
        $second = $redactor->redactPrompt('session-42', $identifiers, $assembled);
        $otherSession = $redactor->redactPrompt('session-99', $identifiers, $assembled);

        self::assertSame($first->map->tokenFor('name'), $second->map->tokenFor('name'));
        self::assertNotSame($first->map->tokenFor('name'), $otherSession->map->tokenFor('name'));
    }

    public function testRehydrationRestoresTheOriginalTextExactly(): void
    {
        $identifiers = new PatientIdentifiers(...self::IDENTIFIERS);
        $assembled = (new PromptAssembler())->assemble(
            ReduceTestFactory::twoFactSet(),
            ReduceTestFactory::context(),
            $identifiers,
        );
        $redactor = new Redactor();
        $redacted = $redactor->redactPrompt('session-42', $identifiers, $assembled);

        $nameToken = $redacted->map->tokenFor('name');
        self::assertNotNull($nameToken);

        $renderedAnswer = "Good morning -- reviewing {$nameToken}'s labs before the visit.";
        $rehydrated = $redactor->rehydrate($renderedAnswer, $redacted->map);

        self::assertSame("Good morning -- reviewing Jane Q. Sampleton's labs before the visit.", $rehydrated);
        self::assertStringNotContainsString($nameToken, $rehydrated);
    }
}
