<?php

/**
 * A row read back from mod_copilot_chat_session, typed.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

/**
 * `pid` is the session's structurally-pinned patient (I10) -- read-only on
 * this DTO by construction; nothing in this module ever repoints a session
 * at a different pid. `factDigest` is the digest AT PRELOAD TIME (T19): the
 * per-turn freshness check compares a freshly recomputed digest against
 * THIS value, never against a live re-read of `mod_copilot_doc`.
 */
final readonly class ChatSession
{
    public function __construct(
        public int $id,
        public int $pid,
        public int $userId,
        public ?int $docId,
        public string $factDigest,
        public ChatSessionStatus $status,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
