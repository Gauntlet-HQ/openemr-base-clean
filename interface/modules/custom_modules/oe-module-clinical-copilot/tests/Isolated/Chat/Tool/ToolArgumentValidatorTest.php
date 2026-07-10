<?php

/**
 * Adversarial + contract: strict tool-argument validation, including a forged pid argument.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat\Tool;

use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCatalog;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolArgumentValidator;
use PHPUnit\Framework\TestCase;

/**
 * I10: "No tool takes a patient identifier." None of {@see ToolCatalog}'s
 * schemas declare a `pid` property, and every schema sets
 * `additionalProperties: false` -- {@see self::testForgedPidArgumentIsRejected()}
 * is the adversarial proof that a forged `pid` argument (an attempt to
 * escape the session's pinned patient purely via tool-call arguments,
 * USERS.md UC6) is rejected here, before {@see \OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolExecutor}
 * ever dispatches to a capability.
 */
final class ToolArgumentValidatorTest extends TestCase
{
    public function testForgedPidArgumentIsRejected(): void
    {
        $definition = ToolCatalog::find('get_control_trend');
        self::assertNotNull($definition);

        $findings = ToolArgumentValidator::validate($definition, ['analyte' => 'a1c', 'window_months' => 6, 'pid' => 999]);

        self::assertNotEmpty($findings);
        self::assertStringContainsString("unrecognized argument 'pid'", $findings[0]);
    }

    public function testValidArgumentsProduceNoFindings(): void
    {
        $definition = ToolCatalog::find('get_control_trend');
        self::assertNotNull($definition);

        self::assertSame([], ToolArgumentValidator::validate($definition, ['analyte' => 'a1c', 'window_months' => 6]));
    }

    public function testMissingRequiredArgumentIsRejected(): void
    {
        $definition = ToolCatalog::find('get_vitals_trend');
        self::assertNotNull($definition);

        $findings = ToolArgumentValidator::validate($definition, ['metric' => 'weight']);

        self::assertNotEmpty($findings);
        self::assertStringContainsString("missing required argument 'window_months'", $findings[0]);
    }

    public function testEnumViolationIsRejected(): void
    {
        $definition = ToolCatalog::find('get_control_trend');
        self::assertNotNull($definition);

        $findings = ToolArgumentValidator::validate($definition, ['analyte' => 'cortisol', 'window_months' => 6]);

        self::assertNotEmpty($findings);
        self::assertStringContainsString("argument 'analyte' must be one of", $findings[0]);
    }

    public function testOutOfRangeIntegerIsRejected(): void
    {
        $definition = ToolCatalog::find('get_control_trend');
        self::assertNotNull($definition);

        $findings = ToolArgumentValidator::validate($definition, ['analyte' => 'a1c', 'window_months' => 999]);

        self::assertNotEmpty($findings);
        self::assertStringContainsString("argument 'window_months' must be <=", $findings[0]);
    }

    public function testNoArgumentToolsAcceptAnEmptyArray(): void
    {
        $overdue = ToolCatalog::find('get_overdue');
        $pending = ToolCatalog::find('get_pending');
        self::assertNotNull($overdue);
        self::assertNotNull($pending);

        self::assertSame([], ToolArgumentValidator::validate($overdue, []));
        self::assertSame([], ToolArgumentValidator::validate($pending, []));
    }
}
