<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\AI;

use OpenEMR\Services\AI\PatientHistorySummaryResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
final class PatientHistorySummaryResultTest extends TestCase
{
    public function testNewPatientResult(): void
    {
        $result = PatientHistorySummaryResult::newPatient();

        $this->assertTrue($result->isNewPatient);
        $this->assertSame('new patient', $result->summary);
        $this->assertSame(
            ['isNewPatient' => true, 'summary' => 'new patient'],
            $result->toArray()
        );
    }

    public function testFromSummaryResult(): void
    {
        $result = PatientHistorySummaryResult::fromSummary('A concise summary.');

        $this->assertFalse($result->isNewPatient);
        $this->assertSame('A concise summary.', $result->summary);
        $this->assertSame(
            ['isNewPatient' => false, 'summary' => 'A concise summary.'],
            $result->toArray()
        );
    }
}
