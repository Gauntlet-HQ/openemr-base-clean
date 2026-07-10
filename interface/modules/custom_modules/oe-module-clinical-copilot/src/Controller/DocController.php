<?php

/**
 * Orchestrates one doc-page request: read path + history + audit logging.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Controller;

use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use OpenEMR\Modules\ClinicalCopilot\Doc\DocRow;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\TracePoller;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\DocHistoryReader;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\DocViewModel;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\PatientIdentifierLookup;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\ScheduledPatientListReader;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\ScheduledPatientRow;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisDocPayload;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadResult;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;
use Ramsey\Uuid\Uuid;

/**
 * `public/doc.php` is deliberately thin (bootstrap -> CSRF -> ACL -> session
 * identity, per build-notes.md's page-bootstrap contract) and delegates
 * everything else here. This class knows nothing about HTTP superglobals or
 * Twig -- it takes a validated `pid`/`userId` and hands back plain data for
 * the caller to render, so it stays unit-testable independent of the web
 * layer.
 *
 * Every call here that reaches {@see SynthesisReadPath} is a chart-data
 * view and MUST be audit-logged (ARCHITECTURE.md §4/§1.3): both
 * {@see self::view()} and {@see self::regenerate()} call
 * {@see self::auditView()} unconditionally, including on a cache hit --
 * "cache hits ... included" is explicit in I12, and a chart-data VIEW audit
 * entry is owed regardless of whether the LLM was ever called.
 */
final class DocController
{
    public function __construct(
        private readonly SynthesisReadPath $readPath,
        private readonly PatientIdentifierLookup $identifierLookup,
        private readonly DocHistoryReader $historyReader,
        private readonly ScheduledPatientListReader $scheduledPatientListReader,
        private readonly DocStore $docStore,
        private readonly TracePoller $tracePoller,
    ) {
    }

    public static function createDefault(): self
    {
        return new self(
            SynthesisReadPath::createDefault(),
            new PatientIdentifierLookup(),
            new DocHistoryReader(),
            new ScheduledPatientListReader(),
            new DocStore(),
            new TracePoller(),
        );
    }

    /**
     * @return list<ScheduledPatientRow>
     */
    public function scheduledPatientsToday(): array
    {
        return $this->scheduledPatientListReader->today();
    }

    public function todayAppointmentForPatient(int $pid): ?ScheduledPatientRow
    {
        return $this->scheduledPatientListReader->forPidToday($pid);
    }

    /**
     * @return array{found: bool, result: ?SynthesisReadResult, history: list<DocRow>, patient: ?PatientIdentifiers}
     */
    public function view(int $pid, int $userId): array
    {
        return $this->handle($pid, $userId, regenerate: false);
    }

    /**
     * T22 manual Regenerate (POST, CSRF-checked by the caller BEFORE this is
     * invoked -- see `public/doc.php`).
     *
     * @param (\Closure(string): void)|null $onStatus optional staged-status callback for SSE
     *
     * @return array{found: bool, result: ?SynthesisReadResult, history: list<DocRow>, patient: ?PatientIdentifiers}
     */
    public function regenerate(int $pid, int $userId, ?\Closure $onStatus = null): array
    {
        $correlationId = Uuid::uuid7()->toString();
        if ($onStatus !== null) {
            $onStatus("correlation_id:{$correlationId}");
        }

        return $this->handle($pid, $userId, regenerate: true, onStatus: $onStatus, correlationId: $correlationId);
    }

    /**
     * Polling fallback for synthesis regenerate progress (ARCHITECTURE.md §1.3).
     *
     * @return array<string, mixed>
     */
    public function pollRegenerateStatus(string $correlationId, int $userId, string $webRoot): array
    {
        $spans = $this->tracePoller->forCorrelationId($correlationId);
        $done = false;
        foreach ($spans as $span) {
            if ($span['kind'] === 'render') {
                $done = true;
                break;
            }
        }

        if (!$done) {
            return ['ok' => true, 'done' => false, 'spans' => $spans, 'result' => null];
        }

        $docRow = $this->docStore->findByCorrelationId($correlationId);
        if ($docRow === null) {
            return ['ok' => true, 'done' => false, 'spans' => $spans, 'result' => null];
        }

        if (!$this->identifierLookup->exists($docRow->pid)) {
            return $this->errorResponse(403, 'not authorized for this correlation id');
        }

        if (!$this->isAuthorizedForCorrelation($correlationId, $userId, $docRow->pid)) {
            return $this->errorResponse(403, 'not authorized for this correlation id');
        }

        $result = $this->synthesisResultFromDocRow($correlationId, $docRow);

        return [
            'ok' => true,
            'done' => true,
            'spans' => $spans,
            'result' => $this->formatRegenerateJson($result, $webRoot),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatRegenerateJson(SynthesisReadResult $result, string $webRoot): array
    {
        $doc = DocViewModel::summary($result);
        $viewModel = DocViewModel::build($result, $webRoot);
        $narrative = is_array($viewModel['narrative'] ?? null) ? $viewModel['narrative'] : [];
        $verifyStatus = $result->verifyStatus?->value ?? '';
        $needsGeneration = !$result->capabilityCrash
            && LlmRuntimeConfig::llmConfigured()
            && ($verifyStatus === VerifyStatus::Degraded->value || $narrative === []);

        return [
            'ok' => true,
            'doc' => $doc,
            'view_model' => [
                'narrative' => $narrative,
            ],
            'needs_narrative_generation' => $needsGeneration,
        ];
    }

    /**
     * @param (\Closure(string): void)|null $onStatus
     *
     * @return array{found: bool, result: ?SynthesisReadResult, history: list<DocRow>, patient: ?PatientIdentifiers}
     */
    private function handle(
        int $pid,
        int $userId,
        bool $regenerate,
        ?\Closure $onStatus = null,
        ?string $correlationId = null,
    ): array {
        if (!$this->identifierLookup->exists($pid)) {
            return ['found' => false, 'result' => null, 'history' => [], 'patient' => null];
        }

        $result = $regenerate
            ? $this->readPath->regenerate($pid, $userId, correlationId: $correlationId, onStatus: $onStatus)
            // View (page load) generates the brief on a cache miss. Now that the
            // narrative is a short 3-5 claim brief on a reduced thinking budget,
            // this is fast and cheap enough to run on load (it was briefly
            // deferred only because the old long narrative blocked the page for
            // ~90s). The Generate/Regenerate button still forces a fresh attempt.
            : $this->readPath->read($pid, $userId);

        $this->auditView($pid, $result->correlationId, $regenerate);

        return [
            'found' => true,
            'result' => $result,
            'history' => $this->historyReader->forPid($pid),
            'patient' => $this->identifierLookup->forPid($pid),
        ];
    }

    /**
     * ARCHITECTURE.md §4: "every read [is] audit-logged via EventAuditLogger."
     * PHI stays out of the comment string -- only the correlation id and a
     * fixed action label, never a name/MRN/value (§4: "never in log lines").
     */
    private function auditView(int $pid, string $correlationId, bool $regenerate): void
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $authUser = (string)($session->get('authUser') ?? '');
        $authProvider = (string)($session->get('authProvider') ?? '');
        $action = $regenerate ? 'regenerate' : 'view';

        EventAuditLogger::getInstance()->newEvent(
            'patient-record',
            $authUser,
            $authProvider,
            1,
            "Clinical Co-Pilot synthesis {$action}, correlation_id={$correlationId}",
            $pid,
        );
    }

    private function synthesisResultFromDocRow(string $correlationId, DocRow $docRow): SynthesisReadResult
    {
        $payload = SynthesisDocPayload::fromDocArray($docRow->doc);

        return SynthesisReadResult::served(
            $correlationId,
            $docRow->pid,
            $payload->facts,
            $docRow->factDigest,
            $docRow->verifyStatus,
            $docRow->regenReason,
            $payload->claims,
            $payload->degradedReason,
            $payload->degradedMessage,
            $payload->verdicts,
            $payload->attempts,
            false,
            $docRow->computedAt,
            $docRow->qaStatus,
            $docRow->qaScore,
            $docRow->id,
        );
    }

    private function isAuthorizedForCorrelation(string $correlationId, int $userId, int $pid): bool
    {
        $row = \OpenEMR\Common\Database\QueryUtils::querySingleRow(
            'SELECT `user_id` FROM `mod_copilot_trace` WHERE `correlation_id` = ? AND `pid` = ? ORDER BY `id` ASC LIMIT 1',
            [$correlationId, $pid],
        );
        if (!is_array($row)) {
            return true;
        }

        $traceUserId = $row['user_id'] ?? null;

        return $traceUserId === null || (int)$traceUserId === $userId;
    }

    /**
     * @return array{ok: false, reason: string, http_status: int}
     */
    private function errorResponse(int $httpStatus, string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'http_status' => $httpStatus];
    }
}
