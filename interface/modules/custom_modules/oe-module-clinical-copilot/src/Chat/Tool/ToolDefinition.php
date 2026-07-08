<?php

/**
 * One tool's schema'd contract: name, description, and its strict JSON Schema.
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
 * Mirrors ARCHITECTURE.md §1.2's table exactly: a strict JSON Schema per
 * tool (no `pid` property anywhere -- I10, the tool executor injects the
 * session's pinned pid server-side) validated by {@see ToolArgumentValidator}
 * before a single capability method is ever called, and handed to
 * {@see \OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmClientInterface}
 * implementations as the provider's native function-calling declaration
 * (T18: "native function calling for the five tools").
 */
final readonly class ToolDefinition
{
    /**
     * @param array<string, mixed> $parameters JSON Schema (draft 2020-12 style, `type: object`)
     */
    public function __construct(
        public ToolName $name,
        public string $description,
        public array $parameters,
    ) {
    }

    /**
     * The shape handed to a provider's function-calling declaration
     * (`{name, description, parameters}`) -- deliberately the same shape
     * regardless of provider, so {@see \OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmClientInterface}
     * implementations never need this class's internals, only this array.
     *
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    public function toDeclaration(): array
    {
        return [
            'name' => $this->name->value,
            'description' => $this->description,
            'parameters' => $this->parameters,
        ];
    }
}
