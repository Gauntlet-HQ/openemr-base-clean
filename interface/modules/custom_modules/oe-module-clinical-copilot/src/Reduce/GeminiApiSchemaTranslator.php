<?php

/**
 * Adapts internal JSON Schema shapes to Google AI Studio's OpenAPI subset.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

/**
 * {@see Claim::jsonSchema()} and other module schemas are authored as JSON
 * Schema for the verifier (V1) and for Vertex structured output. Google AI
 * Studio's `responseSchema` rejects several standard JSON Schema keywords
 * (`$schema`, `additionalProperties`, union `type` arrays). This translator
 * is applied ONLY on the {@see GeminiApiLlmClient} /
 * {@see \OpenEMR\Modules\ClinicalCopilot\Chat\Llm\GeminiApiChatLlmClient}
 * path so Vertex keeps the full schema unchanged.
 */
final class GeminiApiSchemaTranslator
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public static function translate(array $schema): array
    {
        return self::translateNode($schema);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function translateNode(array $schema): array
    {
        $out = [];

        foreach ($schema as $key => $value) {
            if (in_array($key, ['$schema', '$id', 'title', 'description', 'additionalProperties', 'minLength', 'minimum'], true)) {
                continue;
            }

            if ($key === 'type' && is_array($value)) {
                $nullable = in_array('null', $value, true);
                $nonNullTypes = array_values(array_filter(
                    $value,
                    static fn (mixed $t): bool => is_string($t) && $t !== 'null',
                ));
                if ($nonNullTypes === []) {
                    $out['type'] = 'string';
                    $out['nullable'] = true;
                    continue;
                }
                $out['type'] = $nonNullTypes[0];
                if ($nullable) {
                    $out['nullable'] = true;
                }
                continue;
            }

            if ($key === 'properties' && is_array($value)) {
                /** @var array<string, mixed> $properties */
                $properties = $value;
                $translated = [];
                foreach ($properties as $propertyName => $propertySchema) {
                    if (is_array($propertySchema)) {
                        /** @var array<string, mixed> $propertySchema */
                        $translated[$propertyName] = self::translateNode($propertySchema);
                    }
                }
                if ($translated === []) {
                    // Empty PHP arrays JSON-encode as [] but Gemini expects properties as a map.
                    $out['properties'] = new \stdClass();
                } else {
                    $out['properties'] = $translated;
                }
                continue;
            }

            if ($key === 'items' && is_array($value)) {
                /** @var array<string, mixed> $value */
                $out['items'] = self::translateNode($value);
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
