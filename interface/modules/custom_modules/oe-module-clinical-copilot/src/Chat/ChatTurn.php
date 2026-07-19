<?php

/**
 * A row read back from mod_copilot_chat_turn, typed.
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
 * `content`/`toolCalls`/`verificationVerdict` are already-decoded arrays --
 * this class does not interpret their shape further (that is
 * {@see ChatFactSetBuilder}'s and the rendering layer's job); it only
 * carries what {@see ChatTurnStore} read back, typed.
 */
final readonly class ChatTurn
{
    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed>|null $toolCalls
     * @param array<string, mixed>|null $verificationVerdict
     */
    public function __construct(
        public int $id,
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
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
