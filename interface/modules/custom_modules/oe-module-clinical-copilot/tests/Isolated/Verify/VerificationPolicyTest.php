<?php

/**
 * The verifier gate is enforced by default and the env override works in both directions.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Verify;

use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: the hard gate silently regressing to fail-open. The
 * Week-2 posture is enforced-by-default (docs/SECURITY.md finding #1 -- the
 * earlier "off for QA retuning" default was deliberately reverted for the
 * submission); this test pins that default AND pins that
 * CLINICAL_COPILOT_VERIFY_ENFORCE remains a two-way switch, so QA can still
 * relax with `=0` and an operator can force-enable with `=1`.
 */
final class VerificationPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('CLINICAL_COPILOT_VERIFY_ENFORCE');
    }

    protected function tearDown(): void
    {
        putenv('CLINICAL_COPILOT_VERIFY_ENFORCE');
    }

    public function testGateIsEnforcedByDefaultWithNoEnvOverride(): void
    {
        self::assertTrue(VerificationPolicy::gateEnforced(), 'the V1-V6 hard gate must be ON out of the box');
    }

    #[DataProvider('overrideProvider')]
    public function testEnvOverrideWorksInBothDirections(string $value, bool $expected): void
    {
        putenv("CLINICAL_COPILOT_VERIFY_ENFORCE={$value}");

        self::assertSame($expected, VerificationPolicy::gateEnforced());
    }

    /**
     * @return array<string, array{string, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function overrideProvider(): array
    {
        return [
            'disable with 0' => ['0', false],
            'disable with false' => ['false', false],
            'disable with off' => ['off', false],
            'enable with 1' => ['1', true],
            'enable with true' => ['true', true],
            'enable with yes' => ['yes', true],
            'enable with on' => ['on', true],
        ];
    }
}
