<?php

/**
 * Shared fixture-building helpers for the U10 Verify isolated test suite.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Verify;

use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Verify\SessionFactSet;

/**
 * Not a TestCase -- mirrors `tests/Isolated/Fact/FactTestFactory.php`'s
 * pattern for this suite's own fixtures. Every fixture here is deliberately
 * simple: one or two Facts per test, hand-built so a reader can verify the
 * expected verdict by inspection rather than trusting a fixture generator.
 */
final class VerifyTestFactory
{
    public const PINNED_PID = 7;
    public const WRONG_PID = 99;

    private function __construct()
    {
        // static-only
    }

    /**
     * Two ascending A1c trend points for the pinned patient: 7.2 on
     * 2025-01-01, 7.6 on 2025-04-01.
     */
    public static function a1cEarly(): Fact
    {
        return self::trendPoint(self::PINNED_PID, 101, '7.2', '2025-01-01');
    }

    public static function a1cLater(): Fact
    {
        return self::trendPoint(self::PINNED_PID, 102, '7.6', '2025-04-01');
    }

    /**
     * A glucose result flagged `conflict` (C3: parsed-value vs. lab-flag
     * proofs disagreed) -- the fact V6 exercises.
     */
    public static function conflictedGlucose(): Fact
    {
        $citations = [new Citation('procedure_result', 103, 'result', DateSource::Collected)];
        $value = new FactValue('190', 190.0, Comparator::None, 'mg/dL', 'mg/dL', null);
        $factId = FactId::compute(Capability::ControlProxy, FactKind::Result, $citations, $value);

        return new Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            FactKind::Result,
            self::PINNED_PID,
            new \DateTimeImmutable('2025-04-01'),
            DateSource::Collected,
            $value,
            FactStatus::Final,
            [Flag::conflict()],
            $citations,
        );
    }

    /**
     * A fact belonging to a DIFFERENT patient than the session's pinned pid
     * -- simulates the defect V3 exists to independently catch even though
     * the tool executor is supposed to prevent it on ingest (I10).
     */
    public static function wrongPatientVital(): Fact
    {
        $citations = [new Citation('form_vitals', 55, 'weight', DateSource::Collected)];
        $value = new FactValue('180', 180.0, Comparator::None, 'lb', 'lb', null);
        $factId = FactId::compute(Capability::VitalsTrend, FactKind::Vital, $citations, $value);

        return new Fact(
            $factId,
            Capability::VitalsTrend,
            '1',
            FactKind::Vital,
            self::WRONG_PID,
            new \DateTimeImmutable('2025-04-01'),
            DateSource::Collected,
            $value,
            FactStatus::Final,
            [],
            $citations,
        );
    }

    /**
     * @param list<Fact> $facts
     */
    public static function sessionFactSet(array $facts, int $pinnedPid = self::PINNED_PID): SessionFactSet
    {
        return new SessionFactSet($pinnedPid, $facts);
    }

    /**
     * @param list<array<string, mixed>> $claims
     */
    public static function claimsJson(array $claims): string
    {
        return (string)json_encode($claims, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $citationIds
     * @param list<float> $numericValues
     * @param list<string> $flags
     * @return array<string, mixed>
     */
    public static function claim(
        string $text,
        string $claimType,
        array $citationIds = [],
        array $numericValues = [],
        array $flags = [],
        int $order = 0,
    ): array {
        return [
            'text' => $text,
            'claim_type' => $claimType,
            'citation_ids' => $citationIds,
            'numeric_values' => $numericValues,
            'flags' => $flags,
            'order' => $order,
            'emphasis' => null,
        ];
    }

    private static function trendPoint(int $pid, int $resultPk, string $raw, string $clinicalDate): Fact
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
            new \DateTimeImmutable($clinicalDate),
            DateSource::Collected,
            $value,
            FactStatus::Final,
            [],
            $citations,
        );
    }
}
