<?php

/**
 * Lab contract C4: canonical units and the conversion whitelist.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Lab;

use OpenEMR\Modules\ClinicalCopilot\Lab\UnitConverter;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a unit conversion silently applied to the wrong
 * factor, or "no unit, no math" quietly bypassed for a value that happens
 * to look numeric. C4 is strict on purpose (T9): an IFCC A1c of 48 must
 * never render as "48%".
 */
final class UnitConverterTest extends TestCase
{
    public function testIfccToNgspConversionMatchesTheSeededExample(): void
    {
        $result = UnitConverter::convert('a1c', 'mmol/mol', 58.0, LabContractTestConfig::default());

        self::assertFalse($result->excluded);
        self::assertSame('%', $result->unitCanonical);
        self::assertSame('v1', $result->conversionVersion);
        self::assertEqualsWithDelta(7.5, $result->convertedValue, 0.001);
    }

    public function testUnitAlreadyCanonicalAppliesNoConversion(): void
    {
        $result = UnitConverter::convert('a1c', '%', 7.2, LabContractTestConfig::default());

        self::assertFalse($result->excluded);
        self::assertSame('%', $result->unitCanonical);
        self::assertNull($result->conversionVersion, 'no conversion was actually applied, so conversion_version must stay null');
        self::assertSame(7.2, $result->convertedValue);
    }

    public function testEmptyUnitIsExcludedForAGovernedAnalyte(): void
    {
        $result = UnitConverter::convert('glucose', '', 110.0, LabContractTestConfig::default());

        self::assertTrue($result->excluded);
        self::assertNull($result->unitCanonical);
        self::assertNull($result->convertedValue);
    }

    public function testUnrecognizedUnitIsExcludedNeverGuessed(): void
    {
        $result = UnitConverter::convert('a1c', 'furlongs', 7.2, LabContractTestConfig::default());

        self::assertTrue($result->excluded);
    }

    /**
     * UnitConverter itself always excludes an analyte it has no canonical
     * unit for -- it has no notion of "ungoverned passthrough". That policy
     * (e.g. ACR is presented verbatim despite having no conversion config)
     * lives one layer up, in LabRowProcessor::resolveUnitConversion(), which
     * only calls UnitConverter for analytes it already knows are governed.
     * See LabRowProcessorTest for the ACR passthrough behavior.
     */
    public function testUnknownAnalyteWithNoCanonicalConfigIsExcluded(): void
    {
        $result = UnitConverter::convert('made_up_analyte', 'widgets', 1.0, LabContractTestConfig::default());

        self::assertTrue($result->excluded);
    }

    public function testGlucoseMmolPerLiterConversion(): void
    {
        $result = UnitConverter::convert('glucose', 'mmol/L', 6.1, LabContractTestConfig::default());

        self::assertFalse($result->excluded);
        self::assertSame('mg/dL', $result->unitCanonical);
        self::assertEqualsWithDelta(6.1 * 18.018, $result->convertedValue, 0.001);
    }

    public function testCholesterolMmolPerLiterConversion(): void
    {
        $result = UnitConverter::convert('cholesterol', 'mmol/L', 5.0, LabContractTestConfig::default());

        self::assertFalse($result->excluded);
        self::assertEqualsWithDelta(5.0 * 38.67, $result->convertedValue, 0.001);
    }

    public function testTriglyceridesMmolPerLiterConversion(): void
    {
        $result = UnitConverter::convert('triglycerides', 'mmol/L', 1.5, LabContractTestConfig::default());

        self::assertFalse($result->excluded);
        self::assertEqualsWithDelta(1.5 * 88.57, $result->convertedValue, 0.001);
    }

    /**
     * A null raw value (unparseable text) with an otherwise-convertible unit
     * still reports the intended canonical unit + version but performs no
     * arithmetic on nothing.
     */
    public function testNullRawValueWithConvertibleUnitPerformsNoConversionButStillReportsCanonicalUnit(): void
    {
        $result = UnitConverter::convert('a1c', 'mmol/mol', null, LabContractTestConfig::default());

        self::assertFalse($result->excluded);
        self::assertSame('%', $result->unitCanonical);
        self::assertNull($result->convertedValue);
    }
}
