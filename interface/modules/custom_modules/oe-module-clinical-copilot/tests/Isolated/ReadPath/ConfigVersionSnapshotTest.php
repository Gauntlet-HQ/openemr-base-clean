<?php

/**
 * ConfigVersionSnapshot: every cadence/threshold/conversion/turnaround version becomes a digest input.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Capability\Config\LabTurnaroundConfig;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\LabContractConfig;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\Threshold;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\ThresholdDirection;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\ConfigVersionSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * E5 (config drift invalidates affected docs): every key this class emits
 * is independently folded into {@see \OpenEMR\Modules\ClinicalCopilot\Fact\Digest::compute()}'s
 * `$configVersions` map by the read path, so bumping any ONE of them changes
 * the digest (proven at the Digest level already by
 * tests/Isolated/Fact/DigestTest.php::testConfigVersionBumpChangesDigest);
 * this test guards that {@see ConfigVersionSnapshot} actually surfaces every
 * key that participates, pure and DB-free.
 */
final class ConfigVersionSnapshotTest extends TestCase
{
    public function testEveryCadenceThresholdConversionAndTurnaroundVersionIsSurfaced(): void
    {
        $labConfig = new LabContractConfig(
            ['4548-4' => 'a1c'],
            ['a1c' => '%'],
            [],
            'conv-v4',
            [
                '4548-4' => 'cadence-a1c-v1',
                '14957-5' => 'cadence-acr-v2',
                '2093-3' => 'cadence-lipids-v3',
            ],
            [
                '4548-4' => 'cadence-a1c-v1',
                '14957-5' => 'cadence-acr-v2',
                '2093-3' => 'cadence-lipids-v3',
            ],
            ['a1c' => new Threshold(7.0, ThresholdDirection::High, 'threshold-a1c-v5')],
        );
        $turnaroundConfig = new LabTurnaroundConfig(3, [], 'turnaround-v6');

        $versions = ConfigVersionSnapshot::build($labConfig, $turnaroundConfig);

        self::assertSame(
            [
                'cadence:a1c' => 'cadence-a1c-v1',
                'cadence:acr' => 'cadence-acr-v2',
                'cadence:lipids' => 'cadence-lipids-v3',
                'lab_turnaround' => 'turnaround-v6',
                'threshold:a1c' => 'threshold-a1c-v5',
                'unit_conversion' => 'conv-v4',
            ],
            $versions,
        );
    }

    public function testEmptyConversionVersionIsOmittedRatherThanStoredAsAnEmptyString(): void
    {
        $labConfig = new LabContractConfig([], [], [], '', [], [], []);
        $turnaroundConfig = new LabTurnaroundConfig(3, [], 'turnaround-v1');

        $versions = ConfigVersionSnapshot::build($labConfig, $turnaroundConfig);

        self::assertArrayNotHasKey('unit_conversion', $versions);
        self::assertSame(['lab_turnaround' => 'turnaround-v1'], $versions);
    }
}
