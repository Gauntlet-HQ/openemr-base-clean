<?php

/**
 * Orchestrates the public/agent.php surface: one supervisor-graph run per request.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Controller;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Agent\AgentAskRequest;
use OpenEMR\Modules\ClinicalCopilot\Agent\AgentLoopAnswerComposer;
use OpenEMR\Modules\ClinicalCopilot\Agent\AnswerStatus;
use OpenEMR\Modules\ClinicalCopilot\Agent\Supervisor;
use OpenEMR\Modules\ClinicalCopilot\Agent\SupervisorResult;
use OpenEMR\Modules\ClinicalCopilot\Agent\WorkerName;
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\DbLabTurnaroundConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Capability\ControlProxy;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedResponse;
use OpenEMR\Modules\ClinicalCopilot\Capability\OverdueTests;
use OpenEMR\Modules\ClinicalCopilot\Capability\PendingResults;
use OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend;
use OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatPromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmClientFactory;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolExecutor;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractedField;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ParsedExtraction;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use OpenEMR\Modules\ClinicalCopilot\Rag\EvidenceSnippet;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\AlertSinkInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\LoggingAlertSink;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\PatientIdentifierLookup;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptContext;
use OpenEMR\Modules\ClinicalCopilot\Reduce\RedactionMap;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verdict;
use OpenEMR\Services\PrescriptionService;
use Ramsey\Uuid\Uuid;

/**
 * `public/agent.php` is a thin bootstrap shell (method -> CSRF -> ACL ->
 * parse, build-notes.md's page-bootstrap contract) that delegates everything
 * else here, mirroring {@see ChatController}'s split. This class knows
 * nothing about HTTP superglobals or JSON encoding.
 *
 * One {@see self::ask()} call = one full Week 2 multi-agent run: the
 * deterministic {@see Supervisor} routes to the intake-extractor and/or
 * evidence-retriever workers, the LLM-backed
 * {@see AgentLoopAnswerComposer} composes a draft over chart facts its
 * tools fetched, and the {@see \OpenEMR\Modules\ClinicalCopilot\Agent\CriticWorker}
 * hard-gates it -- with the whole handoff graph recorded as one
 * correlation-id-linked span tree (`supervisor` root -> `worker` children ->
 * `verify`), inspectable in `public/dashboard.php?correlation_id=...`.
 */
final class AgentController
{
    // Distinct prompt-context identity for the agent surface (the prompt
    // discipline is the chat path's; only the surface label differs).
    private const DOC_TYPE = 'endo-agent-v1';
    private const PROMPT_VERSION = 'agent-v1';

    public function __construct(
        private readonly PatientIdentifierLookup $identifierLookup,
        private readonly AlertSinkInterface $alertSink,
        private readonly SystemLogger $logger,
    ) {
    }

    public static function createDefault(): self
    {
        return new self(
            new PatientIdentifierLookup(),
            new LoggingAlertSink(),
            new SystemLogger(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function ask(AgentAskRequest $ask, int $userId): array
    {
        if (!$this->identifierLookup->exists($ask->pid)) {
            return ['ok' => false, 'http_status' => 404, 'reason' => 'patient not found'];
        }

        // Module convention (synthesis/chat paths): the correlation id is
        // minted server-side per invocation, UUIDv7, and every span of this
        // run hangs off it.
        $correlationId = Uuid::uuid7()->toString();
        $identifiers = $this->identifierLookup->forPid($ask->pid) ?? new PatientIdentifiers('', '', '', '');

        $composer = $this->buildComposer($ask->pid, $correlationId, $identifiers);
        $result = Supervisor::createDefault($composer)->handle($ask->toAgentRequest($correlationId));

        $this->logAsk($ask->pid, $userId, $correlationId, $result, $composer->lastUnavailableReason());
        $this->auditAsk($ask->pid, $correlationId, $result);

        return self::response($ask->pid, $correlationId, $result, $composer);
    }

    /**
     * Mirrors {@see ChatController::buildChatAgent()}'s wiring one level
     * down: the same five capabilities behind the same {@see ToolExecutor},
     * the same chat LLM client/prompt assembler/redactor. The one deliberate
     * difference: `toolsEnabled: true`. The chat surface preloads the
     * synthesis doc's facts and keeps its (tested, dormant) tool loop off
     * for interactive latency; the agent surface starts from an EMPTY fact
     * set, so the tool loop is exactly how its grounding facts get fetched
     * -- and every claim the composer emits must cite one of them or the
     * critic refuses the draft.
     */
    private function buildComposer(int $pid, string $correlationId, PatientIdentifiers $identifiers): AgentLoopAnswerComposer
    {
        $labContractConfigProvider = new DbLabContractConfigProvider();
        $labSliceReader = new LabSliceReader($labContractConfigProvider);
        $turnaroundConfigProvider = new DbLabTurnaroundConfigProvider();

        $toolExecutor = new ToolExecutor(
            $pid,
            $correlationId,
            new ControlProxy($labSliceReader),
            new MedResponse(new PrescriptionService(), $labSliceReader),
            new VitalsTrend(),
            new OverdueTests($labSliceReader, $labContractConfigProvider, ServiceContainer::getClock()),
            new PendingResults($labSliceReader, $turnaroundConfigProvider),
            $this->alertSink,
        );

        $agentLoop = new AgentLoop(
            ChatLlmClientFactory::create(),
            $toolExecutor,
            new ChatPromptAssembler(),
            new Redactor(),
            "agent:{$correlationId}",
            $identifiers,
            // Same modest per-run budgets as a chat turn (see ChatController
            // for the rate/quota rationale) -- one agent ask is one-shot, but
            // the token discipline is identical.
            new PromptContext(
                self::DOC_TYPE,
                self::PROMPT_VERSION,
                LlmRuntimeConfig::chatModel(),
                maxOutputTokens: 8192,
                thinkingBudget: 2048,
            ),
            null,
            toolsEnabled: true,
        );

        return new AgentLoopAnswerComposer($agentLoop, $this->logger);
    }

    /**
     * @return array<string, mixed>
     */
    private static function response(int $pid, string $correlationId, SupervisorResult $result, AgentLoopAnswerComposer $composer): array
    {
        return [
            'ok' => true,
            'pid' => $pid,
            'correlation_id' => $correlationId,
            'routed' => array_map(static fn (WorkerName $w): string => $w->value, $result->routed),
            'answer_status' => self::answerStatusWire($result->answerStatus),
            'refusal_message' => $result->refusalMessage,
            'degraded_reason' => $composer->lastUnavailableReason(),
            'claims' => self::claimsArray($result->answer, $composer->lastRedactionMap()),
            'verdicts' => array_map(static fn (Verdict $v): array => $v->toArray(), $result->verdicts),
            'evidence' => array_map(static fn (EvidenceSnippet $s): array => [
                'title' => $s->chunk->title,
                'source' => $s->chunk->source,
                'section' => $s->chunk->section,
                'score' => $s->score,
                'citation' => $s->citation->toArray(),
            ], $result->evidence),
            'extraction' => self::extractionArray($result->extraction),
        ];
    }

    private static function answerStatusWire(?AnswerStatus $status): ?string
    {
        if ($status === null) {
            // No draft was composed: either nothing to answer, or the LLM was
            // unavailable (see `degraded_reason`). Distinct from a refusal.
            return null;
        }

        return match ($status) {
            AnswerStatus::Answered => 'answered',
            AnswerStatus::Refused => 'refused',
            AnswerStatus::FrozenSev1 => 'frozen_sev1',
        };
    }

    /**
     * Same rehydration {@see ChatController::rehydratedClaimsArray()} does:
     * the composer's prompt was redacted (direct identifiers -> per-session
     * tokens), so claim text is rehydrated for display via the run's own map.
     *
     * @param list<Claim>|null $claims
     *
     * @return list<array<string, mixed>>|null
     */
    private static function claimsArray(?array $claims, ?RedactionMap $map): ?array
    {
        if ($claims === null) {
            return null;
        }

        $redactor = new Redactor();

        return array_map(static function (Claim $claim) use ($redactor, $map): array {
            $text = $map !== null ? $redactor->rehydrate($claim->text, $map) : $claim->text;
            $emphasis = $claim->emphasis !== null && $map !== null ? $redactor->rehydrate($claim->emphasis, $map) : $claim->emphasis;

            return [
                'text' => $text,
                'claim_type' => $claim->claimType->value,
                'citation_ids' => $claim->citationIds,
                'numeric_values' => $claim->numericValues,
                'flags' => $claim->flags,
                'order' => $claim->order,
                'emphasis' => $emphasis,
            ];
        }, $claims);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function extractionArray(?ParsedExtraction $extraction): ?array
    {
        if ($extraction === null) {
            return null;
        }

        return [
            'doc_type' => $extraction->docType->value,
            'fields' => array_map(static fn (ExtractedField $f): array => [
                'field_key' => $f->fieldKey,
                'value' => $f->value ?? $f->vlmValue,
                'unit' => $f->unit,
                'ref_range' => $f->refRange,
                'abnormal_flag' => $f->abnormalFlag,
                'confidence' => $f->confidence,
                'citation' => $f->citation?->toArray(),
            ], $extraction->fields),
        ];
    }

    /**
     * Observability log: run metadata only -- never the question or answer
     * text (the raw model response is already logged pre-verification by the
     * loop's own plumbing; the claims live in the HTTP response).
     */
    private function logAsk(int $pid, int $userId, string $correlationId, SupervisorResult $result, ?string $degradedReason): void
    {
        $this->logger->info('Clinical Co-Pilot agent ask', [
            'correlation_id' => $correlationId,
            'pid' => $pid,
            'user_id' => $userId,
            'routed' => array_map(static fn (WorkerName $w): string => $w->value, $result->routed),
            'answer_status' => self::answerStatusWire($result->answerStatus),
            'degraded_reason' => $degradedReason,
            'evidence_count' => count($result->evidence),
            'verdicts_failed' => count(array_filter(
                $result->verdicts,
                static fn (Verdict $v): bool => !$v->passed && !$v->skipped,
            )),
        ]);
    }

    /**
     * HIPAA audit event, PHI-free metadata only (mirrors
     * {@see ChatController::auditTurn()}).
     */
    private function auditAsk(int $pid, string $correlationId, SupervisorResult $result): void
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $authUser = (string)($session->get('authUser') ?? '');
        $authProvider = (string)($session->get('authProvider') ?? '');

        $summary = sprintf(
            'Clinical Co-Pilot agent ask, correlation_id=%s, routed=%s, answer_status=%s',
            $correlationId,
            implode('>', array_map(static fn (WorkerName $w): string => $w->value, $result->routed)),
            self::answerStatusWire($result->answerStatus) ?? 'none',
        );

        EventAuditLogger::getInstance()->newEvent(
            'patient-record',
            $authUser,
            $authProvider,
            1,
            $summary,
            $pid,
        );
    }
}
