<?php

/**
 * Lab contract C3: value parsing and censoring grammar.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Lab\ValueParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a censored lab value ("<7.0") ever being presented,
 * trended, or thresholded as if it were an exact reading -- the whole point
 * of C3's grammar is that a comparator changes what claim is even
 * permissible over a value.
 */
final class ValueParserTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: float, 2: string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function censoredValues(): iterable
    {
        yield 'less-than' => ['<7.0', 7.0, 'lt'];
        yield 'less-than-or-equal' => ['<=7.0', 7.0, 'lte'];
        yield 'greater-than' => ['>9.5', 9.5, 'gt'];
        yield 'greater-than-or-equal' => ['>=9.5', 9.5, 'gte'];
        yield 'whitespace-tolerant' => ['<   7.0', 7.0, 'lt'];
        yield 'trailing unit token ignored' => ['<7.0 %', 7.0, 'lt'];
    }

    #[DataProvider('censoredValues')]
    public function testCensoredGrammarParsesComparatorAndBound(string $raw, float $expectedParsed, string $expectedComparator): void
    {
        $result = ValueParser::parse($raw, 'N');

        self::assertSame($expectedParsed, $result->parsed);
        self::assertSame($expectedComparator, $result->comparator->value);
        self::assertTrue($result->comparator->isCensored());
    }

    public function testUncensoredValueHasNoneComparator(): void
    {
        $result = ValueParser::parse('7.2', 'N');

        self::assertSame(7.2, $result->parsed);
        self::assertSame(Comparator::None, $result->comparator);
        self::assertFalse($result->comparator->isCensored());
    }

    /**
     * @return iterable<string, array{0: string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function nonNumericTypes(): iterable
    {
        yield 'F formatted' => ['F'];
        yield 'E external' => ['E'];
        yield 'L long text' => ['L'];
    }

    #[DataProvider('nonNumericTypes')]
    public function testNonNumericResultDataTypeNeverParsesEvenIfTextLooksNumeric(string $resultDataType): void
    {
        $result = ValueParser::parse('7.2', $resultDataType);

        self::assertNull($result->parsed);
        self::assertFalse($result->numericTypeEligible);
        self::assertFalse($result->isUnparseable(), 'F/E/L types are qualitative by type, not "unparseable"');
    }

    public function testUnparseableNumericEligibleValueYieldsNullParsedNotAGuess(): void
    {
        $result = ValueParser::parse('Positive', 'N');

        self::assertNull($result->parsed);
        self::assertTrue($result->numericTypeEligible);
        self::assertTrue($result->isUnparseable());
    }

    public function testEmptyResultIsUnparseableNotZero(): void
    {
        $result = ValueParser::parse('', 'N');

        self::assertNull($result->parsed);
        self::assertTrue($result->isUnparseable());
    }

    public function testStringDataTypeIsAlsoNumericEligible(): void
    {
        $result = ValueParser::parse('110', 'S');

        self::assertSame(110.0, $result->parsed);
        self::assertTrue($result->numericTypeEligible);
    }
}
