<?php

/**
 * Canonical serializer determinism: same content, any construction order,
 * identical bytes. This is what makes the digest and the LLM prompt (U7)
 * trustworthy inputs.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact;

use OpenEMR\Modules\ClinicalCopilot\Fact\CanonicalSerializer;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a physician's synthesis silently regenerating (or
 * failing to regenerate) because two logically-identical fact sets produced
 * different digest bytes purely due to extraction order, hash-map iteration
 * order, or PHP's default float formatting.
 */
final class CanonicalSerializerTest extends TestCase
{
    public function testFactListOrderDoesNotAffectSerializedBytes(): void
    {
        $a = FactTestFactory::a1cTrendPoint(pid: 1, resultPk: 1, raw: '7.2');
        $b = FactTestFactory::a1cTrendPoint(pid: 1, resultPk: 2, raw: '7.6');
        $c = FactTestFactory::censoredResult(pid: 1, resultPk: 3);

        $forward = CanonicalSerializer::serializeFacts([$a, $b, $c]);
        $reversed = CanonicalSerializer::serializeFacts([$c, $b, $a]);
        $shuffled = CanonicalSerializer::serializeFacts([$b, $c, $a]);

        self::assertSame($forward, $reversed);
        self::assertSame($forward, $shuffled);
    }

    public function testCitationInsertionOrderDoesNotAffectSerializedBytes(): void
    {
        $value = new FactValue('7.8', 7.8, Comparator::None, '%', '%', null);

        $factWithForwardCitations = new Fact(
            FactId::compute(
                Capability::ControlProxy,
                FactKind::Result,
                [
                    new Citation('procedure_result', 11, 'result', DateSource::Collected),
                    new Citation('procedure_result', 10, 'result', DateSource::Collected),
                ],
                $value,
            ),
            Capability::ControlProxy,
            '1',
            FactKind::Result,
            3,
            new \DateTimeImmutable('2025-06-07'),
            DateSource::Collected,
            $value,
            FactStatus::Corrected,
            [Flag::supersededCount(1)],
            [
                new Citation('procedure_result', 11, 'result', DateSource::Collected),
                new Citation('procedure_result', 10, 'result', DateSource::Collected),
            ],
        );

        $factWithReversedCitations = new Fact(
            $factWithForwardCitations->factId,
            Capability::ControlProxy,
            '1',
            FactKind::Result,
            3,
            new \DateTimeImmutable('2025-06-07'),
            DateSource::Collected,
            $value,
            FactStatus::Corrected,
            [Flag::supersededCount(1)],
            [
                new Citation('procedure_result', 10, 'result', DateSource::Collected),
                new Citation('procedure_result', 11, 'result', DateSource::Collected),
            ],
        );

        self::assertSame(
            CanonicalSerializer::serializeFacts([$factWithForwardCitations]),
            CanonicalSerializer::serializeFacts([$factWithReversedCitations]),
        );
    }

    public function testFlagInsertionOrderDoesNotAffectSerializedBytes(): void
    {
        $value = new FactValue('8.1', 8.1, Comparator::None, '%', '%', null);
        $citations = [new Citation('procedure_result', 20, 'result', DateSource::Collected)];
        $factId = FactId::compute(
            Capability::ControlProxy,
            FactKind::Result,
            $citations,
            $value,
        );

        $forward = new Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            FactKind::Result,
            3,
            new \DateTimeImmutable('2025-05-08'),
            DateSource::Collected,
            $value,
            FactStatus::Corrected,
            [Flag::supersededCount(1), Flag::censored()],
            $citations,
        );

        $reversed = new Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            FactKind::Result,
            3,
            new \DateTimeImmutable('2025-05-08'),
            DateSource::Collected,
            $value,
            FactStatus::Corrected,
            [Flag::censored(), Flag::supersededCount(1)],
            $citations,
        );

        self::assertSame(
            CanonicalSerializer::serializeFacts([$forward]),
            CanonicalSerializer::serializeFacts([$reversed]),
        );
    }

    /**
     * U2's noted bug: a whole-number float (e.g. an A1c of 8.0) must not
     * silently become the integer 8 in serialized output -- that would
     * change the wire type and could misrender as "8" instead of "8.0".
     */
    public function testWholeNumberFloatsPreserveZeroFraction(): void
    {
        $fact = FactTestFactory::a1cTrendPoint(pid: 1, resultPk: 1, raw: '8.0');

        $bytes = CanonicalSerializer::serializeFacts([$fact]);

        self::assertStringContainsString('8.0', $bytes);
        self::assertStringNotContainsString(':8,', $bytes);
    }

    public function testAssociativeArrayKeyOrderDoesNotAffectGenericSerialization(): void
    {
        $forward = ['b' => 1, 'a' => 2, 'c' => ['z' => 1, 'y' => 2]];
        $reversed = ['a' => 2, 'c' => ['y' => 2, 'z' => 1], 'b' => 1];

        self::assertSame(
            CanonicalSerializer::serializeValue($forward),
            CanonicalSerializer::serializeValue($reversed),
        );
    }

    public function testListArrayOrderIsPreservedByGenericCanonicalization(): void
    {
        // canonicalizeValue() is a generic helper; it intentionally does NOT
        // reorder plain lists (only Fact-aware callers like
        // canonicalizeFacts() impose a canonical order on facts/flags/
        // citations). This guards against silently masking a real ordering
        // bug in a list that legitimately carries meaning.
        self::assertNotSame(
            CanonicalSerializer::serializeValue([1, 2, 3]),
            CanonicalSerializer::serializeValue([3, 2, 1]),
        );
    }
}
