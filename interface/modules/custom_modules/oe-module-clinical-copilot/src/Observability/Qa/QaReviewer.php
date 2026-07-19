<?php

/**
 * The post-mortem QA accuracy agent: sweeps served docs/chat turns, scores them, never gates.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Qa;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatFactSetBuilder;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnStore;
use OpenEMR\Modules\ClinicalCopilot\Doc\QaStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\PatientIdentifierLookup;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisDocPayload;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;

/**
 * docs/build-notes.md "U12 additions" (user decision of record): "sweeps
 * recently-served, not-yet-QA'd mod_copilot_doc and mod_copilot_chat_turn
 * rows on the worker tick ... re-reads each rendered answer against that
 * row's stored session fact set from scratch, and logs its verdict to
 * mod_copilot_qa. Idempotent." This class IS that sweep -- {@see self::sweep()}
 * is the ONE method U9's worker tick calls.
 *
 * Guardrail (T15, restated everywhere in this module): ADVISORY ONLY. This
 * class never touches `mod_copilot_doc.doc`, never changes what was already
 * served, never blocks anything -- it only appends one row per target to
 * {@see QaStore} and, for doc targets only, narrowly annotates
 * `qa_status`/`qa_score` via {@see DocQaAnnotator} (a one-way pending ->
 * terminal transition, see that class's docblock).
 *
 * A target with a fully degraded (facts-only) attempt has no narrative to
 * review -- {@see self::reviewDegraded()} records that honestly as `status =
 * 'ok'` (the sweep succeeded; there was simply nothing to concur or disagree
 * with) rather than calling the Flash reviewer over an empty narrative.
 */
final class QaReviewer
{
    public function __construct(
        private readonly QaStore $qaStore,
        private readonly DocQaAnnotator $docAnnotator,
        private readonly FlashReviewer $flashReviewer,
        private readonly PatientIdentifierLookup $identifierLookup,
        private readonly ChatSessionStore $chatSessionStore,
        private readonly ChatTurnStore $chatTurnStore,
        private readonly SystemLogger $logger = new SystemLogger(),
    ) {
    }

    public static function createDefault(): self
    {
        return new self(
            new QaStore(),
            new DocQaAnnotator(),
            new FlashReviewer(LlmClientFactory::create()),
            new PatientIdentifierLookup(),
            new ChatSessionStore(),
            new ChatTurnStore(),
        );
    }

    /**
     * Sweeps up to `$limit` not-yet-QA'd targets (doc rows first, then chat
     * turns fill any remaining budget) and returns a summary U9's worker uses
     * both for its own logging and for the T22 rerun decision (via
     * {@see QaSweepSummary::docOutcomes()}).
     */
    public function sweep(int $limit): QaSweepSummary
    {
        if ($limit <= 0) {
            return QaSweepSummary::empty();
        }

        $threshold = $this->loadThresholdConfig();
        $outcomes = [];
        $ok = 0;
        $low = 0;
        $unavailable = 0;
        $errors = 0;

        foreach ($this->pendingDocTargets($limit) as $row) {
            try {
                $outcome = $this->reviewDoc($row, $threshold);
            } catch (\Throwable $e) {
                // One target failing -- e.g. a UNIQUE(target_type,target_id)
                // race when two ticks overlap on the same still-pending row --
                // must not abort the whole batch and silently no-op the T22
                // rerun stage for this tick. Skip it, count it, keep going.
                $this->logger->error('ClinicalCopilot: QA review of a doc target failed', ['exception' => $e]);
                $errors++;
                continue;
            }
            if ($outcome === null) {
                continue;
            }
            $outcomes[] = $outcome;
            [$ok, $low, $unavailable, $errors] = self::tally($outcome, $ok, $low, $unavailable, $errors);
        }

        $remaining = $limit - count($outcomes);
        if ($remaining > 0) {
            foreach ($this->pendingChatTurnTargets($remaining) as $row) {
                try {
                    $outcome = $this->reviewChatTurn($row, $threshold);
                } catch (\Throwable $e) {
                    $this->logger->error('ClinicalCopilot: QA review of a chat-turn target failed', ['exception' => $e]);
                    $errors++;
                    continue;
                }
                if ($outcome === null) {
                    continue;
                }
                $outcomes[] = $outcome;
                [$ok, $low, $unavailable, $errors] = self::tally($outcome, $ok, $low, $unavailable, $errors);
            }
        }

        return new QaSweepSummary(count($outcomes), $ok, $low, $unavailable, $errors, $outcomes);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private static function tally(QaSweepOutcome $outcome, int $ok, int $low, int $unavailable, int $errors): array
    {
        return match (true) {
            $outcome->status === 'unavailable' => [$ok, $low, $unavailable + 1, $errors],
            $outcome->status === 'error' => [$ok, $low, $unavailable, $errors + 1],
            $outcome->qaStatus === QaStatus::Low => [$ok, $low + 1, $unavailable, $errors],
            default => [$ok + 1, $low, $unavailable, $errors],
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reviewDoc(array $row, array $threshold): ?QaSweepOutcome
    {
        $docId = (int)$row['id'];
        $pid = (int)$row['pid'];
        $correlationId = (string)$row['correlation_id'];
        $factDigest = (string)$row['fact_digest'];

        if ($this->qaStore->existsFor(QaTargetType::Doc, $docId)) {
            // Race with a concurrent sweep already handled this row --
            // idempotent no-op (docs/build-notes.md).
            return null;
        }

        $docArray = json_decode((string)$row['doc'], true);
        if (!is_array($docArray)) {
            $this->logger->error('ClinicalCopilot: QA sweep could not decode doc JSON', ['doc_id' => $docId]);

            return null;
        }
        /** @var array<string, mixed> $docArray */
        $payload = SynthesisDocPayload::fromDocArray($docArray);

        if ($payload->claims === null || $payload->claims === []) {
            return $this->recordDegraded(QaTargetType::Doc, $docId, $correlationId, $pid, null, $factDigest, $payload->facts);
        }

        $identifiers = $this->identifierLookup->forPid($pid) ?? new PatientIdentifiers('', '', '', '');
        $result = $this->flashReviewer->review("qa:doc:{$docId}", $payload->facts, $payload->claims, $identifiers);

        return $this->recordReviewed(QaTargetType::Doc, $docId, $correlationId, $pid, null, $factDigest, $payload->facts, $payload->claims, $result, $threshold, annotateDoc: true);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reviewChatTurn(array $row, array $threshold): ?QaSweepOutcome
    {
        $turnId = (int)$row['id'];
        $sessionId = (int)$row['session_id'];
        $seq = (int)$row['seq'];
        $correlationId = (string)$row['correlation_id'];

        if ($this->qaStore->existsFor(QaTargetType::ChatTurn, $turnId)) {
            return null;
        }

        $session = $this->chatSessionStore->find($sessionId);
        if ($session === null) {
            $this->logger->error('ClinicalCopilot: QA sweep could not resolve chat session for turn', ['turn_id' => $turnId, 'session_id' => $sessionId]);

            return null;
        }

        $content = json_decode((string)$row['content'], true);
        $content = is_array($content) ? $content : [];

        $claimsRaw = $content['claims'] ?? null;
        $claims = null;
        if (is_array($claimsRaw)) {
            $claims = [];
            foreach ($claimsRaw as $claimData) {
                if (is_array($claimData)) {
                    try {
                        /** @var array<string, mixed> $claimData */
                        $claims[] = Claim::fromArray($claimData);
                    } catch (\InvalidArgumentException) {
                        // A malformed persisted claim is a data problem, not a
                        // reason to abort the whole sweep target -- skip it,
                        // the rest of the narrative is still reviewable.
                        continue;
                    }
                }
            }
        }

        $verifyStatus = $content['verify_status'] ?? null;

        $preloadedFacts = $session->docId !== null ? $this->preloadedFactsForDoc($session->docId) : [];
        $allTurns = $this->chatTurnStore->forSession($sessionId);
        $turnsUpToHere = array_values(array_filter($allTurns, static fn ($t): bool => $t->seq <= $seq));
        $sessionFacts = ChatFactSetBuilder::build($preloadedFacts, $turnsUpToHere);

        if ($claims === null || $claims === [] || $verifyStatus !== 'passed') {
            return $this->recordDegraded(QaTargetType::ChatTurn, $turnId, $correlationId, $session->pid, $session->userId, null, $sessionFacts);
        }

        $identifiers = $this->identifierLookup->forPid($session->pid) ?? new PatientIdentifiers('', '', '', '');
        $result = $this->flashReviewer->review("qa:chat_turn:{$turnId}", $sessionFacts, $claims, $identifiers);

        return $this->recordReviewed(QaTargetType::ChatTurn, $turnId, $correlationId, $session->pid, $session->userId, null, $sessionFacts, $claims, $result, $threshold, annotateDoc: false);
    }

    /**
     * @param list<Fact> $facts
     */
    private function recordDegraded(
        QaTargetType $targetType,
        int $targetId,
        string $correlationId,
        int $pid,
        ?int $userId,
        ?string $factDigest,
        array $facts,
    ): QaSweepOutcome {
        $this->qaStore->insert(new NewQaVerdict(
            $targetType,
            $targetId,
            $correlationId,
            $pid,
            $userId,
            null,
            null,
            null,
            [],
            0.0,
            QaMetrics::factUtilizationRate($facts, []),
            'no narrative available for this attempt (facts-only/degraded) -- nothing to review',
            null,
            null,
            null,
            'ok',
        ));

        if ($targetType === QaTargetType::Doc) {
            $this->docAnnotator->annotate($targetId, QaStatus::Ok, null);
        }

        return new QaSweepOutcome($targetType, $targetId, $pid, $factDigest, 'ok', null, QaStatus::Ok, null, null);
    }

    /**
     * @param list<Fact> $facts
     * @param list<Claim> $claims
     * @param array{concurrence_required: bool, salience_required: bool, low_score_below: float} $threshold
     */
    private function recordReviewed(
        QaTargetType $targetType,
        int $targetId,
        string $correlationId,
        int $pid,
        ?int $userId,
        ?string $factDigest,
        array $facts,
        array $claims,
        FlashReviewResult $result,
        array $threshold,
        bool $annotateDoc,
    ): QaSweepOutcome {
        $densityRatio = QaMetrics::densityRatio($claims);
        $factUtilization = QaMetrics::factUtilizationRate($facts, $claims);

        if ($result->status !== 'ok') {
            $this->qaStore->insert(new NewQaVerdict(
                $targetType,
                $targetId,
                $correlationId,
                $pid,
                $userId,
                $result->model,
                null,
                null,
                [],
                $densityRatio,
                $factUtilization,
                $result->reviewerNote,
                $result->tokensIn,
                $result->tokensOut,
                $result->costUsd,
                $result->status,
            ));

            if ($annotateDoc) {
                $this->docAnnotator->annotate($targetId, QaStatus::Unavailable, null);
            }

            return new QaSweepOutcome($targetType, $targetId, $pid, $factDigest, $result->status, null, QaStatus::Unavailable, null, null);
        }

        $qaScore = self::score($result->concurs ?? false, $result->salienceOk ?? false);
        $qaStatus = self::classify($result->concurs ?? false, $result->salienceOk ?? false, $qaScore, $threshold);

        $this->qaStore->insert(new NewQaVerdict(
            $targetType,
            $targetId,
            $correlationId,
            $pid,
            $userId,
            $result->model,
            $result->concurs,
            $result->salienceOk,
            $result->flags,
            $densityRatio,
            $factUtilization,
            $result->reviewerNote,
            $result->tokensIn,
            $result->tokensOut,
            $result->costUsd,
            'ok',
        ));

        if ($annotateDoc) {
            $this->docAnnotator->annotate($targetId, $qaStatus, $qaScore);
        }

        return new QaSweepOutcome($targetType, $targetId, $pid, $factDigest, 'ok', $qaScore, $qaStatus, $result->concurs, $result->salienceOk);
    }

    private static function score(bool $concurs, bool $salienceOk): float
    {
        return ($concurs ? 0.6 : 0.0) + ($salienceOk ? 0.4 : 0.0);
    }

    /**
     * @param array{concurrence_required: bool, salience_required: bool, low_score_below: float} $threshold
     */
    private static function classify(bool $concurs, bool $salienceOk, float $qaScore, array $threshold): QaStatus
    {
        if ($threshold['concurrence_required'] && !$concurs) {
            return QaStatus::Low;
        }
        if ($threshold['salience_required'] && !$salienceOk) {
            return QaStatus::Low;
        }
        if ($qaScore < $threshold['low_score_below']) {
            return QaStatus::Low;
        }

        return QaStatus::Ok;
    }

    /**
     * @return array{concurrence_required: bool, salience_required: bool, low_score_below: float}
     */
    private function loadThresholdConfig(): array
    {
        $raw = QueryUtils::fetchSingleValue(
            "SELECT `config_json` FROM `mod_copilot_cadence` WHERE `code_set` = 'qa_threshold'",
            'config_json',
        );
        $config = is_string($raw) ? json_decode($raw, true) : null;
        $config = is_array($config) ? $config : [];

        return [
            'concurrence_required' => (bool)($config['concurrence_required'] ?? true),
            'salience_required' => (bool)($config['salience_required'] ?? true),
            'low_score_below' => (float)($config['low_score_below'] ?? 0.6),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingDocTargets(int $limit): array
    {
        return QueryUtils::fetchRecords(
            "SELECT `id`, `pid`, `fact_digest`, `doc`, `correlation_id`
             FROM `mod_copilot_doc`
             WHERE `qa_status` = 'pending'
             ORDER BY `id` ASC
             LIMIT " . QueryUtils::escapeLimit($limit),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingChatTurnTargets(int $limit): array
    {
        return QueryUtils::fetchRecords(
            "SELECT t.`id`, t.`session_id`, t.`seq`, t.`correlation_id`, t.`content`
             FROM `mod_copilot_chat_turn` t
             LEFT JOIN `mod_copilot_qa` q ON q.`target_type` = 'chat_turn' AND q.`target_id` = t.`id`
             WHERE t.`role` = 'assistant' AND q.`id` IS NULL
             ORDER BY t.`id` ASC
             LIMIT " . QueryUtils::escapeLimit($limit),
        );
    }

    /**
     * @return list<Fact>
     */
    private function preloadedFactsForDoc(int $docId): array
    {
        $row = QueryUtils::querySingleRow('SELECT `doc` FROM `mod_copilot_doc` WHERE `id` = ?', [$docId]);
        if (!is_array($row)) {
            return [];
        }

        $docArray = json_decode((string)$row['doc'], true);
        if (!is_array($docArray)) {
            return [];
        }

        /** @var array<string, mixed> $docArray */
        return SynthesisDocPayload::fromDocArray($docArray)->facts;
    }
}
