<?php

/**
 * `mod_copilot_doc.qa_status` (T22 / U12 post-mortem QA).
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
 * Advisory only (docs/build-notes.md "U12 additions" / T22): U12's async QA
 * sweep is the only writer of anything other than `Pending`; DocStore never
 * gates serving on this column, it only reads it for best-of-N ordering via
 * `qa_score` (which is set alongside it).
 */
enum QaStatus: string
{
    case Pending = 'pending';
    case Ok = 'ok';
    case Low = 'low';
    case Unavailable = 'unavailable';
}
