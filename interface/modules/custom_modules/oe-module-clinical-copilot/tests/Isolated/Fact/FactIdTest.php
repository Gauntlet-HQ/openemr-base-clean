<?php

/**
 * fact_id collision-avoidance and stability (T19).
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
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a preloaded chat-session fact and a fresh re-fetch
 * of the same datum silently colliding on fact_id after a lab correction --
 * the verifier (V2) would then resolve a citation to the WRONG value without
 * any way to detect it.
 */
final class FactIdTest extends TestCase
{
    private function citations(): array
    {
        return [new Citation('procedure_result', 9, 'result', DateSource::Collected)];
    }

    public function testSameInputsProduceTheSameFactId(): void
    {
        $value = new FactValue('7.9', 7.9, Comparator::None, '%', '%', null);

        $first = FactId::compute(Capability::ControlProxy, FactKind::Result, $this->citations(), $value);
        $second = FactId::compute(Capability::ControlProxy, FactKind::Result, $this->citations(), $value);

        self::assertSame($first, $second);
    }

    /**
     * The corrected-lab-in-place landmine (CCP-003): same physical row,
     * value UPDATEd from final/7.9 to corrected/8.1. The re-fetch after
     * correction must NOT collide with the pre-correction fact_id.
     */
    public function testCorrectedValueOnTheSameCitationProducesADifferentFactId(): void
    {
        $original = new FactValue('7.9', 7.9, Comparator::None, '%', '%', null);
        $corrected = new FactValue('8.1', 8.1, Comparator::None, '%', '%', null);

        $originalId = FactId::compute(Capability::ControlProxy, FactKind::Result, $this->citations(), $original);
        $correctedId = FactId::compute(Capability::ControlProxy, FactKind::Result, $this->citations(), $corrected);

        self::assertNotSame($originalId, $correctedId);
    }

    public function testDifferentCapabilityProducesADifferentFactId(): void
    {
        $value = new FactValue('7.9', 7.9, Comparator::None, '%', '%', null);

        $controlProxyId = FactId::compute(Capability::ControlProxy, FactKind::Result, $this->citations(), $value);
        $medResponseId = FactId::compute(Capability::MedResponse, FactKind::Result, $this->citations(), $value);

        self::assertNotSame($controlProxyId, $medResponseId);
    }

    public function testDifferentKindProducesADifferentFactId(): void
    {
        $value = new FactValue('7.9', 7.9, Comparator::None, '%', '%', null);

        $resultId = FactId::compute(Capability::ControlProxy, FactKind::Result, $this->citations(), $value);
        $trendId = FactId::compute(Capability::ControlProxy, FactKind::TrendPoint, $this->citations(), $value);

        self::assertNotSame($resultId, $trendId);
    }

    public function testDifferentCitationsProduceADifferentFactId(): void
    {
        $value = new FactValue('7.9', 7.9, Comparator::None, '%', '%', null);
        $otherCitations = [new Citation('procedure_result', 99, 'result', DateSource::Collected)];

        $first = FactId::compute(Capability::ControlProxy, FactKind::Result, $this->citations(), $value);
        $second = FactId::compute(Capability::ControlProxy, FactKind::Result, $otherCitations, $value);

        self::assertNotSame($first, $second);
    }

    /**
     * Citation collection order must not perturb the id -- two capability
     * code paths that assemble the same citation set in different order
     * (e.g. iterating a supersession chain oldest-first vs newest-first)
     * must still agree on fact_id.
     */
    public function testCitationOrderDoesNotAffectFactId(): void
    {
        $value = new FactValue('7.8', 7.8, Comparator::None, '%', '%', null);
        $forward = [
            new Citation('procedure_result', 11, 'result', DateSource::Collected),
            new Citation('procedure_result', 10, 'result', DateSource::Collected),
        ];
        $reversed = [
            new Citation('procedure_result', 10, 'result', DateSource::Collected),
            new Citation('procedure_result', 11, 'result', DateSource::Collected),
        ];

        $forwardId = FactId::compute(Capability::ControlProxy, FactKind::Result, $forward, $value);
        $reversedId = FactId::compute(Capability::ControlProxy, FactKind::Result, $reversed, $value);

        self::assertSame($forwardId, $reversedId);
    }

    /**
     * fact_id's signature deliberately has no capability_version parameter at
     * all: a config/threshold version bump describes the SAME underlying
     * datum under a new rule set, not a new datum. (The digest, not
     * fact_id, is what a version bump must invalidate -- see
     * DigestTest::testCapabilityVersionBumpChangesDigest*.) This test
     * documents that two Facts built from the identical (capability, kind,
     * citations, value) but different capability_version strings are
     * assigned the very same fact_id, by construction.
     */
    public function testFactIdIsIndependentOfCapabilityVersion(): void
    {
        $value = new FactValue('7.9', 7.9, Comparator::None, '%', '%', null);
        $citations = $this->citations();
        $factId = FactId::compute(Capability::ControlProxy, FactKind::Result, $citations, $value);

        $withV1 = new \OpenEMR\Modules\ClinicalCopilot\Fact\Fact(
            $factId,
            Capability::ControlProxy,
            '1',
            FactKind::Result,
            3,
            null,
            DateSource::Collected,
            $value,
            \OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus::Final,
            [],
            $citations,
        );
        $withV2 = new \OpenEMR\Modules\ClinicalCopilot\Fact\Fact(
            $factId,
            Capability::ControlProxy,
            '2',
            FactKind::Result,
            3,
            null,
            DateSource::Collected,
            $value,
            \OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus::Final,
            [],
            $citations,
        );

        self::assertSame($withV1->factId, $withV2->factId);
        self::assertNotSame($withV1->capabilityVersion, $withV2->capabilityVersion);
    }
}
