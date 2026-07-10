<?php

/**
 * One structured tool-call request the model emitted (I13: a request, never an execution).
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
 * `name` is the raw string the model emitted -- NOT yet resolved against
 * {@see ToolName} or {@see ToolCatalog} -- an unrecognized name is itself a
 * validation finding {@see \OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolExecutor}
 * surfaces the same way a schema-invalid argument set is (ARCHITECTURE.md
 * §1.2), never a fatal error.
 */
final readonly class ToolCallRequest
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments,
    ) {
    }
}
