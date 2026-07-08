<?php

/**
 * The session's fact set (preloaded facts ∪ this session's tool results) with the pinned pid.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;

/**
 * V2 resolves `citation_ids` against exactly this set (ARCHITECTURE.md §2.2:
 * "facts present in the session fact set (preloaded facts ∪ this session's
 * tool results)") -- indexed by `fact_id` for O(1) resolution regardless of
 * how many tool calls a chat turn made. `pinnedPid` is the session's
 * server-injected patient id (I10) V3 checks every resolved fact against;
 * it is a CONSTRUCTOR argument here, never derived from the facts
 * themselves, so a test (or a caller) can deliberately seed a
 * wrong-patient fact to exercise V3's independent re-check.
 */
final class SessionFactSet
{
    /** @var array<string, Fact> */
    private readonly array $byId;

    /**
     * @param list<Fact> $facts
     */
    public function __construct(
        public readonly int $pinnedPid,
        array $facts,
    ) {
        $index = [];
        foreach ($facts as $fact) {
            $index[$fact->factId] = $fact;
        }
        $this->byId = $index;
    }

    public function resolve(string $factId): ?Fact
    {
        return $this->byId[$factId] ?? null;
    }

    /**
     * @return list<Fact>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    /**
     * The closed set V6(ii) enumerates against on the synthesis path.
     *
     * @return list<Fact>
     */
    public function conflictFlagged(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (Fact $fact): bool => $fact->hasFlag(Flag::conflict()),
        ));
    }
}
