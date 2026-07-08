<?php

/**
 * The LOINC code sets each U5 capability declares (ARCHITECTURE_COMPLETE.md
 * "Capabilities" table).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability\Config;

/**
 * Single, shared source of the LOINC codes the five capabilities read, so
 * the three capabilities that overlap on the diabetes monitoring panel
 * (ControlProxy, OverdueTests, PendingResults) never drift out of sync with
 * each other on what "the panel" means. Each capability's own code set is a
 * documented, deliberate subset (see each method's docblock) -- not simply
 * "all codes", per the capability table's per-capability source column.
 */
final class AnalyteCodeSets
{
    private function __construct()
    {
        // static-only
    }

    public const LOINC_A1C = '4548-4';
    public const LOINC_GLUCOSE = '2345-7';
    public const LOINC_CHOL_TOTAL = '2093-3';
    public const LOINC_LDL = '18262-6';
    public const LOINC_HDL = '2085-9';
    public const LOINC_TRIGLYCERIDES = '2571-8';
    public const LOINC_ACR = '14957-5';

    /** @var list<string> */
    public const LIPIDS = [self::LOINC_CHOL_TOTAL, self::LOINC_LDL, self::LOINC_HDL, self::LOINC_TRIGLYCERIDES];

    /**
     * ControlProxy (UC1, UC2): A1c, glucose, and the four lipid-panel codes.
     * Deliberately excludes ACR -- ACR has no numeric "control target"
     * narrative the way A1c/glucose/lipids do; it is OverdueTests'/
     * PendingResults' domain only.
     *
     * @return list<string>
     */
    public static function controlProxyCodes(): array
    {
        return [self::LOINC_A1C, self::LOINC_GLUCOSE, ...self::LIPIDS];
    }

    /**
     * OverdueTests (UC1, UC4): every cadence-governed code -- ACR, A1c, and
     * the lipid panel. Glucose is deliberately excluded: it has no
     * `mod_copilot_cadence` cadence row (no clinically meaningful "overdue"
     * concept for a point-in-time glucose check the way there is for an
     * annual/quarterly monitoring lab), so it is not "overdue-able".
     *
     * @return list<string>
     */
    public static function overdueCodes(): array
    {
        return [self::LOINC_ACR, self::LOINC_A1C, ...self::LIPIDS];
    }

    /**
     * PendingResults (UC1, UC4, UC5): the union of every code any other
     * capability monitors. A drawn-but-unresulted or preliminary result for
     * ANY of them is "what's in flight" -- UC5 is not scoped to one analyte
     * domain the way ControlProxy/OverdueTests are.
     *
     * @return list<string>
     */
    public static function pendingCodes(): array
    {
        return [self::LOINC_ACR, self::LOINC_A1C, self::LOINC_GLUCOSE, ...self::LIPIDS];
    }

    /**
     * Maps a LOINC code to its `mod_copilot_cadence` monitoring bucket key
     * (`a1c` / `acr` / `lipids`) -- the SAME granularity `cadence:*` rows and
     * the `lab_turnaround` config's `per_analyte_days` use. Deliberately
     * coarser than {@see \OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider}'s
     * unit-conversion/threshold bucketing (which splits "lipids" into
     * "cholesterol"/"triglycerides") -- these are two independent config
     * concerns bucketed at two different granularities, by design (see that
     * class's docblock and this module's table.sql threshold-seed comment).
     */
    public static function cadenceBucketForLoinc(string $loincCode): ?string
    {
        return match ($loincCode) {
            self::LOINC_A1C => 'a1c',
            self::LOINC_ACR => 'acr',
            self::LOINC_CHOL_TOTAL, self::LOINC_LDL, self::LOINC_HDL, self::LOINC_TRIGLYCERIDES => 'lipids',
            default => null,
        };
    }
}
