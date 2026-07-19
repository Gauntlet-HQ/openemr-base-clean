<?php

/**
 * mod_copilot_cadence-backed RateLimiterInterface implementation (ARCHITECTURE.md §3.7).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Chat\RateLimit\RateLimitDecision;
use OpenEMR\Modules\ClinicalCopilot\Chat\RateLimit\RateLimiterInterface;

/**
 * {@see RateLimiterInterface}'s own docblock splits the chat rate limits: the
 * session-scoped ones ("one active turn", "30 turns/session") are enforced
 * directly by {@see \OpenEMR\Modules\ClinicalCopilot\Controller\ChatController}
 * itself; this class covers the two U12-owned, config-driven, PER-USER limits
 * -- "max 3 active sessions; max 60 turns/hour" -- both counted straight from
 * the ledger tables (`mod_copilot_chat_session`/`mod_copilot_chat_turn`), no
 * separate counters to keep in sync.
 */
final class CadenceRateLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly CadenceConfigStore $configStore = new CadenceConfigStore(),
    ) {
    }

    public function checkTurn(int $pid, int $userId, int $sessionId): RateLimitDecision
    {
        $limits = $this->configStore->limits();

        $activeSessions = (int)QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM `mod_copilot_chat_session` WHERE `user_id` = ? AND `status` = ?',
            'c',
            [$userId, 'active'],
        );
        if ($activeSessions > $limits['max_active_sessions_per_user']) {
            return RateLimitDecision::deny(sprintf(
                'max active sessions reached (%d) -- close another session before starting more',
                $limits['max_active_sessions_per_user'],
            ));
        }

        $turnsThisHour = (int)QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c
             FROM `mod_copilot_chat_turn` t
             JOIN `mod_copilot_chat_session` s ON s.`id` = t.`session_id`
             WHERE s.`user_id` = ? AND t.`role` = ? AND t.`created_at` > ?',
            'c',
            [$userId, 'user', self::oneHourAgo()],
        );
        if ($turnsThisHour >= $limits['max_turns_per_user_per_hour']) {
            return RateLimitDecision::deny(sprintf(
                'hourly turn limit reached (%d/hr) -- try again later',
                $limits['max_turns_per_user_per_hour'],
            ));
        }

        return RateLimitDecision::allow();
    }

    private static function oneHourAgo(): string
    {
        return (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
    }
}
