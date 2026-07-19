<?php

/**
 * SynthesisDocPayload::fromDocArray tolerates malformed persisted entries instead of crashing the read.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisDocPayload;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ClaimType;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * A chat turn, the doc render, and the QA sweep all replay a persisted doc via
 * {@see SynthesisDocPayload::fromDocArray()} on every read. A single malformed
 * fact/claim entry (schema drift on an old row, a hand-edited row) must degrade
 * -- skip the bad entry, keep the rest -- never throw and 500 the whole surface
 * before any work is done.
 */
final class SynthesisDocPayloadTest extends TestCase
{
    public function testMalformedFactEntryIsSkippedNotFatal(): void
    {
        $goodFact = FactTestFactory::a1cTrendPoint(1, 7, '7.2', '2025-01-01');

        $doc = [
            'facts' => [
                $goodFact->toArray(),
                ['fact_id' => '', 'capability' => 'not_a_capability'], // malformed: bad capability, missing fields
                ['this is' => 'not a fact at all'],
            ],
            'claims' => null,
            'verify_status' => VerifyStatus::Degraded->value,
            'verdicts' => [],
            'attempts' => 1,
        ];

        $payload = SynthesisDocPayload::fromDocArray($doc);

        self::assertCount(1, $payload->facts, 'only the well-formed fact survives');
        self::assertSame($goodFact->factId, $payload->facts[0]->factId);
    }

    public function testMalformedClaimEntryIsSkippedNotFatal(): void
    {
        $goodClaim = new Claim('A grounded statement.', ClaimType::LabValue, ['cit-1'], [7.2], [], 0, null);

        $doc = [
            'facts' => [],
            'claims' => [
                $goodClaim->toArray(),
                ['text' => '', 'claim_type' => 'nonsense'], // malformed: empty text, bad type
            ],
            'verify_status' => VerifyStatus::Passed->value,
            'verdicts' => [],
            'attempts' => 1,
        ];

        $payload = SynthesisDocPayload::fromDocArray($doc);

        self::assertIsArray($payload->claims);
        self::assertCount(1, $payload->claims, 'only the well-formed claim survives');
        self::assertSame('A grounded statement.', $payload->claims[0]->text);
    }

    public function testWellFormedDocRoundTripsUnchanged(): void
    {
        $fact = FactTestFactory::a1cTrendPoint(1, 7, '7.2', '2025-01-01');
        $doc = SynthesisDocPayload::build(
            [$fact],
            [new Claim('Cited claim.', ClaimType::LabValue, [$fact->factId], [7.2], [], 0, null)],
            VerifyStatus::Passed,
            null,
            null,
            [],
            1,
        );

        $payload = SynthesisDocPayload::fromDocArray($doc);

        self::assertCount(1, $payload->facts);
        self::assertIsArray($payload->claims);
        self::assertCount(1, $payload->claims);
        self::assertSame(VerifyStatus::Passed, $payload->verifyStatus);
    }
}
