<?php

/**
 * GeminiApiSchemaTranslator: adapts Claim JSON Schema for AI Studio responseSchema.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce;

use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\GeminiApiSchemaTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: sending the full JSON Schema Claim::jsonSchema() to
 * Google AI Studio's responseSchema field (400 on $schema, additionalProperties,
 * union type arrays). Vertex keeps the untranslated schema.
 */
final class GeminiApiSchemaTranslatorTest extends TestCase
{
    public function testStripsUnsupportedKeywordsFromToolCatalogSchemas(): void
    {
        foreach (\OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCatalog::all() as $tool) {
            $translated = GeminiApiSchemaTranslator::translate($tool->parameters);
            self::assertArrayNotHasKey('additionalProperties', $translated);
            self::assertArrayNotHasKey('minLength', $translated);
            self::assertArrayNotHasKey('minimum', $translated);
        }
    }

    public function testEmptyObjectPropertiesEncodeAsMapNotList(): void
    {
        $translated = GeminiApiSchemaTranslator::translate([
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [],
            'properties' => [],
        ]);

        self::assertArrayHasKey('properties', $translated);
        self::assertInstanceOf(\stdClass::class, $translated['properties']);
        self::assertSame('{}', json_encode($translated['properties'], JSON_THROW_ON_ERROR));
    }

    public function testStripsUnsupportedKeywordsFromClaimSchema(): void
    {
        $translated = GeminiApiSchemaTranslator::translate(Claim::jsonSchema());

        self::assertArrayNotHasKey('$schema', $translated);
        self::assertArrayNotHasKey('title', $translated);
        self::assertArrayNotHasKey('additionalProperties', $translated);

        /** @var array<string, mixed> $itemSchema */
        $itemSchema = $translated['items'];
        self::assertIsArray($itemSchema);
        self::assertArrayNotHasKey('additionalProperties', $itemSchema);

        /** @var array<string, mixed> $properties */
        $properties = $itemSchema['properties'];
        self::assertIsArray($properties);

        /** @var array<string, mixed> $emphasis */
        $emphasis = $properties['emphasis'];
        self::assertSame('string', $emphasis['type']);
        self::assertTrue($emphasis['nullable']);
        self::assertArrayNotHasKey('0', $emphasis);
    }

    public function testNullableUnionBecomesNullableFlag(): void
    {
        $translated = GeminiApiSchemaTranslator::translate([
            'type' => 'object',
            'properties' => [
                'note' => [
                    'type' => ['string', 'null'],
                ],
            ],
        ]);

        /** @var array<string, mixed> $note */
        $note = $translated['properties']['note'];
        self::assertSame('string', $note['type']);
        self::assertTrue($note['nullable']);
    }
}
