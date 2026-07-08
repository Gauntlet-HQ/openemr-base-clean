<?php

/**
 * The fields ChatSessionStore::insert() accepts for one new mod_copilot_chat_session row.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

final readonly class NewChatSession
{
    public function __construct(
        public int $pid,
        public int $userId,
        public ?int $docId,
        public string $factDigest,
    ) {
    }
}
