<?php

/**
 * The extraction lifecycle state: draft (under human review) or locked.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

/**
 * insert -> verify -> lock. A `draft` extraction is editable in the review UI
 * by anyone with copilot access; on lock its verified values are committed to
 * the chart and it becomes immutable except to elevated ACL (which unlocks,
 * edits, and re-commits as an appended correction — never a silent overwrite).
 */
enum ExtractionStatus: string
{
    case Draft = 'draft';
    case Locked = 'locked';
}
