<?php

/**
 * Re-kinds a Fact: same capability/pid/date/value/status/flags/citations, new `kind`.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability\Support;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;

/**
 * U4's {@see \OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader} resolves
 * C1-C4 and hands every presented row back as `kind: result` -- deliberately
 * NOT deciding whether it is a `trend_point` or `preliminary_result`
 * (see {@see \OpenEMR\Modules\ClinicalCopilot\Lab\PresentedLabFact}'s
 * docblock). That re-kinding judgment belongs to the capability. Because
 * `fact_id = hash(capability, kind, citations, value)`
 * ({@see FactId::compute()}), changing `kind` always requires a fresh
 * `fact_id` -- this helper is the single place that does both together so no
 * capability ever forgets to recompute it.
 */
final class FactRekind
{
    private function __construct()
    {
        // static-only
    }

    public static function withKind(Fact $original, FactKind $newKind): Fact
    {
        $factId = FactId::compute($original->capability, $newKind, $original->citations, $original->value);

        return new Fact(
            $factId,
            $original->capability,
            $original->capabilityVersion,
            $newKind,
            $original->pid,
            $original->clinicalDate,
            $original->dateSource,
            $original->value,
            $original->status,
            $original->flags,
            $original->citations,
        );
    }
}
