<?php

/**
 * ChartLinkResolver: verified deep link for procedure_order, tooltip fallback otherwise.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\ChartLinkResolver;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\ScheduledPatientRow;
use PHPUnit\Framework\TestCase;

final class ChartLinkResolverTest extends TestCase
{
    public function testProcedureOrderResolvesToTheVerifiedSingleOrderResultsRoute(): void
    {
        $citation = new Citation('procedure_order', 77, null, DateSource::Collected);

        self::assertSame(
            'https://example.test/interface/orders/single_order_results.php?orderid=77',
            ChartLinkResolver::url($citation, 'https://example.test'),
        );
        self::assertSame('Lab order #77', ChartLinkResolver::label($citation));
    }

    public function testUnmappedTableFallsBackToTooltipOnlyNoUrl(): void
    {
        $citation = new Citation('procedure_result', 501, 'result', DateSource::Collected);

        self::assertNull(ChartLinkResolver::url($citation, 'https://example.test'));
        self::assertSame('Lab result #501.result', ChartLinkResolver::label($citation));
    }

    public function testLabelOmitsTheFieldSuffixWhenFieldIsNull(): void
    {
        $citation = new Citation('form_vitals', 9, null, DateSource::Collected);

        self::assertSame('Vitals #9', ChartLinkResolver::label($citation));
    }

    public function testVisitLabelFormatsTitleAndTime(): void
    {
        $visit = new ScheduledPatientRow(3, 'CCP-003', 'Cara Corrected', '09:30', 'Endo follow-up');

        self::assertSame('Endo follow-up · 09:30', ChartLinkResolver::visitLabel($visit));
        self::assertNull(ChartLinkResolver::visitLabel(null));
    }
}
