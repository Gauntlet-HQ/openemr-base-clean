<?php

/**
 * Rebuilds a session's full fact set: preloaded facts UNION every tool result ever fetched.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

/**
 * ARCHITECTURE.md §1.1/§2.2 (V2): a chat turn's citations must resolve
 * against "preloaded facts UNION this session's tool results" -- not just
 * THIS turn's tool results, since an anaphoric follow-up ("and the one
 * before that?") can cite a fact a tool call fetched two turns ago. This
 * class rebuilds exactly that set from the append-only ledger: the doc's
 * preloaded facts (passed in by the caller, read once at session-seed time)
 * plus every fact recorded on every {@see ChatTurnRole::Tool} row this
 * session has ever produced, replayed in order.
 *
 * Deliberately a pure function over already-persisted rows -- it never
 * re-runs a capability itself (I2 is satisfied at the point each tool call
 * originally executed; replaying its recorded result is provenance replay,
 * the same reasoning T22 uses for QA-driven synthesis reruns, not a second
 * live read).
 */
final class ChatFactSetBuilder
{
    private function __construct()
    {
        // static-only
    }

    /**
     * @param list<Fact> $preloadedFacts
     * @param list<ChatTurn> $turns every turn row for the session, in order
     * @return list<Fact>
     */
    public static function build(array $preloadedFacts, array $turns): array
    {
        $byId = [];
        foreach ($preloadedFacts as $fact) {
            $byId[$fact->factId] = $fact;
        }

        foreach ($turns as $turn) {
            if ($turn->role !== ChatTurnRole::Tool) {
                continue;
            }

            $factsRaw = $turn->content['facts'] ?? [];
            if (!is_array($factsRaw)) {
                continue;
            }

            foreach ($factsRaw as $factData) {
                if (!is_array($factData)) {
                    continue;
                }
                try {
                    /** @var array<string, mixed> $factData */
                    $fact = Fact::fromArray($factData);
                } catch (\InvalidArgumentException) {
                    // A malformed persisted fact row is a data problem to
                    // surface via telemetry (U12), never a reason to crash a
                    // live chat turn -- skip it, the rest of the set is still
                    // usable.
                    continue;
                }
                $byId[$fact->factId] = $fact;
            }
        }

        return array_values($byId);
    }
}
