<?php

/**
 * The fields ChatTurnStore::insert() accepts for one new mod_copilot_chat_turn row.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

final readonly class NewChatTurn
{
    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed>|null $toolCalls
     * @param array<string, mixed>|null $verificationVerdict
     */
    public function __construct(
        public int $sessionId,
        public int $seq,
        public ChatTurnRole $role,
        public array $content,
        public ?array $toolCalls,
        public ?array $verificationVerdict,
        public string $correlationId,
        public ?int $tokensIn,
        public ?int $tokensOut,
        public ?float $costUsd,
    ) {
    }
}
