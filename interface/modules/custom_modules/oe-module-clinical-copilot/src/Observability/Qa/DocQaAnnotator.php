<?php

/**
 * The one narrow, documented mutation U12 performs against mod_copilot_doc.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Qa;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Doc\QaStatus;

/**
 * {@see \OpenEMR\Modules\ClinicalCopilot\DocStore}'s own docblock states this
 * is deliberately out of ITS scope: "this class has exactly two public
 * methods, insert() and findBest() ... there is no update or delete method
 * anywhere in this class." {@see \OpenEMR\Modules\ClinicalCopilot\Doc\QaStatus}'s
 * docblock names the answer: "U12's async QA sweep is the only writer of
 * anything other than Pending."
 *
 * This class is that writer, and it is scoped as narrowly as the ledger
 * philosophy allows: it NEVER touches `doc` (the facts+citations+narrative
 * JSON, the actual served content -- fully immutable, E7 intact), only the
 * two advisory annotation columns `qa_status`/`qa_score` added for exactly
 * this purpose (table.sql: "T22/U12 ... advisory only, never a serving
 * gate"). The WHERE clause additionally restricts the transition to
 * `pending -> {ok|low|unavailable}` -- a ONE-WAY transition, never reversed,
 * idempotent by construction (a second sweep attempt against an
 * already-annotated row is a no-op, matching
 * {@see \OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionStore::freeze()}'s
 * "the one legal mutation" precedent for `mod_copilot_chat_session.status`).
 * `DocStore::findBest()` already documents reading `qa_score` for best-of-N
 * ordering -- this is the only code path that ever sets it. This narrow
 * exception to the append-only invariant is recorded in the spec at
 * docs/build-notes.md (I3 "Documented carve-out (T22)").
 */
final class DocQaAnnotator
{
    /**
     * @return bool true if this call performed the annotation (row was still
     *         `pending`); false if another sweep already annotated it first
     *         (idempotent no-op, safe to ignore)
     */
    public function annotate(int $docId, QaStatus $status, ?float $score): bool
    {
        QueryUtils::sqlStatementThrowException(
            "UPDATE `mod_copilot_doc` SET `qa_status` = ?, `qa_score` = ? WHERE `id` = ? AND `qa_status` = 'pending'",
            [$status->value, $score, $docId],
        );

        $affected = QueryUtils::affectedRows();

        return $affected !== false && $affected > 0;
    }
}
