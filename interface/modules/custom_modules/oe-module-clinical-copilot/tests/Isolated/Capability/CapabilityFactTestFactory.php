<?php

/**
 * Shared Fact-building helpers for the U5 Capability isolated test suite.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Capability;

use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;

/**
 * Not a TestCase -- a plain factory, mirroring
 * `tests/Isolated/Fact/FactTestFactory.php`'s pattern for this suite's own
 * fixtures so DerivedFacts/FactRekind tests never need a database.
 */
final class CapabilityFactTestFactory
{
    private function __construct()
    {
        // static-only
    }

    /**
     * A `trend_point` Fact, modeled on ControlProxy's re-kinding rule: exact
     * numeric, non-corrected, non-in-flight.
     */
    public static function trendPoint(int $pid, int $resultPk, float $value, string $clinicalDate, string $unit = '%'): Fact
    {
        $citations = [new Citation('procedure_result', $resultPk, 'result', DateSource::Collected)];
        $factValue = new FactValue((string)$value, $value, Comparator::None, $unit, $unit, null);
        $factId = FactId::compute(Capability::ControlProxy, FactKind::TrendPoint, $citations, $factValue);

        return new Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            FactKind::TrendPoint,
            $pid,
            new \DateTimeImmutable($clinicalDate),
            DateSource::Collected,
            $factValue,
            FactStatus::Final,
            [],
            $citations,
        );
    }

    /**
     * A `result`-kind Fact (as U4's LabSliceReader always emits, before any
     * capability re-kinds it) that ControlProxy's re-kinding rule must NOT
     * turn into a trend_point: a correction.
     */
    public static function correctedResult(int $pid, int $resultPk, float $value, string $clinicalDate): Fact
    {
        $citations = [new Citation('procedure_result', $resultPk, 'result', DateSource::Collected)];
        $factValue = new FactValue((string)$value, $value, Comparator::None, '%', '%', null);
        $factId = FactId::compute(Capability::ControlProxy, FactKind::Result, $citations, $factValue);

        return new Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            FactKind::Result,
            $pid,
            new \DateTimeImmutable($clinicalDate),
            DateSource::Collected,
            $factValue,
            FactStatus::Corrected,
            [],
            $citations,
        );
    }
}
