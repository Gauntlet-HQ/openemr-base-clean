<?php

/**
 * The result of one ToolExecutor::execute() call -- facts, or a named failure.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Tool;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

/**
 * ARCHITECTURE.md §1.3: "a tool failure is reported to the model AND the
 * user ... never silently absorbed." `$ok` is false for every failure mode
 * (unrecognized tool name, schema-invalid arguments, a pid-assertion trip,
 * or the capability itself throwing) -- {@see self::$errorMessage} is always
 * the SAME text surfaced to the model (as this call's tool result) and to
 * the user (as a named banner), so there is exactly one failure message per
 * outcome, never two that could drift apart.
 */
final readonly class ToolCallOutcome
{
    /**
     * @param list<Fact> $facts empty when `$ok` is false
     */
    private function __construct(
        public string $toolName,
        public bool $ok,
        public array $facts,
        public ?string $errorMessage,
    ) {
    }

    /**
     * @param list<Fact> $facts
     */
    public static function ok(string $toolName, array $facts): self
    {
        return new self($toolName, true, $facts, null);
    }

    public static function failed(string $toolName, string $errorMessage): self
    {
        return new self($toolName, false, [], $errorMessage);
    }
}
