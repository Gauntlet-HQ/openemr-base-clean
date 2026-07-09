<?php

/**
 * Shared Fact-building helpers for the U3 isolated test suite.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact;

use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;

/**
 * Not a TestCase itself -- a plain factory the other Isolated\Fact tests
 * compose to avoid re-deriving the Fact shape in every test.
 */
final class FactTestFactory
{
    private function __construct()
    {
        // static-only
    }

    /**
     * A rising-A1c trend_point, modeled on the CCP-001 landmine fixture.
     */
    public static function a1cTrendPoint(int $pid = 1, int $resultPk = 1, string $raw = '7.2', ?string $clinicalDate = '2025-07-07'): Fact
    {
        $citations = [new Citation('procedure_result', $resultPk, 'result', DateSource::Collected)];
        $value = new FactValue($raw, (float)$raw, Comparator::None, '%', '%', null);
        $factId = FactId::compute(Capability::ControlProxy, FactKind::TrendPoint, $citations, $value);

        return new Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            FactKind::TrendPoint,
            $pid,
            $clinicalDate !== null ? new \DateTimeImmutable($clinicalDate) : null,
            DateSource::Collected,
            $value,
            FactStatus::Final,
            [],
            $citations,
        );
    }

    /**
     * A censored "<7.0" result, modeled on the CCP-003 landmine fixture.
     */
    public static function censoredResult(int $pid = 3, int $resultPk = 12): Fact
    {
        $citations = [new Citation('procedure_result', $resultPk, 'result', DateSource::Collected)];
        $value = new FactValue('<7.0', 7.0, Comparator::Lt, '%', '%', null);
        $factId = FactId::compute(Capability::ControlProxy, FactKind::Result, $citations, $value);

        return new Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            FactKind::Result,
            $pid,
            new \DateTimeImmutable('2025-06-22'),
            DateSource::Collected,
            $value,
            FactStatus::Final,
            [Flag::censored()],
            $citations,
        );
    }

    /**
     * An excluded unitless-value fact, modeled on the CCP-003 landmine fixture.
     */
    public static function unitlessExclusion(int $pid = 3, int $resultPk = 13): Fact
    {
        $citations = [new Citation('procedure_result', $resultPk, 'units', DateSource::Collected)];
        $value = new FactValue('110', null, Comparator::None, '', null, null);
        $factId = FactId::compute(Capability::ControlProxy, FactKind::Exclusion, $citations, $value);

        return new Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            FactKind::Exclusion,
            $pid,
            new \DateTimeImmutable('2025-07-02'),
            DateSource::Collected,
            $value,
            FactStatus::Excluded,
            [Flag::excludedReason(ExclusionReason::Unitless)],
            $citations,
        );
    }

    /**
     * A no-value fact (med_event), covering Facts whose `value` is legitimately null.
     */
    public static function medEvent(int $pid = 1, int $rxPk = 2): Fact
    {
        $citations = [new Citation('prescriptions', $rxPk, 'start_date', DateSource::Collected)];
        $factId = FactId::compute(Capability::MedResponse, FactKind::MedEvent, $citations, null);

        return new Fact(
            $factId,
            Capability::MedResponse,
            '1',
            FactKind::MedEvent,
            $pid,
            new \DateTimeImmutable('2025-03-07'),
            DateSource::Collected,
            null,
            FactStatus::Final,
            [],
            $citations,
        );
    }

    /**
     * A new-row-corrected result citing the row it supersedes, modeled on
     * the CCP-003 corrected_lab_new_row landmine.
     */
    public static function supersedingCorrection(int $pid = 3, int $correctedPk = 11, int $supersededPk = 10): Fact
    {
        $citations = [
            new Citation('procedure_result', $correctedPk, 'result', DateSource::Collected),
            new Citation('procedure_result', $supersededPk, 'result', DateSource::Collected),
        ];
        $value = new FactValue('7.8', 7.8, Comparator::None, '%', '%', null);
        $factId = FactId::compute(Capability::ControlProxy, FactKind::Result, $citations, $value);

        return new Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            FactKind::Result,
            $pid,
            new \DateTimeImmutable('2025-06-07'),
            DateSource::Collected,
            $value,
            FactStatus::Corrected,
            [Flag::supersededCount(1)],
            $citations,
        );
    }
}
