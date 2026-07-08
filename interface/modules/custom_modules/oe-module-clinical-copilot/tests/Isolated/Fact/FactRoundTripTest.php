<?php

/**
 * toArray()/fromArray() round-trip fidelity (parse, don't validate).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a Fact that serializes to JSON for the doc store
 * (mod_copilot_doc.doc) or a chat-session preload and comes back subtly
 * different (a lost flag, a comparator silently defaulting, a float turning
 * into a string) would let unverifiable claims slip past the verifier, which
 * trusts the Fact objects it re-hydrates.
 */
final class FactRoundTripTest extends TestCase
{
    /**
     * @return iterable<string, array{0: Fact}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function facts(): iterable
    {
        yield 'trend_point with numeric value' => [FactTestFactory::a1cTrendPoint()];
        yield 'censored result' => [FactTestFactory::censoredResult()];
        yield 'unitless exclusion (parsed null, flagged)' => [FactTestFactory::unitlessExclusion()];
        yield 'med_event with null value' => [FactTestFactory::medEvent()];
        yield 'superseding correction with two citations' => [FactTestFactory::supersedingCorrection()];
    }

    #[DataProvider('facts')]
    public function testRoundTripPreservesEveryField(Fact $original): void
    {
        $rehydrated = Fact::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rehydrated->toArray());
        self::assertSame($original->factId, $rehydrated->factId);
        self::assertSame($original->capability, $rehydrated->capability);
        self::assertSame($original->kind, $rehydrated->kind);
        self::assertSame($original->pid, $rehydrated->pid);
        self::assertEquals($original->clinicalDate, $rehydrated->clinicalDate);
        self::assertSame($original->dateSource, $rehydrated->dateSource);
        self::assertEquals($original->value, $rehydrated->value);
        self::assertSame($original->status, $rehydrated->status);
        self::assertSame(
            array_map(static fn ($f) => $f->value, $original->flags),
            array_map(static fn ($f) => $f->value, $rehydrated->flags),
        );
        self::assertSame(
            array_map(static fn ($c) => $c->toArray(), $original->citations),
            array_map(static fn ($c) => $c->toArray(), $rehydrated->citations),
        );
    }

    public function testFromArrayRejectsMissingCitations(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = FactTestFactory::a1cTrendPoint()->toArray();
        $data['citations'] = [];

        Fact::fromArray($data);
    }

    public function testFromArrayRejectsUnrecognizedCapability(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = FactTestFactory::a1cTrendPoint()->toArray();
        $data['capability'] = 'not_a_real_capability';

        Fact::fromArray($data);
    }

    public function testFromArrayRejectsUnrecognizedKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = FactTestFactory::a1cTrendPoint()->toArray();
        $data['kind'] = 'not_a_real_kind';

        Fact::fromArray($data);
    }

    public function testFromArrayRejectsMalformedFlag(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = FactTestFactory::censoredResult()->toArray();
        $data['flags'] = ['not_a_real_flag'];

        Fact::fromArray($data);
    }

    public function testFromArrayRejectsNonPositivePid(): void
    {
        $this->expectException(\DomainException::class);

        $data = FactTestFactory::a1cTrendPoint()->toArray();
        $data['pid'] = 0;

        Fact::fromArray($data);
    }
}
