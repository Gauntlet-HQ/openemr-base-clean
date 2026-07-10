<?php

/**
 * Append-only ledger backing the two over-reliance dashboard indicators (ARCHITECTURE.md §2.5).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\UiEvent;

use OpenEMR\Common\Database\QueryUtils;

/**
 * "Over-reliance is measured, not assumed away: citation click-through rate
 * and facts-panel opens are dashboard metrics" (ARCHITECTURE.md §2.5).
 * Written by `public/event.php`, a minimal, audit-safe client ping endpoint
 * (no PHI in the row -- just a correlation id, event type, and the pid/user
 * already visible to the authenticated session that sent it).
 */
final class UiEventStore
{
    public function record(UiEventType $type, string $correlationId, int $pid, int $userId): void
    {
        QueryUtils::sqlInsert(
            'INSERT INTO `mod_copilot_ui_event` (`correlation_id`, `pid`, `user_id`, `event_type`) VALUES (?, ?, ?, ?)',
            [$correlationId, $pid, $userId, $type->value],
        );
    }

    public function count(UiEventType $type, \DateTimeImmutable $since): int
    {
        return (int)QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM `mod_copilot_ui_event` WHERE `event_type` = ? AND `created_at` > ?',
            'c',
            [$type->value, $since->format('Y-m-d H:i:s')],
        );
    }
}
