<?php

/**
 * Builds Digest::compute()'s $configVersions map from the lab-contract and lab-turnaround config.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Capability\Config\AnalyteCodeSets;
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\LabTurnaroundConfig;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\LabContractConfig;

/**
 * Mirrors the SAME reasoning {@see \OpenEMR\Modules\ClinicalCopilot\Fact\Digest}'s
 * own isolated test asserts for `capabilityVersions`
 * (`testCapabilityVersionBumpChangesDigestEvenForACapabilityWithNoFacts`,
 * tests/Isolated/Fact/DigestTest.php): a capability's version is a digest
 * input EVEN for a capability that produced zero facts for this patient,
 * because a version bump could turn a previously-excluded row into a
 * presented one on the NEXT read with no new data at all. The same argument
 * applies here -- ControlProxy/OverdueTests/PendingResults attempt EVERY
 * configured code for EVERY patient regardless of whether a fact results
 * (a threshold bump could flip an out-of-range flag with no new draw), so
 * every cadence/threshold/conversion/turnaround version this read path's
 * config providers expose participates in every extraction, not only the
 * ones whose code happens to have data today. Pure and deterministic (E6);
 * every key here is independently a digest input (E5).
 */
final class ConfigVersionSnapshot
{
    private function __construct()
    {
        // static-only
    }

    /**
     * @return array<string, string>
     */
    public static function build(LabContractConfig $labContractConfig, LabTurnaroundConfig $turnaroundConfig): array
    {
        $versions = [];

        foreach ($labContractConfig->cadenceVersionByLoinc as $loincCode => $version) {
            $bucket = AnalyteCodeSets::cadenceBucketForLoinc($loincCode);
            if ($bucket !== null) {
                $versions["cadence:{$bucket}"] = $version;
            }
        }

        if ($labContractConfig->conversionVersion !== '') {
            $versions['unit_conversion'] = $labContractConfig->conversionVersion;
        }

        foreach ($labContractConfig->thresholdByAnalyte as $analyte => $threshold) {
            $versions["threshold:{$analyte}"] = $threshold->version;
        }

        $versions['lab_turnaround'] = $turnaroundConfig->version;

        ksort($versions, SORT_STRING);

        return $versions;
    }
}
