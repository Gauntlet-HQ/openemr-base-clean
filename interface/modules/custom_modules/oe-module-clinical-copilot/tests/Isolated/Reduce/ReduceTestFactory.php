<?php

/**
 * Shared fixture-building helpers for the U7 Reduce isolated test suite.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce;

use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptContext;

/**
 * Not a TestCase -- mirrors `tests/Isolated/Fact/FactTestFactory.php`'s
 * pattern for this suite's own fixtures.
 */
final class ReduceTestFactory
{
    private function __construct()
    {
        // static-only
    }

    /**
     * @return list<Fact>
     */
    public static function twoFactSet(int $pid = 7): array
    {
        return [self::a1cTrendPoint($pid, 1, '7.2'), self::a1cTrendPoint($pid, 2, '7.6')];
    }

    public static function a1cTrendPoint(int $pid, int $resultPk, string $raw): Fact
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
            new \DateTimeImmutable('2025-07-07'),
            DateSource::Collected,
            $value,
            FactStatus::Final,
            [],
            $citations,
        );
    }

    public static function patientIdentifiers(): PatientIdentifiers
    {
        return new PatientIdentifiers('Jane Q. Sampleton', 'MRN-778812', '1968-04-11', '19 Birchwood Ln, Springfield');
    }

    public static function context(?string $promptVersion = null): PromptContext
    {
        return new PromptContext('endo-previsit-v1', $promptVersion ?? 'reduce-v1');
    }
}
