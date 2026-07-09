<?php

/**
 * Hand-rolled strict validation of one tool call's arguments against its JSON Schema.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Tool;

/**
 * No general-purpose JSON Schema library is a module dependency (composer.json
 * carries only `guzzlehttp/guzzle` and `google/auth` -- deliberately, per the
 * same "hand-pinned over a heavy dependency" reasoning T18 states for the
 * Vertex REST contract). {@see ToolDefinition::$parameters} is a small,
 * closed shape (object / required / properties / enum / integer min-max) --
 * this class validates exactly that subset, no more, mirroring
 * {@see \OpenEMR\Modules\ClinicalCopilot\Verify\ClaimSchema}'s own choice to
 * hand-parse rather than depend on a schema engine for one well-known shape.
 *
 * ARCHITECTURE.md §1.2: "Schema-invalid tool calls are rejected back to the
 * model with the validation error (one retry), then surfaced as a tool
 * failure." This class only answers "is this call valid" with a specific
 * finding list; {@see \OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop} owns
 * the one-retry-then-failure policy (the model's very next round, with the
 * finding fed back as this call's tool result, IS that one retry -- see that
 * class's docblock).
 */
final class ToolArgumentValidator
{
    private function __construct()
    {
        // static-only
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<string> validation findings; empty means valid
     */
    public static function validate(ToolDefinition $definition, array $arguments): array
    {
        $schema = $definition->parameters;
        $findings = [];

        /** @var array<string, array<string, mixed>> $properties */
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        /** @var list<string> $required */
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $allowAdditional = ($schema['additionalProperties'] ?? true) !== false;

        foreach ($required as $requiredKey) {
            if (!array_key_exists($requiredKey, $arguments)) {
                $findings[] = "missing required argument '{$requiredKey}'";
            }
        }

        foreach ($arguments as $key => $value) {
            if (!array_key_exists($key, $properties)) {
                if (!$allowAdditional) {
                    $findings[] = "unrecognized argument '{$key}' -- this tool declares no such property (I10: no tool accepts a patient identifier)";
                }
                continue;
            }

            $findings = [...$findings, ...self::validateProperty($key, $value, $properties[$key])];
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return list<string>
     */
    private static function validateProperty(string $key, mixed $value, array $propertySchema): array
    {
        $type = $propertySchema['type'] ?? null;
        $findings = [];

        switch ($type) {
            case 'integer':
                if (!is_int($value)) {
                    $findings[] = "argument '{$key}' must be an integer";
                    break;
                }
                if (isset($propertySchema['minimum']) && $value < $propertySchema['minimum']) {
                    $findings[] = "argument '{$key}' must be >= {$propertySchema['minimum']}, got {$value}";
                }
                if (isset($propertySchema['maximum']) && $value > $propertySchema['maximum']) {
                    $findings[] = "argument '{$key}' must be <= {$propertySchema['maximum']}, got {$value}";
                }
                break;
            case 'string':
                if (!is_string($value)) {
                    $findings[] = "argument '{$key}' must be a string";
                    break;
                }
                if (isset($propertySchema['enum']) && is_array($propertySchema['enum']) && !in_array($value, $propertySchema['enum'], true)) {
                    $allowed = implode(', ', $propertySchema['enum']);
                    $findings[] = "argument '{$key}' must be one of [{$allowed}], got '{$value}'";
                }
                if (isset($propertySchema['minLength']) && strlen($value) < $propertySchema['minLength']) {
                    $findings[] = "argument '{$key}' is shorter than the minimum length {$propertySchema['minLength']}";
                }
                if (isset($propertySchema['maxLength']) && strlen($value) > $propertySchema['maxLength']) {
                    $findings[] = "argument '{$key}' exceeds the maximum length {$propertySchema['maxLength']}";
                }
                break;
            default:
                // No other property types appear in this catalog's schemas
                // (ToolCatalog) -- an unrecognized declared type is a config
                // bug, not a user-facing validation outcome, so it is
                // deliberately not treated as an argument failure here.
        }

        return $findings;
    }
}
