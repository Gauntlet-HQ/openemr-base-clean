<?php

/**
 * LabIdentityMatcher — the PHI-mixing guard that matches a lab to its chart.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Ingest;

use OpenEMR\Modules\ClinicalCopilot\Ingest\LabIdentityMatcher;
use OpenEMR\Modules\ClinicalCopilot\Ingest\LabIdentityStatus;
use PHPUnit\Framework\TestCase;

/**
 * The one job: when a lab report is stapled to the wrong chart, say so loudly;
 * when it clearly belongs, say so quietly; and when there is nothing to compare,
 * refuse to pretend it matched. Every branch is exercised — the safety of this
 * check is exactly its willingness to return Mismatch/Unknown on ambiguity.
 */
final class LabIdentityMatcherTest extends TestCase
{
    public function testMatchesWhenNameAndDobAgree(): void
    {
        $m = LabIdentityMatcher::compare('Jane', 'Doe', '1970-04-01', 'Jane Doe', '1970-04-01');

        self::assertSame(LabIdentityStatus::Match, $m->status);
        self::assertSame([], $m->reasons);
        self::assertNull($m->detail());
        self::assertFalse($m->isMismatch());
    }

    public function testMatchesRegardlessOfNameOrderPunctuationAndCase(): void
    {
        $m = LabIdentityMatcher::compare('Jane', 'Doe', '1970-04-01', 'DOE, JANE A.', '1970-04-01');

        self::assertSame(LabIdentityStatus::Match, $m->status);
    }

    public function testMatchesOnDobFormatDifferences(): void
    {
        // Chart stores ISO; the document printed a US-style date — same day.
        $m = LabIdentityMatcher::compare('Jane', 'Doe', '1970-04-01', 'Jane Doe', '04/01/1970');

        self::assertSame(LabIdentityStatus::Match, $m->status);
    }

    public function testMismatchOnDifferentName(): void
    {
        $m = LabIdentityMatcher::compare('Jane', 'Doe', '1970-04-01', 'John Smith', '1970-04-01');

        self::assertSame(LabIdentityStatus::Mismatch, $m->status);
        self::assertTrue($m->isMismatch());
        self::assertNotNull($m->detail());
        self::assertStringContainsString('John Smith', (string)$m->detail());
    }

    public function testMismatchOnDifferentDob(): void
    {
        $m = LabIdentityMatcher::compare('Jane', 'Doe', '1970-04-01', 'Jane Doe', '1981-12-12');

        self::assertSame(LabIdentityStatus::Mismatch, $m->status);
        self::assertStringContainsString('1981-12-12', (string)$m->detail());
    }

    public function testAnyConflictWinsEvenIfTheOtherFieldAgrees(): void
    {
        // DOB agrees but the name is a different person — err toward flagging.
        $m = LabIdentityMatcher::compare('Jane', 'Doe', '1970-04-01', 'John Smith', '1970-04-01');

        self::assertSame(LabIdentityStatus::Mismatch, $m->status);
    }

    public function testNearMissFirstNameIsFlagged(): void
    {
        // Same surname, but "Janet" is not "Jane" — a near-miss that is exactly
        // the kind of wrong-chart error this guard exists to catch.
        $m = LabIdentityMatcher::compare('Jane', 'Doe', '1970-04-01', 'Janet Doe', null);

        self::assertSame(LabIdentityStatus::Mismatch, $m->status);
    }

    public function testUnknownWhenDocumentStatesNoIdentity(): void
    {
        $m = LabIdentityMatcher::compare('Jane', 'Doe', '1970-04-01', null, null);

        self::assertSame(LabIdentityStatus::Unknown, $m->status);
        self::assertNotSame([], $m->reasons);
    }

    public function testUnknownWhenBlankStringsAreProvided(): void
    {
        $m = LabIdentityMatcher::compare('Jane', 'Doe', '1970-04-01', '   ', '');

        self::assertSame(LabIdentityStatus::Unknown, $m->status);
    }

    public function testUnknownWhenChartLacksTheCounterpartToConfirm(): void
    {
        // Document gives only a name; the chart has no name to check it against
        // and the document has no DOB — no conflict, but nothing confirmed.
        $m = LabIdentityMatcher::compare(null, null, '1970-04-01', 'Jane Doe', null);

        self::assertSame(LabIdentityStatus::Unknown, $m->status);
    }

    public function testUnparseableDocumentDobIsTreatedAsNotStatedNotAMatch(): void
    {
        // Garbled DOB, matching name → confirmed on name, DOB simply ignored.
        $m = LabIdentityMatcher::compare('Jane', 'Doe', '1970-04-01', 'Jane Doe', 'not-a-date');

        self::assertSame(LabIdentityStatus::Match, $m->status);
    }

    public function testInitialsDoNotSpuriouslyMatch(): void
    {
        // A single-letter token must never satisfy a first/last name.
        $m = LabIdentityMatcher::compare('J', 'Doe', '1970-04-01', 'Jane Doe', null);

        self::assertNotSame(LabIdentityStatus::Match, $m->status);
    }
}
