<?php

/**
 * Guards: the session fact set is preloaded facts UNION every tool result ever recorded, replayed from the ledger.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat;

use OpenEMR\Modules\ClinicalCopilot\Chat\ChatFactSetBuilder;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurn;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnRole;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact\FactTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §1.1/§2.2 (V2): a chat turn's citations must resolve
 * against "preloaded facts UNION this session's tool results" -- not only
 * the CURRENT turn's tool results. This asserts a fact fetched on an EARLIER
 * turn (recorded on a `tool`-role ledger row) is still resolvable when a
 * LATER turn's fact set is rebuilt -- the mechanism an anaphoric "and the
 * one before that?" depends on two turns later.
 */
final class ChatFactSetBuilderTest extends TestCase
{
    public function testPreloadedAndHistoricalToolFactsAreBothIncluded(): void
    {
        $preloaded = FactTestFactory::a1cTrendPoint(1, 1, '7.2', '2025-01-10');
        $fetchedTurnsAgo = FactTestFactory::a1cTrendPoint(1, 2, '6.9', '2024-10-01');

        $toolTurn = new ChatTurn(
            10,
            1,
            2,
            ChatTurnRole::Tool,
            ['tool' => 'get_control_trend', 'arguments' => [], 'ok' => true, 'error' => null, 'facts' => [$fetchedTurnsAgo->toArray()]],
            null,
            null,
            'corr-1',
            null,
            null,
            null,
            new \DateTimeImmutable(),
        );

        $built = ChatFactSetBuilder::build([$preloaded], [$toolTurn]);
        $ids = array_map(static fn ($f) => $f->factId, $built);

        self::assertContains($preloaded->factId, $ids);
        self::assertContains($fetchedTurnsAgo->factId, $ids);
    }

    public function testMalformedFactRowsAreSkippedRatherThanCrashing(): void
    {
        $preloaded = FactTestFactory::a1cTrendPoint(1, 1, '7.2', '2025-01-10');

        $toolTurn = new ChatTurn(
            11,
            1,
            2,
            ChatTurnRole::Tool,
            ['tool' => 'get_control_trend', 'arguments' => [], 'ok' => false, 'error' => 'boom', 'facts' => [['not' => 'a valid fact shape']]],
            null,
            null,
            'corr-2',
            null,
            null,
            null,
            new \DateTimeImmutable(),
        );

        $built = ChatFactSetBuilder::build([$preloaded], [$toolTurn]);

        self::assertCount(1, $built);
        self::assertSame($preloaded->factId, $built[0]->factId);
    }

    public function testUserAndAssistantTurnsContributeNoFacts(): void
    {
        $preloaded = FactTestFactory::a1cTrendPoint(1, 1, '7.2', '2025-01-10');

        $userTurn = new ChatTurn(1, 1, 1, ChatTurnRole::User, ['text' => 'hi'], null, null, 'corr-3', null, null, null, new \DateTimeImmutable());
        $assistantTurn = new ChatTurn(2, 1, 2, ChatTurnRole::Assistant, ['claims' => []], null, null, 'corr-3', null, null, null, new \DateTimeImmutable());

        $built = ChatFactSetBuilder::build([$preloaded], [$userTurn, $assistantTurn]);

        self::assertCount(1, $built);
    }
}
