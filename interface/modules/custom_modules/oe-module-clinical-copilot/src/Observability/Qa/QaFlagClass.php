<?php

/**
 * The closed set of Flash reviewer flag classes (docs/build-notes.md "U12 additions").
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Qa;

/**
 * Exactly the territory ARCHITECTURE.md §2.4/§2.5 says the deterministic
 * verifier cannot catch: misleading emphasis, subtle paraphrase, omission,
 * and mis-prioritized salience -- the second-pass reviewer's whole reason for
 * existing. `Other` is an escape hatch for a genuine concern that does not
 * fit the other four, never a default the model is invited to reach for.
 */
enum QaFlagClass: string
{
    case Emphasis = 'emphasis';
    case Paraphrase = 'paraphrase';
    case Omission = 'omission';
    case Salience = 'salience';
    case Other = 'other';
}
