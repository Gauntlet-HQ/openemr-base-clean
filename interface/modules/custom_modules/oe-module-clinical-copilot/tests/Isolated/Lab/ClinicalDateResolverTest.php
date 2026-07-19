<?php

/**
 * Lab contract C1: clinical-date precedence.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Lab\ClinicalDateResolver;
use OpenEMR\Modules\ClinicalCopilot\Lab\RawLabRow;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: the two time axes (I4) mixing -- a fallback date
 * silently being treated as an authoritative collection date would corrupt
 * every trend ordering and OverdueTests calculation downstream.
 */
final class ClinicalDateResolverTest extends TestCase
{
    private function rowWithDates(
        ?string $reportCollected,
        ?string $orderCollected,
        ?string $resultDate,
        ?string $reportReport,
    ): RawLabRow {
        return new RawLabRow(
            1,
            1,
            '4548-4',
            'N',
            '7.2',
            '%',
            'final',
            '',
            '',
            $reportCollected !== null ? new \DateTimeImmutable($reportCollected) : null,
            $orderCollected !== null ? new \DateTimeImmutable($orderCollected) : null,
            $resultDate !== null ? new \DateTimeImmutable($resultDate) : null,
            $reportReport !== null ? new \DateTimeImmutable($reportReport) : null,
        );
    }

    public function testReportDateCollectedWinsWhenAllFourArePresent(): void
    {
        $row = $this->rowWithDates('2025-01-01', '2025-01-02', '2025-01-03', '2025-01-04');

        $resolved = ClinicalDateResolver::resolve($row);

        self::assertSame('2025-01-01', $resolved->date?->format('Y-m-d'));
        self::assertSame(DateSource::Collected, $resolved->source);
    }

    public function testOrderDateCollectedWinsWhenReportDateCollectedIsMissing(): void
    {
        $row = $this->rowWithDates(null, '2025-01-02', '2025-01-03', '2025-01-04');

        $resolved = ClinicalDateResolver::resolve($row);

        self::assertSame('2025-01-02', $resolved->date?->format('Y-m-d'));
        self::assertSame(DateSource::Collected, $resolved->source);
    }

    public function testResultDateIsAFallbackWhenNoCollectionDateExists(): void
    {
        $row = $this->rowWithDates(null, null, '2025-01-03', '2025-01-04');

        $resolved = ClinicalDateResolver::resolve($row);

        self::assertSame('2025-01-03', $resolved->date?->format('Y-m-d'));
        self::assertSame(DateSource::Fallback, $resolved->source);
    }

    public function testReportDateReportIsTheLastResortFallback(): void
    {
        $row = $this->rowWithDates(null, null, null, '2025-01-04');

        $resolved = ClinicalDateResolver::resolve($row);

        self::assertSame('2025-01-04', $resolved->date?->format('Y-m-d'));
        self::assertSame(DateSource::Fallback, $resolved->source);
    }

    public function testNoDateAtAllResolvesToNullWithFallbackSource(): void
    {
        $row = $this->rowWithDates(null, null, null, null);

        $resolved = ClinicalDateResolver::resolve($row);

        self::assertNull($resolved->date);
        self::assertSame(DateSource::Fallback, $resolved->source);
    }
}
