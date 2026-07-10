<?php

/**
 * Creates a chat session preloaded with the exact content-addressed doc the physician is reading.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath;

/**
 * ARCHITECTURE.md §1.1: "the session is preloaded with the exact
 * content-addressed doc the physician is reading ... turn 1 therefore needs
 * zero retrieval." build-notes.md's guidance for this build unit is "seed
 * chat from the stored doc row; do NOT re-run the read-path orchestrator
 * mid-turn" -- the "mid-turn" qualifier is the operative word: this class
 * calls {@see SynthesisReadPath::read()} exactly ONCE, at session-creation
 * time, which is the identical, idempotent, free-by-construction (T21) read
 * `public/doc.php` already performs when the physician opens the synthesis
 * page moments before opening the chat panel beside it -- it is emphatically
 * NOT invoked again per chat turn (see {@see AgentLoop}, which only ever
 * calls capability `extract*()` methods directly, never this seeder or
 * `SynthesisReadPath`).
 *
 * A capability crash (`SynthesisReadResult::$capabilityCrash`) means no doc
 * row exists to seed from -- session creation is refused in that case
 * (the physician sees the same "synthesis paused" banner `doc.php` already
 * shows; chat has nothing safe to preload from a partial fact set either).
 */
final class ChatSessionSeeder
{
    public function __construct(
        private readonly SynthesisReadPath $readPath,
        private readonly ChatSessionStore $sessionStore,
    ) {
    }

    /**
     * @return ChatSession|null null when the synthesis could not be computed
     *         (capability crash) -- there is nothing safe to seed a session from
     */
    public function seed(int $pid, int $userId, bool $forceNew = false): ?ChatSession
    {
        $result = $this->readPath->read($pid, $userId);

        if ($result->capabilityCrash || $result->docId === null || $result->factDigest === null) {
            return null;
        }

        if (!$forceNew) {
            $existing = $this->sessionStore->findLatestActiveForUserAndPid($userId, $pid);
            if ($existing !== null && $existing->factDigest === $result->factDigest) {
                return $existing;
            }
        }

        $sessionId = $this->sessionStore->insert(new NewChatSession($pid, $userId, $result->docId, $result->factDigest));

        return $this->sessionStore->find($sessionId) ?? throw new \LogicException(
            'ChatSessionStore::find() returned nothing immediately after ChatSessionStore::insert()'
        );
    }
}
