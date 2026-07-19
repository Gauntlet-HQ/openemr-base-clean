<?php

/**
 * Validates emitted Facts (and known-bad shapes) against fact.schema.json --
 * the schema, not the PHP implementation, is the contract (ARCHITECTURE.md R3).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact;

use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: the PHP Fact class and the shipped JSON Schema
 * drifting apart silently -- e.g. a future edit adds a field to Fact::toArray()
 * without updating fact.schema.json (or vice versa), and downstream
 * consumers (U7 prompt assembly, U10 verifier, any external tooling that
 * validates against the schema file directly) stop agreeing on what a valid
 * Fact looks like.
 */
final class FactSchemaValidationTest extends TestCase
{
    private const SCHEMA_PATH = __DIR__ . '/../../../src/Fact/schema/fact.schema.json';

    /**
     * @return iterable<string, array{0: \OpenEMR\Modules\ClinicalCopilot\Fact\Fact}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function validFacts(): iterable
    {
        yield 'trend_point with numeric value' => [FactTestFactory::a1cTrendPoint()];
        yield 'censored result' => [FactTestFactory::censoredResult()];
        yield 'unitless exclusion' => [FactTestFactory::unitlessExclusion()];
        yield 'med_event with null value' => [FactTestFactory::medEvent()];
        yield 'superseding correction with two citations' => [FactTestFactory::supersedingCorrection()];
    }

    #[DataProvider('validFacts')]
    public function testValidFactValidatesAgainstSchema(\OpenEMR\Modules\ClinicalCopilot\Fact\Fact $fact): void
    {
        $validator = $this->validate($fact->toArray());

        self::assertTrue($validator->isValid(), $this->errorString($validator));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidShapes(): iterable
    {
        $base = FactTestFactory::a1cTrendPoint()->toArray();

        yield 'empty citations array' => [
            array_merge($base, ['citations' => []]),
            'citations',
        ];
        yield 'unrecognized capability' => [
            array_merge($base, ['capability' => 'not_a_capability']),
            'capability',
        ];
        yield 'unrecognized flag string' => [
            array_merge($base, ['flags' => ['not_a_real_flag']]),
            'flags',
        ];
        yield 'missing required field (status)' => [
            (static function (array $data): array {
                unset($data['status']);
                return $data;
            })($base),
            'status',
        ];
        yield 'additional undeclared property' => [
            array_merge($base, ['unexpected_extra_field' => true]),
            'additional',
        ];
    }

    #[DataProvider('invalidShapes')]
    public function testInvalidShapeFailsSchemaValidation(array $data, string $expectedInErrors): void
    {
        $validator = $this->validate($data);

        self::assertFalse($validator->isValid(), 'Expected shape to fail validation but it passed.');
        self::assertStringContainsString($expectedInErrors, strtolower($this->errorString($validator)));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validate(array $data): Validator
    {
        $payload = json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $schema = json_decode(
            (string)file_get_contents(self::SCHEMA_PATH),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );

        $validator = new Validator();
        $validator->validate($payload, $schema);

        return $validator;
    }

    private function errorString(Validator $validator): string
    {
        return 'Errors: ' . json_encode($validator->getErrors(), JSON_THROW_ON_ERROR);
    }
}
