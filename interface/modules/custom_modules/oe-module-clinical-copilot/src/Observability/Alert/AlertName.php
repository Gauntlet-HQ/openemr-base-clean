<?php

/**
 * The closed set of alerts AlertEvaluator checks (ARCHITECTURE.md §3.5 + I14 + the Week-2 spec-named three + the ingestion-latency SLO).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Alert;

/**
 * The seven alerts in ARCHITECTURE.md §3.5's table, plus the I14 unaccounted-
 * entity alert (docs/build-notes.md "I14"), plus the three Week-2 spec-named
 * alerts (extraction failure rate, RAG retrieval latency, eval regression),
 * plus the ingestion-latency SLO alert (the ops/cost-analysis.md upload→draft
 * p95 < 8 s target). One enum case per alert, spec order first, so the
 * dashboard and the evaluator's own findings list read the same way the spec
 * documents them.
 */
enum AlertName: string
{
    case WrongPatientTrip = 'wrong_patient_v3_trip';
    case P95Latency = 'p95_latency';
    case ErrorRate = 'error_rate';
    case ToolFailureRate = 'tool_failure_rate';
    case VerificationFailureRate = 'verification_failure_rate';
    case LlmSpend = 'llm_spend';
    case WorkerHeartbeatStale = 'worker_heartbeat_stale';
    case UnaccountedEntity = 'unaccounted_entity';
    case ExtractionFailureRate = 'extraction_failure_rate';
    case RagRetrievalLatency = 'rag_retrieval_latency';
    case IngestionLatency = 'ingestion_latency';
    case EvalRegression = 'eval_regression';

    /**
     * ARCHITECTURE.md §3.5's "Means" column, condensed -- shown on the
     * dashboard banner so the on-call reader does not need this source file
     * open to know what a firing alert implies.
     */
    public function meaning(): string
    {
        return match ($this) {
            self::WrongPatientTrip => 'patient pinning failed upstream of the LLM -- sev-1',
            self::P95Latency => 'physician-visible slowness; worker not keeping up',
            self::ErrorRate => 'systemic failure (DB, LLM, schema drift)',
            self::ToolFailureRate => 'a capability breaking on real data shapes',
            self::VerificationFailureRate => 'model or prompt regression (drift, provider-side change)',
            self::LlmSpend => 'runaway loop, warm storm, or hostile-but-authenticated user',
            self::WorkerHeartbeatStale => 'cron missing or dead -- warm sweep and alert evaluation are down',
            self::UnaccountedEntity => 'a data-shape surprise: extraction silently dropped a source row before it was ever classified (I14)',
            self::ExtractionFailureRate => 'document ingestion failing on real uploads -- VLM outage, extraction-schema drift, or a new document shape',
            self::RagRetrievalLatency => 'guideline-evidence retrieval is slow -- knowledge Postgres degrading, missing index, or corpus scan overload',
            self::IngestionLatency => 'document ingestion (upload -> draft) is breaching its p95 SLO (ops/cost-analysis.md: p95 < 8 s) -- slow vision provider, oversized scans, or a saturated document-store/DB tier',
            self::EvalRegression => 'the last eval-gate run recorded a rubric regression (>5% drop vs baseline, or below the 0.90 floor) -- model/prompt/code drift reached the golden set',
        };
    }

    /**
     * ARCHITECTURE.md §3.5's "On-call response" column, condensed.
     */
    public function onCallResponse(): string
    {
        return match ($this) {
            self::WrongPatientTrip => 'freeze module (feature flag), preserve session + trace, diff tool-executor pid injection vs. citations before re-enable',
            self::P95Latency => 'check trace step breakdown: LLM latency vs. extraction vs. queue; verify worker heartbeat; scale/interval-tune worker',
            self::ErrorRate => "check error_class distribution in traces; if LLM-side, confirm degradation is engaging",
            self::ToolFailureRate => 'pull failing spans\' payloads; usually a data-quality edge -- add the case to fixtures before fixing',
            self::VerificationFailureRate => 'compare per-check failure mix vs. baseline; pin/roll back model version; replay evals',
            self::LlmSpend => 'hard cap trips the circuit breaker automatically; rank correlation IDs by cost_usd in traces to find the burner before reset',
            self::WorkerHeartbeatStale => 'verify the cron entry (hard deployment requirement); check /copilot/ready',
            self::UnaccountedEntity => 'pull the span payload, add the case to fixtures BEFORE fixing the mapping',
            self::ExtractionFailureRate => 'pull failing vision_extract spans (error_class/error_detail); if provider-side, confirm the manual-entry fallback is serving uploads; if a new document shape, add it to fixtures before fixing the extractor',
            self::RagRetrievalLatency => 'check the knowledge row on /ready and the dashboard; if Postgres is slow, check indexes/connection health on the knowledge DB -- if it goes fully unreachable the module degrades to the offline corpus on its own',
            self::IngestionLatency => 'open a slow ingest/preview span\'s waterfall and compare its vision_extract child: if the vision call dominates, check provider status and consider the flash extraction model (ops/cost-analysis.md lever); otherwise profile document storage and draft persistence',
            self::EvalRegression => 'treat as a deploy blocker: re-run ops/eval/run-evals.php, diff the failing-case list against baseline.json, pin/roll back the model or prompt version; only update the baseline deliberately (--update-baseline)',
        };
    }
}
