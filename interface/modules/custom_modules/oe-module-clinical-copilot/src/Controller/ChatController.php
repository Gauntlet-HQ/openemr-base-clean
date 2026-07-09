<?php

/**
 * Orchestrates the chat surface: session bootstrap, per-turn execution, T19 freshness, freezing.
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
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\DbLabTurnaroundConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Capability\ControlProxy;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedResponse;
use OpenEMR\Modules\ClinicalCopilot\Capability\OverdueTests;
use OpenEMR\Modules\ClinicalCopilot\Capability\PendingResults;
use OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend;
use OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatAgent;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatAnswer;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatFactSetBuilder;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatFreshnessChecker;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatPromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSession;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionSeeder;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionStatus;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurn;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnConfidence;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnRole;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\ChatLlmClientFactory;
use OpenEMR\Modules\ClinicalCopilot\Chat\NewChatTurn;
use OpenEMR\Modules\ClinicalCopilot\Chat\RateLimit\CircuitBreakerInterface;
use OpenEMR\Modules\ClinicalCopilot\Chat\RateLimit\RateLimiterInterface;
use OpenEMR\Modules\ClinicalCopilot\Chat\SessionTurnLock;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolExecutor;
use OpenEMR\Modules\ClinicalCopilot\Chat\TracePoller;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceCircuitBreaker;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceRateLimiter;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\AlertSinkInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\LoggingAlertSink;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\PatientIdentifierLookup;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisDocPayload;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptContext;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use OpenEMR\Services\PrescriptionService;
use Ramsey\Uuid\Uuid;

/**
 * `public/chat.php` (and `public/status.php` read-only) are thin bootstrap
 * shells (CSRF -> ACL -> session identity, build-notes.md's page-bootstrap
 * contract) that delegate everything else here, mirroring
 * {@see DocController}'s own split. This class knows nothing about HTTP
 * superglobals, SSE framing, or Twig.
 *
 * Every method that touches `pid`/session state re-validates the session's
 * `user_id` against the authenticated caller (ARCHITECTURE.md §1.3: "the
 * session's user_id must equal the authenticated user on every turn") --
 * this is NOT optional per-call, it is the whole reason a session is
 * pid+user pinned rather than pid-only.
 */
final class ChatController
{
    private const MAX_TURNS_PER_SESSION = 30;
    private const DOC_TYPE = 'endo-previsit-chat-v1';
    private const PROMPT_VERSION = 'chat-v1';

    private static function model(): string
    {
        return LlmRuntimeConfig::reduceAndChatModel();
    }

    public function __construct(
        private readonly DocStore $docStore,
        private readonly ChatSessionStore $sessionStore,
        private readonly ChatTurnStore $turnStore,
        private readonly ChatSessionSeeder $seeder,
        private readonly PatientIdentifierLookup $identifierLookup,
        private readonly ChatFreshnessChecker $freshnessChecker,
        private readonly SessionTurnLock $turnLock,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly CircuitBreakerInterface $circuitBreaker,
        private readonly AlertSinkInterface $alertSink,
        private readonly TraceRecorderInterface $tracer,
        private readonly TracePoller $tracePoller,
        private readonly SystemLogger $logger,
    ) {
    }

    public static function createDefault(): self
    {
        $labContractConfigProvider = new DbLabContractConfigProvider();
        $labSliceReader = new LabSliceReader($labContractConfigProvider);
        $turnaroundConfigProvider = new DbLabTurnaroundConfigProvider();

        $capabilities = [
            new ControlProxy($labSliceReader),
            new MedResponse(new PrescriptionService(), $labSliceReader),
            new VitalsTrend(),
            new OverdueTests($labSliceReader, $labContractConfigProvider, ServiceContainer::getClock()),
            new PendingResults($labSliceReader, $turnaroundConfigProvider),
        ];

        $docStore = new DocStore();
        $sessionStore = new ChatSessionStore();
        $alertSink = new LoggingAlertSink();

        return new self(
            $docStore,
            $sessionStore,
            new ChatTurnStore(),
            new ChatSessionSeeder(SynthesisReadPath::createDefault(), $sessionStore),
            new PatientIdentifierLookup(),
            new ChatFreshnessChecker($capabilities, $labContractConfigProvider, $turnaroundConfigProvider),
            new SessionTurnLock(),
            new CadenceRateLimiter(),
            new CadenceCircuitBreaker(),
            $alertSink,
            new TraceRecorder(),
            new TracePoller(),
            new SystemLogger(),
        );
    }

    /**
     * Lazily creates a session for `$pid` (ARCHITECTURE.md §1.1) -- called
     * once when the physician opens the chat panel beside the synthesis doc.
     *
     * @return array{ok: bool, session_id: int|null, reason: string|null, resumed: bool, stale: bool, turns: list<array{role: string, text: ?string, result: ?array<string, mixed>}>}
     */
    public function startSession(int $pid, int $userId): array
    {
        if (!$this->identifierLookup->exists($pid)) {
            return ['ok' => false, 'session_id' => null, 'reason' => 'patient not found', 'resumed' => false, 'stale' => false, 'turns' => []];
        }

        // Close out this clinician's idle sessions first, so the resume lookup
        // below reflects only genuinely-live sessions: a chart reopened after
        // the idle window mints a fresh session rather than resuming a stale
        // one, and abandoned sessions stop counting toward the per-user cap.
        $this->sessionStore->expireIdleForUser($userId);

        $existingId = null;
        $existing = $this->sessionStore->findLatestActiveForUserAndPid($userId, $pid);
        if ($existing !== null) {
            $existingId = $existing->id;
        }

        $session = $this->seeder->seed($pid, $userId);
        if ($session === null) {
            return ['ok' => false, 'session_id' => null, 'reason' => 'synthesis unavailable -- open the synthesis doc first', 'resumed' => false, 'stale' => false, 'turns' => []];
        }

        $resumed = $existingId !== null && $session->id === $existingId;
        $stale = $resumed && $this->freshnessChecker->hasDrifted($session->pid, $session->factDigest);

        return [
            'ok' => true,
            'session_id' => $session->id,
            'reason' => null,
            'resumed' => $resumed,
            'stale' => $stale,
            'turns' => $this->clientTurnHistory($session->id),
        ];
    }

    /**
     * T19's one-click re-seed: a fresh session for the same patient off the
     * CURRENT doc. The old session is left exactly as it is -- abandoned,
     * never frozen (only a V3 sev-1 trip freezes a session).
     *
     * @return array{ok: bool, session_id: int|null, reason: string|null, resumed: bool, stale: bool, turns: list<array{role: string, text: ?string, result: ?array<string, mixed>}>}
     */
    public function reseed(int $pid, int $userId): array
    {
        if (!$this->identifierLookup->exists($pid)) {
            return ['ok' => false, 'session_id' => null, 'reason' => 'patient not found', 'resumed' => false, 'stale' => false, 'turns' => []];
        }

        // Same idle sweep as startSession: a manual re-seed is a fresh start,
        // so retire the clinician's stale sessions before minting the new one.
        $this->sessionStore->expireIdleForUser($userId);

        $session = $this->seeder->seed($pid, $userId, forceNew: true);
        if ($session === null) {
            return ['ok' => false, 'session_id' => null, 'reason' => 'synthesis unavailable -- open the synthesis doc first', 'resumed' => false, 'stale' => false, 'turns' => []];
        }

        return [
            'ok' => true,
            'session_id' => $session->id,
            'reason' => null,
            'resumed' => false,
            'stale' => false,
            'turns' => [],
        ];
    }

    /**
     * Manual "release sessions" escape hatch: force-close all of the caller's
     * active sessions so the per-user active-session cap stops denying turns.
     * The automatic idle sweep ({@see ChatSessionStore::expireIdleForUser()})
     * only frees sessions past the idle window, which does not help a clinician
     * who is actively juggling more live charts than the cap allows -- this
     * gives them a one-click reset instead of a dead end.
     *
     * `$keepSessionId` preserves the session the clinician is currently in (the
     * chat panel passes its own session id) so the open conversation survives
     * while the abandoned ones are cleared.
     *
     * @return array{ok: bool, released: int}
     */
    public function releaseSessions(int $userId, ?int $keepSessionId = null): array
    {
        $released = $this->sessionStore->countActiveForUser($userId, $keepSessionId);
        $this->sessionStore->expireAllForUser($userId, $keepSessionId);

        return ['ok' => true, 'released' => $released];
    }

    /**
     * `public/status.php`'s polling fallback (ARCHITECTURE.md §1.3): reads
     * the SAME ledger the turn is writing rather than a parallel status
     * variable, so "what the progress UI shows" is provably what happened,
     * never something that can drift from it. Ownership is checked via the
     * turn ledger itself (the `user`-role row for this correlation id is
     * always written before any LLM/tool work starts) -- a correlation id
     * belonging to another user's session is refused exactly like a direct
     * session-id mismatch on {@see self::submitTurn()}.
     *
     * @return array<string, mixed>
     */
    public function pollStatus(string $correlationId, int $userId): array
    {
        $turns = $this->turnStore->findByCorrelationId($correlationId);
        if ($turns === []) {
            // Either the turn has not started yet (client polled before the
            // POST's user-turn insert landed) or the correlation id is
            // simply unknown -- either way, nothing to leak, so this is not
            // itself an error.
            return ['ok' => true, 'done' => false, 'spans' => [], 'turn' => null];
        }

        $session = $this->sessionStore->find($turns[0]->sessionId);
        if ($session === null || $session->userId !== $userId) {
            return $this->errorResponse(403, 'not authorized for this correlation id');
        }

        $spans = $this->tracePoller->forCorrelationId($correlationId);

        foreach ($turns as $turn) {
            if ($turn->role === ChatTurnRole::Assistant) {
                return [
                    'ok' => true,
                    'done' => true,
                    'spans' => $spans,
                    'turn' => [
                        'verify_status' => $turn->content['verify_status'] ?? null,
                        'confidence' => $turn->content['confidence'] ?? null,
                        'confidence_label' => $turn->content['confidence_label'] ?? null,
                        'degraded_message' => $turn->content['degraded_message'] ?? null,
                        'frozen' => $turn->content['frozen'] ?? false,
                        'claims' => $turn->content['claims'] ?? null,
                        'verdicts' => $turn->verificationVerdict,
                    ],
                ];
            }
        }

        return ['ok' => true, 'done' => false, 'spans' => $spans, 'turn' => null];
    }

    /**
     * Executes one turn, synchronously, start to finish (ARCHITECTURE.md
     * §1.3: "the request IS the turn"). Every branch below that returns
     * ends the turn WITHOUT ever calling {@see \OpenEMR\Modules\ClinicalCopilot\Chat\AgentLoop} --
     * the concurrency lock, session/user identity check, frozen check, and
     * rate-limit/breaker checks all happen before a single LLM call is even
     * attempted.
     *
     * @param (\Closure(string): void)|null $onStatus optional staged-status
     *        callback for SSE (ARCHITECTURE.md §1.3); `public/chat.php`
     *        supplies one only when the client requested a streamed
     *        response, otherwise this is null and the turn behaves exactly
     *        like a plain synchronous POST
     * @return array<string, mixed>
     */
    public function submitTurn(int $sessionId, int $userId, string $message, ?\Closure $onStatus = null): array
    {
        $session = $this->sessionStore->find($sessionId);
        if ($session === null) {
            return $this->errorResponse(404, 'no such chat session');
        }

        if ($session->userId !== $userId) {
            // ARCHITECTURE.md §1.3: identity re-checked on EVERY turn, not
            // only at session creation.
            return $this->errorResponse(403, 'this chat session does not belong to the authenticated user');
        }

        if ($session->status === ChatSessionStatus::Frozen) {
            return $this->errorResponse(423, 'this chat session is frozen following a patient-identity verification incident and cannot be resumed');
        }

        if ($session->status === ChatSessionStatus::Expired) {
            // The session auto-closed after the idle timeout (a resumed
            // conversation always starts fresh). Signal the client to re-seed
            // and resend rather than answering under a closed session -- the
            // `session_expired` flag is what the chat panel keys its one silent
            // re-seed-and-retry on.
            return [
                'ok' => false,
                'http_status' => 409,
                'reason' => 'this chat session expired after a period of inactivity -- starting a fresh one',
                'session_expired' => true,
            ];
        }

        if (trim($message) === '') {
            return $this->errorResponse(400, 'message must not be empty');
        }
        if (strlen($message) > 4000) {
            return $this->errorResponse(400, 'message is too long');
        }

        if (!$this->turnLock->tryAcquire($sessionId)) {
            return $this->errorResponse(409, 'a turn is already in progress for this session');
        }

        try {
            return $this->runTurnLocked($session, $message, $onStatus);
        } finally {
            $this->turnLock->release($sessionId);
        }
    }

    /**
     * @param (\Closure(string): void)|null $onStatus
     * @return array<string, mixed>
     */
    private function runTurnLocked(ChatSession $session, string $message, ?\Closure $onStatus): array
    {
        if ($this->turnStore->countAssistantTurns($session->id) >= self::MAX_TURNS_PER_SESSION) {
            return $this->errorResponse(429, 'this session has reached its turn limit -- start a fresh session from the current summary');
        }

        // Retire this clinician's OTHER idle sessions before the per-user
        // active-session cap is checked, so long-abandoned sessions do not pin
        // the cap and reject this turn before the LLM is ever reached. The
        // current session is excluded from the sweep: it is active by
        // definition (a turn is running on it) and its activity for this turn
        // is written to the ledger further down, not yet visible here.
        $this->sessionStore->expireIdleForUser($session->userId, exceptSessionId: $session->id);

        $rateDecision = $this->rateLimiter->checkTurn($session->pid, $session->userId, $session->id);
        if (!$rateDecision->allowed) {
            return $this->errorResponse(429, $rateDecision->reason ?? 'rate limit exceeded');
        }

        $turnT0 = microtime(true);
        $correlationId = Uuid::uuid7()->toString();

        // Emitted BEFORE any retrieval/LLM work so an SSE client can capture
        // the correlation id immediately and fall back to polling
        // `status.php?cid=...` (ARCHITECTURE.md §1.3) if the stream itself
        // stalls or a buffering proxy in between never delivers later
        // events -- the turn keeps running server-side regardless.
        if ($onStatus !== null) {
            $onStatus("correlation_id:{$correlationId}");
        }

        $docRow = $this->docStore->findBest($session->pid, $session->factDigest);
        $preloadedFacts = [];
        $narrativeClaims = null;
        if ($docRow !== null) {
            $payload = SynthesisDocPayload::fromDocArray($docRow->doc);
            $narrativeClaims = $payload->claims;
            $preloadedFacts = $payload->facts;
        }

        $priorTurns = $this->turnStore->forSession($session->id);
        $sessionFacts = ChatFactSetBuilder::build($preloadedFacts, $priorTurns);
        $conversationTranscript = self::renderTranscript($priorTurns);

        $stale = $this->freshnessChecker->hasDrifted($session->pid, $session->factDigest);

        $nextSeq = $this->turnStore->nextSeq($session->id);
        $this->turnStore->insert(new NewChatTurn(
            $session->id,
            $nextSeq,
            ChatTurnRole::User,
            ['text' => $message],
            null,
            null,
            $correlationId,
            null,
            null,
            null,
        ));

        if ($this->circuitBreaker->isOpen()) {
            $answer = ChatAnswer::degradedBreakerOpen($sessionFacts);
        } else {
            $identifiers = $this->identifierLookup->forPid($session->pid) ?? new PatientIdentifiers('', '', '', '');
            $agent = $this->buildChatAgent($session, $correlationId, $identifiers, $onStatus);
            $answer = $agent->answer($session->pid, $correlationId, $sessionFacts, $narrativeClaims, $conversationTranscript, $message);
        }

        $confidence = ChatTurnConfidence::fromAnswer($answer);

        $this->persistToolTurns($session->id, $session->pid, $session->userId, $answer, $correlationId);
        $this->persistAssistantTurn($session, $answer, $confidence, $correlationId);

        $this->recordSpan($correlationId, 'verify', $turnT0, $answer->verifyStatus->value === 'passed' ? 'ok' : 'degraded', $session->pid, $session->userId, model: $answer->usage->modelVersion, tokensIn: $answer->usage->tokensIn, tokensOut: $answer->usage->tokensOut);
        $this->recordSpan($correlationId, 'chat_turn', $turnT0, $answer->frozen ? 'error' : ($answer->verifyStatus->value === 'passed' ? 'ok' : 'degraded'), $session->pid, $session->userId, model: $answer->usage->modelVersion, tokensIn: $answer->usage->tokensIn, tokensOut: $answer->usage->tokensOut);

        if ($answer->frozen) {
            $this->sessionStore->freeze($session->id);
            if ($answer->sev1Signal !== null) {
                $this->alertSink->sev1PatientIdentity($answer->sev1Signal);
            }
            $this->logger->error('Clinical Co-Pilot: chat session frozen on V3 sev-1 trip', [
                'correlation_id' => $correlationId,
                'session_id' => $session->id,
            ]);
        }

        foreach ($answer->toolCallLog as $entry) {
            if (!$entry->outcome->ok) {
                $this->logger->error('Clinical Co-Pilot: chat tool call failed', [
                    'correlation_id' => $correlationId,
                    'tool' => $entry->request->name,
                    'reason' => $entry->outcome->errorMessage,
                ]);
            }
        }

        $this->logChatTurn($session, $message, $answer, $confidence, $correlationId);
        $this->auditTurn($session->pid, $answer, $confidence, $correlationId);

        return $this->turnResponse($session, $answer, $confidence, $correlationId, $stale);
    }

    /**
     * @param (\Closure(string): void)|null $onStatus
     */
    private function buildChatAgent(ChatSession $session, string $correlationId, PatientIdentifiers $identifiers, ?\Closure $onStatus): ChatAgent
    {
        $labContractConfigProvider = new DbLabContractConfigProvider();
        $labSliceReader = new LabSliceReader($labContractConfigProvider);
        $turnaroundConfigProvider = new DbLabTurnaroundConfigProvider();

        $toolExecutor = new ToolExecutor(
            $session->pid,
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
            "chat:{$session->id}",
            $identifiers,
            // Chat is interactive and answers over already-extracted facts, so
            // it runs a deliberately small thinking budget -- speed over deep
            // reasoning. The richer budget is reserved for the narrative.
            new PromptContext(self::DOC_TYPE, self::PROMPT_VERSION, self::model(), thinkingBudget: 256),
            $onStatus,
        );

        return new ChatAgent($agentLoop, new Verifier(), $onStatus);
    }

    /**
     * @param list<ChatTurn> $priorTurns
     */
    private function persistToolTurns(int $sessionId, int $pid, int $userId, ChatAnswer $answer, string $correlationId): void
    {
        foreach ($answer->toolCallLog as $entry) {
            $toolT0 = microtime(true);
            $seq = $this->turnStore->nextSeq($sessionId);
            $this->turnStore->insert(new NewChatTurn(
                $sessionId,
                $seq,
                ChatTurnRole::Tool,
                [
                    'tool' => $entry->request->name,
                    'arguments' => $entry->request->arguments,
                    'ok' => $entry->outcome->ok,
                    'error' => $entry->outcome->errorMessage,
                    'facts' => array_map(static fn (Fact $f): array => $f->toArray(), $entry->outcome->facts),
                ],
                null,
                null,
                $correlationId,
                null,
                null,
                null,
            ));

            $this->recordSpan(
                $correlationId,
                'tool_call',
                $toolT0,
                $entry->outcome->ok ? 'ok' : 'error',
                $pid,
                $userId,
                errorClass: $entry->outcome->ok ? null : 'ToolCallFailure',
                errorDetail: $entry->outcome->errorMessage,
            );
        }
    }

    /**
     * TODO(U9 report): same TracePayloadStore adoption gap noted in
     * {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath::recordSpan()} --
     * `chat_turn`/`tool_call`/`verify` spans here do not yet populate
     * `TraceSpan::$payloadRef` (the prompt bytes, tool args/results, and
     * verifier findings a span waterfall click-through would show,
     * ARCHITECTURE.md §3.2/§3.3). Left for whichever unit next touches these
     * call sites; not taken on as a drive-by change in U9.
     */
    private function recordSpan(
        string $correlationId,
        string $kind,
        float $t0,
        string $status,
        int $pid,
        ?int $userId,
        ?string $errorClass = null,
        ?string $errorDetail = null,
        ?string $model = null,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
    ): void {
        $durationMs = (int)round((microtime(true) - $t0) * 1000);
        $startedAt = \DateTimeImmutable::createFromFormat('U.u', number_format($t0, 6, '.', ''));
        if ($startedAt === false) {
            $startedAt = new \DateTimeImmutable();
        }

        $this->tracer->record(new TraceSpan(
            $correlationId,
            TraceSpan::newSpanId(),
            null,
            $kind,
            $startedAt,
            $durationMs,
            $status,
            $pid,
            $userId,
            $errorClass,
            $errorDetail,
            $model,
            $tokensIn,
            $tokensOut,
        ));
    }

    /**
     * The ONE place a chat answer's claims are rehydrated (ARCHITECTURE.md
     * §4: identifiers are restored only after verification, on the final
     * rendered answer) -- both the persisted turn row and the JSON response
     * `chat.php` sends the browser must show the SAME rehydrated text, never
     * the still-tokenized text the model/verifier saw.
     *
     * @return list<array<string, mixed>>|null
     */
    private static function rehydratedClaimsArray(ChatAnswer $answer): ?array
    {
        if ($answer->claims === null) {
            return null;
        }

        $redactor = new Redactor();
        $map = $answer->redactionMap;

        return array_map(static function ($claim) use ($redactor, $map): array {
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
        }, $answer->claims);
    }

    private function persistAssistantTurn(ChatSession $session, ChatAnswer $answer, ChatTurnConfidence $confidence, string $correlationId): void
    {
        $content = [
            'claims' => self::rehydratedClaimsArray($answer),
            'verify_status' => $answer->verifyStatus->value,
            'degraded_reason' => $answer->degradedReason,
            'degraded_message' => $answer->degradedMessage,
            'frozen' => $answer->frozen,
            'confidence' => $confidence->score,
            'confidence_label' => $confidence->label,
        ];

        $verdictOut = array_map(static fn ($v): array => [
            'check' => $v->checkId->value,
            'passed' => $v->passed,
            'skipped' => $v->skipped,
            'findings' => $v->findings,
        ], $answer->verdicts);

        $this->turnStore->insert(new NewChatTurn(
            $session->id,
            $this->turnStore->nextSeq($session->id),
            ChatTurnRole::Assistant,
            $content,
            null,
            $verdictOut,
            $correlationId,
            $answer->usage->tokensIn,
            $answer->usage->tokensOut,
            null,
        ));
    }

    /**
     * @return list<array{role: string, text: ?string, result: ?array<string, mixed>}>
     */
    private function clientTurnHistory(int $sessionId): array
    {
        $history = [];
        foreach ($this->turnStore->forSession($sessionId) as $turn) {
            if ($turn->role === ChatTurnRole::User) {
                $text = is_string($turn->content['text'] ?? null) ? $turn->content['text'] : '';
                $history[] = ['role' => 'physician', 'text' => $text, 'result' => null];
                continue;
            }

            if ($turn->role !== ChatTurnRole::Assistant) {
                continue;
            }

            $claims = [];
            $rawClaims = $turn->content['claims'] ?? null;
            if (is_array($rawClaims)) {
                foreach ($rawClaims as $claim) {
                    if (is_array($claim) && is_string($claim['text'] ?? null)) {
                        $claims[] = ['text' => $claim['text']];
                    }
                }
            }

            $history[] = [
                'role' => 'assistant',
                'text' => null,
                'result' => [
                    'frozen' => (bool)($turn->content['frozen'] ?? false),
                    'degraded_message' => is_string($turn->content['degraded_message'] ?? null)
                        ? $turn->content['degraded_message']
                        : null,
                    'claims' => $claims,
                    'tool_calls' => [],
                ],
            ];
        }

        return $history;
    }

    /**
     * @param list<ChatTurn> $turns
     * @return list<string>
     */
    private static function renderTranscript(array $turns): array
    {
        $lines = [];
        foreach ($turns as $turn) {
            if ($turn->role === ChatTurnRole::User) {
                $text = is_string($turn->content['text'] ?? null) ? $turn->content['text'] : '';
                $lines[] = "Physician: {$text}";
            } elseif ($turn->role === ChatTurnRole::Assistant) {
                $claims = $turn->content['claims'] ?? null;
                if (is_array($claims)) {
                    $texts = [];
                    foreach ($claims as $claim) {
                        if (is_array($claim) && is_string($claim['text'] ?? null)) {
                            $texts[] = $claim['text'];
                        }
                    }
                    $lines[] = 'Assistant: ' . implode(' ', $texts);
                } elseif (is_string($turn->content['degraded_message'] ?? null)) {
                    $lines[] = 'Assistant: ' . $turn->content['degraded_message'];
                }
            }
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function turnResponse(ChatSession $session, ChatAnswer $answer, ChatTurnConfidence $confidence, string $correlationId, bool $stale): array
    {
        return [
            'ok' => true,
            'session_id' => $session->id,
            'correlation_id' => $correlationId,
            'stale' => $stale,
            'frozen' => $answer->frozen,
            'verify_status' => $answer->verifyStatus->value,
            'confidence' => $confidence->score,
            'confidence_label' => $confidence->label,
            'degraded_message' => $answer->degradedMessage,
            'claims' => self::rehydratedClaimsArray($answer),
            'verdicts' => array_map(static fn ($v): array => [
                'check' => $v->checkId->value,
                'passed' => $v->passed,
                'skipped' => $v->skipped,
                'findings' => $v->findings,
            ], $answer->verdicts),
            'tool_calls' => array_map(static fn ($entry): array => [
                'tool' => $entry->request->name,
                'ok' => $entry->outcome->ok,
                'error' => $entry->outcome->errorMessage,
            ], $answer->toolCallLog),
            'facts' => array_map(static fn (Fact $f): array => $f->toArray(), $answer->accumulatedFacts),
        ];
    }

    /**
     * @return array{ok: bool, http_status: int, reason: string}
     */
    private function errorResponse(int $httpStatus, string $reason): array
    {
        return ['ok' => false, 'http_status' => $httpStatus, 'reason' => $reason];
    }

    private function auditTurn(int $pid, ChatAnswer $answer, ChatTurnConfidence $confidence, string $correlationId): void
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $authUser = (string)($session->get('authUser') ?? '');
        $authProvider = (string)($session->get('authProvider') ?? '');

        // Audit-trail metadata only -- correlation id, outcome, and confidence,
        // never the message/answer text (those live on the turn row and in the
        // observability log). Keeps the HIPAA audit event PHI-free.
        $summary = sprintf(
            'Clinical Co-Pilot chat turn, correlation_id=%s, verify=%s, confidence=%s (%.2f)',
            $correlationId,
            $answer->verifyStatus->value,
            $confidence->label,
            $confidence->score,
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

    /**
     * Heightened-visibility log of one chat interaction (ARCHITECTURE.md §3
     * observability): the provider's question, the assistant's answer, the
     * deterministic confidence, and the turn's cost/latency/verification
     * metadata -- one structured PSR-3 record per turn for oversight and
     * dashboards, keyed by correlation id back to the turn ledger and traces.
     *
     * PHI NOTE -- ACKNOWLEDGED AND INTENTIONAL FOR THE DEMO, not an oversight:
     * `provider_message` and `assistant_answer` carry patient clinical content,
     * and logging them in full is a deliberate choice here for demo visibility
     * on synthetic-only data (OPEN-1). This is intentionally left un-hardened at
     * this stage -- no gating/scrubbing is warranted for a synthetic demo and
     * adding it now would be wasted effort. The one thing worth recording is
     * that the tradeoff is understood: before any real-PHI deployment, route
     * these two fields to a PHI-eligible sink or drop them (the confidence +
     * metadata stay useful, and the content already persists on the
     * access-controlled `mod_copilot_chat_turn` row).
     */
    private function logChatTurn(ChatSession $session, string $message, ChatAnswer $answer, ChatTurnConfidence $confidence, string $correlationId): void
    {
        $this->logger->info('Clinical Co-Pilot chat turn', [
            'correlation_id' => $correlationId,
            'session_id' => $session->id,
            'pid' => $session->pid,
            'user_id' => $session->userId,
            'verify_status' => $answer->verifyStatus->value,
            'confidence' => $confidence->score,
            'confidence_label' => $confidence->label,
            'attempts' => $answer->attempts,
            'frozen' => $answer->frozen,
            'degraded_reason' => $answer->degradedReason,
            'tool_calls' => array_map(static fn ($entry): string => $entry->request->name, $answer->toolCallLog),
            'tokens_in' => $answer->usage->tokensIn,
            'tokens_out' => $answer->usage->tokensOut,
            'latency_ms' => $answer->usage->latencyMs,
            'model' => $answer->usage->modelVersion,
            'provider_message' => $message,
            'assistant_answer' => self::answerText($answer),
        ]);
    }

    /**
     * The assistant's rendered answer as plain text: the rehydrated claim
     * texts joined, or the degraded/frozen message when there are no claims.
     */
    private static function answerText(ChatAnswer $answer): string
    {
        $claims = self::rehydratedClaimsArray($answer);
        if (is_array($claims) && $claims !== []) {
            $texts = array_map(
                static fn ($claim): string => is_string($claim['text'] ?? null) ? $claim['text'] : '',
                $claims,
            );

            return trim(implode(' ', array_filter($texts, static fn (string $t): bool => $t !== '')));
        }

        return $answer->degradedMessage ?? '';
    }
}
