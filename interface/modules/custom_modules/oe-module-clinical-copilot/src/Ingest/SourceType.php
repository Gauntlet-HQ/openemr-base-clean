<?php

/**
 * The provenance kind of a Week 2 citation source.
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
 * The `source_type` half of the Week 2 citation contract
 * ({source_type, source_id, page_or_section, field_or_chunk_id,
 * quote_or_value}). `document` is an uploaded lab/intake page (a
 * `mod_copilot_extraction` + `documents.id`); `guideline` is a retrieved RAG
 * chunk (Phase B). Kept distinct from the Week 1 core-table `Citation` so the
 * load-bearing Week 1 fact/verify invariants are never destabilized.
 */
enum SourceType: string
{
    case Document = 'document';
    case Guideline = 'guideline';
}
