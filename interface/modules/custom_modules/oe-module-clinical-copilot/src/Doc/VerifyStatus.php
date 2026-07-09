<?php

/**
 * `mod_copilot_doc.verify_status` (T22 / I11).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Doc;

/**
 * The deterministic V1-V6 verdict (U10) for THIS attempt -- the only
 * verdict {@see \OpenEMR\Modules\ClinicalCopilot\DocStore::findBest()} treats
 * as a serving gate (I11). `Degraded` means the narrative failed
 * verification and this row's `doc` JSON carries facts-only content (I6);
 * `Passed` means the narrative in `doc` is fully verified prose.
 */
enum VerifyStatus: string
{
    case Passed = 'passed';
    case Degraded = 'degraded';
}
