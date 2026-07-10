<?php

/**
 * `mod_copilot_doc.regen_reason` (T22).
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
 * Why this particular attempt exists. `None` is the first attempt at a given
 * `(pid, fact_digest)`; the others correspond to T22's QA-driven rerun
 * (`QaLow`), the physician's manual Regenerate button (`Manual`, ARCHITECTURE.md
 * §6.1), and U10's fail-closed verifier retry (`VerifyRetry`).
 */
enum RegenReason: string
{
    case None = 'none';
    case QaLow = 'qa_low';
    case Manual = 'manual';
    case VerifyRetry = 'verify_retry';
}
