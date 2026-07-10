<?php

/**
 * Lab contract C3: the two admissible out-of-range proofs, and conflict handling.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\Threshold;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\ThresholdDirection;
use OpenEMR\Modules\ClinicalCopilot\Lab\OutOfRangeEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: I8 (the system adjudicates nothing) being silently
 * violated by picking one proof over the other when they disagree, instead
 * of surfacing the disagreement as a `conflict` flag.
 */
final class OutOfRangeEvaluatorTest extends TestCase
{
    private function highThreshold(float $value): Threshold
    {
        return new Threshold($value, ThresholdDirection::High, 'v1');
    }

    public function testProofByValueAloneWhenNoLabFlagEvidence(): void
    {
        $result = OutOfRangeEvaluator::evaluate(9.5, Comparator::None, $this->highThreshold(9.0), '', '');

        self::assertTrue($result->isOutOfRangeByValue());
        self::assertNull($result->byLabFlag);
        self::assertFalse($result->conflict);
    }

    public function testNotOutOfRangeByValueWhenBelowThreshold(): void
    {
        $result = OutOfRangeEvaluator::evaluate(7.0, Comparator::None, $this->highThreshold(9.0), '', '');

        self::assertFalse($result->isOutOfRangeByValue());
        self::assertSame(false, $result->byValue);
    }

    public function testProofByLabFlagRequiresBothAbnormalAndRange(): void
    {
        $result = OutOfRangeEvaluator::evaluate(null, Comparator::None, null, 'high', '4.0-8.0');

        self::assertTrue($result->isOutOfRangeByLabFlag());
        self::assertNull($result->byValue);
        self::assertFalse($result->conflict);
    }

    public function testAbnormalFlagWithoutRangeIsNotAProof(): void
    {
        $result = OutOfRangeEvaluator::evaluate(null, Comparator::None, null, 'high', '');

        self::assertNull($result->byLabFlag);
    }

    public function testAbnormalNoIsANegativeProofRegardlessOfRange(): void
    {
        $result = OutOfRangeEvaluator::evaluate(null, Comparator::None, null, 'no', '');

        self::assertSame(false, $result->byLabFlag);
        self::assertFalse($result->isOutOfRangeByLabFlag());
    }

    public function testUnrecognizedAbnormalValueIsNotAProof(): void
    {
        $result = OutOfRangeEvaluator::evaluate(null, Comparator::None, null, 'weird', '4.0-8.0');

        self::assertNull($result->byLabFlag);
    }

    /**
     * Both proofs available and agreeing (both say out-of-range): no
     * conflict, both flags are true.
     */
    public function testAgreeingProofsAreNotAConflict(): void
    {
        $result = OutOfRangeEvaluator::evaluate(9.5, Comparator::None, $this->highThreshold(9.0), 'high', '4.0-9.0');

        self::assertTrue($result->isOutOfRangeByValue());
        self::assertTrue($result->isOutOfRangeByLabFlag());
        self::assertFalse($result->conflict);
    }

    /**
     * I8: the two proofs disagree -- the value says in-range, the lab's own
     * abnormal flag says out-of-range. Both are surfaced; nothing here picks
     * a winner.
     */
    public function testDisagreeingProofsProduceAConflict(): void
    {
        $result = OutOfRangeEvaluator::evaluate(7.0, Comparator::None, $this->highThreshold(9.0), 'high', '4.0-8.0');

        self::assertFalse($result->isOutOfRangeByValue());
        self::assertTrue($result->isOutOfRangeByLabFlag());
        self::assertTrue($result->conflict);
    }

    /**
     * A censored value never produces a byValue proof: its direction alone
     * does not decide whether it crosses an arbitrary threshold.
     */
    public function testCensoredValueNeverProducesAByValueProof(): void
    {
        $result = OutOfRangeEvaluator::evaluate(7.0, Comparator::Lt, $this->highThreshold(9.0), '', '');

        self::assertNull($result->byValue);
    }

    public function testNoThresholdConfiguredMeansNoByValueProofEvenWithAParsedValue(): void
    {
        $result = OutOfRangeEvaluator::evaluate(20.0, Comparator::None, null, '', '');

        self::assertNull($result->byValue);
    }

    public function testNoProofsAvailableIsNotAConflict(): void
    {
        $result = OutOfRangeEvaluator::evaluate(null, Comparator::None, null, '', '');

        self::assertNull($result->byValue);
        self::assertNull($result->byLabFlag);
        self::assertFalse($result->conflict);
    }
}
