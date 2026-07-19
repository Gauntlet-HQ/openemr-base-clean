<?php

/**
 * The one, closed list of chat tools and their strict input schemas.
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
 * ARCHITECTURE.md §1.2's tool table, verbatim. `additionalProperties: false`
 * on every schema is load-bearing: it is what lets a forged `pid` argument
 * (an adversarial attempt to escape the session's pinned patient, USERS.md
 * §1/UC6) be rejected by {@see ToolArgumentValidator} before the tool
 * executor ever runs -- no tool schema declares a `pid` property, so no
 * amount of prompt pressure can make one appear in a validated call (I10).
 */
final class ToolCatalog
{
    private function __construct()
    {
        // static-only
    }

    /**
     * @return list<ToolDefinition>
     */
    public static function all(): array
    {
        return [
            new ToolDefinition(
                ToolName::GetControlTrend,
                'Retrieves the A1c, glucose, or lipid-panel trend for the pinned patient over a trailing window. '
                    . 'Wraps the ControlProxy capability -- the same deterministic extraction behind the synthesis doc.',
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['analyte', 'window_months'],
                    'properties' => [
                        'analyte' => ['type' => 'string', 'enum' => ['a1c', 'glucose', 'lipids']],
                        'window_months' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60],
                    ],
                ],
            ),
            new ToolDefinition(
                ToolName::GetMedHistory,
                'Retrieves medication events (both in-house prescriptions and outside/reconciled meds) paired with '
                    . 'subsequent A1c movement for the pinned patient, optionally filtered by drug name substring, '
                    . 'over a trailing window. Wraps the MedResponse capability.',
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['window_months'],
                    'properties' => [
                        'drug_filter' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                        'window_months' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60],
                    ],
                ],
            ),
            new ToolDefinition(
                ToolName::GetVitalsTrend,
                'Retrieves the weight, blood pressure, or BMI trend for the pinned patient over a trailing window. '
                    . 'Wraps the VitalsTrend capability.',
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['metric', 'window_months'],
                    'properties' => [
                        'metric' => ['type' => 'string', 'enum' => ['weight', 'bp', 'bmi']],
                        'window_months' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60],
                    ],
                ],
            ),
            new ToolDefinition(
                ToolName::GetOverdue,
                'Retrieves every monitoring test currently overdue for the pinned patient, with reorder-suppression '
                    . 'notes where an active pending order already covers it. Wraps the OverdueTests capability. Takes no arguments.',
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [],
                    'properties' => [],
                ],
            ),
            new ToolDefinition(
                ToolName::GetPending,
                'Retrieves every active unresulted order and preliminary result for the pinned patient. '
                    . 'Wraps the PendingResults capability. Takes no arguments.',
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [],
                    'properties' => [],
                ],
            ),
        ];
    }

    public static function find(string $name): ?ToolDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->name->value === $name) {
                return $definition;
            }
        }

        return null;
    }
}
