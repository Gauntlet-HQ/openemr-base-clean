--
-- Clinical Co-Pilot Module
-- Table definitions and initial config seed.
--
-- @package   OpenEMR\Modules\ClinicalCopilot
-- @link      https://www.open-emr.org
-- @author    Clinical Co-Pilot Team
-- @copyright Copyright (c) 2026 OpenEMR Foundation
-- @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--
-- All existing OpenEMR tables are read-only to this module (I9 additivity).
-- Only the five mod_copilot_* tables below are module-owned/writable, plus
-- one background_services row registered here and managed (activated /
-- deactivated) by ModuleManagerListener on enable/disable.
--

-- ============================================================================
-- mod_copilot_doc — narratives are content-addressed by (pid, fact_digest).
-- Facts are never cached (I2); this table caches narratives only. Append-only
-- in code (no UPDATE/DELETE path is ever implemented against it — I3/E7).
--
-- T22 (Warm timing + QA-driven rerun, docs/build-notes.md): relaxed to carry
-- best-of-N candidate narratives per (pid, fact_digest) instead of exactly
-- one. DROPPED the UNIQUE(pid, fact_digest) key in favor of a non-unique
-- (pid, fact_digest, id) index; added qa_status/qa_score/regen_reason/
-- verify_status so DocStore::findBest() (U6) can serve the current best =
-- most recent row with verify_status='passed', preferring higher qa_score,
-- falling back to the latest 'degraded' row when none passed (I6). Still
-- append-only (E7): new attempts are new rows, nothing here is ever mutated.
-- ============================================================================
#IfNotTable mod_copilot_doc
CREATE TABLE IF NOT EXISTS `mod_copilot_doc` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `pid` BIGINT(20) NOT NULL COMMENT 'references patient_data.pid (read-only reference, no FK against core)',
    `fact_digest` VARCHAR(64) NOT NULL COMMENT 'hash(facts || capability versions || cadence/config version || code-set version || doc_type || reduce prompt+schema version), I1',
    `doc_type` VARCHAR(64) NOT NULL DEFAULT 'endo-previsit-v1' COMMENT 'digest input; column exists for querying by doc type',
    `appt_id` BIGINT(20) DEFAULT NULL COMMENT 'metadata only, references openemr_postcalendar_events.pc_eid; NOT part of any key',
    `doc` LONGTEXT NOT NULL COMMENT 'JSON: facts + citations + narrative',
    `capability_versions` LONGTEXT NOT NULL COMMENT 'JSON map of capability => capability_version, a digest input',
    `prompt_version` VARCHAR(64) NOT NULL COMMENT 'reduce prompt+schema version, a digest input',
    `computed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'display only; staleness is cache addressing via fact_digest, never a timestamp check (I4)',
    `correlation_id` VARCHAR(64) NOT NULL COMMENT 'I12: every invocation leaves a trace',
    `llm_latency_ms` INT(11) DEFAULT NULL,
    `tokens_in` INT(11) DEFAULT NULL,
    `tokens_out` INT(11) DEFAULT NULL,
    `cost_usd` DECIMAL(10,6) DEFAULT NULL,
    `excluded_counts` LONGTEXT DEFAULT NULL COMMENT 'JSON, per-analyte exclusion counts incl. unitless-exclusion rate (I5)',
    `qa_status` ENUM('pending','ok','low','unavailable') NOT NULL DEFAULT 'pending' COMMENT 'T22/U12: post-mortem async QA verdict for this attempt; advisory only, never a serving gate',
    `qa_score` DECIMAL(4,3) DEFAULT NULL COMMENT 'T22/U12: advisory QA score in [0,1]; drives best-of-N preference in DocStore::findBest(), never a gate by itself',
    `regen_reason` ENUM('none','qa_low','manual','verify_retry') NOT NULL DEFAULT 'none' COMMENT 'T22: why this attempt exists; none for the first attempt at a given digest',
    `verify_status` ENUM('passed','degraded') NOT NULL DEFAULT 'passed' COMMENT 'T22/I11: this attempt''s deterministic V1-V6 verdict; degraded = facts-only, no narrative content may be trusted/served as prose',
    PRIMARY KEY (`id`),
    KEY `idx_pid_fact_digest_id` (`pid`, `fact_digest`, `id`) COMMENT 'T22: relaxed from UNIQUE(pid, fact_digest) so best-of-N candidate narratives can coexist per digest',
    KEY `idx_pid_computed_at` (`pid`, `computed_at`)
) ENGINE=InnoDB COMMENT='Clinical Co-Pilot: content-addressed pre-visit synthesis documents (append-only, best-of-N per digest, T22)';
#EndIf

-- ============================================================================
-- mod_copilot_cadence — versioned config: monitoring cadences, canonical-unit
-- conversion whitelist, lab-turnaround (expected_result_date), rate-limit /
-- circuit-breaker values (§3.7), synthesis auto-retry count. This is the one
-- module table where UPDATE is permitted in code — config only (I3 exempts
-- config rows, not the ledgers).
-- ============================================================================
#IfNotTable mod_copilot_cadence
CREATE TABLE IF NOT EXISTS `mod_copilot_cadence` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `code_set` VARCHAR(64) NOT NULL COMMENT 'config key, e.g. cadence:acr, cadence:a1c, cadence:lipids, unit_conversion, lab_turnaround, rate_limit_breaker, synthesis_retry',
    `interval` VARCHAR(32) DEFAULT NULL COMMENT 'human-scale interval for cadence rows, e.g. "P1Y", "P3M"; NULL for non-cadence config rows',
    `config_json` LONGTEXT DEFAULT NULL COMMENT 'JSON: structured config payload (thresholds, conversion factors, breaker limits, retry counts, etc.)',
    `version` VARCHAR(32) NOT NULL COMMENT 'digest input; bump (never in-place semantic change without one, E5)',
    `updated_at` DATETIME DEFAULT NULL COMMENT 'nullable; module-owned config, UPDATE allowed here only',
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_code_set` (`code_set`)
) ENGINE=InnoDB COMMENT='Clinical Co-Pilot: versioned cadence/unit/turnaround/rate-limit config';
#EndIf

-- ============================================================================
-- mod_copilot_chat_session — one session per pid-pinned chat (I10). Frozen on
-- a verifier V3 sev-1 trip and preserved as evidence, never resumed.
-- ============================================================================
#IfNotTable mod_copilot_chat_session
CREATE TABLE IF NOT EXISTS `mod_copilot_chat_session` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `pid` BIGINT(20) NOT NULL COMMENT 'references patient_data.pid; structurally pinned for the life of the session (I10)',
    `user_id` BIGINT(20) NOT NULL COMMENT 'references users.id, the authed clinician',
    `doc_id` BIGINT(20) DEFAULT NULL COMMENT 'references mod_copilot_doc.id this session was preloaded from',
    `fact_digest` VARCHAR(64) NOT NULL COMMENT 'fact_digest at session preload time, for mid-conversation drift banners (T19)',
    `status` VARCHAR(16) NOT NULL DEFAULT 'active' COMMENT 'active | frozen | expired (auto-closed after idle timeout)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pid` (`pid`),
    KEY `idx_doc_id` (`doc_id`),
    KEY `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB COMMENT='Clinical Co-Pilot: pid-pinned chat sessions';
#EndIf

-- Upgrade for installs predating idle-session auto-expiry: the (user_id, status)
-- index backs both the per-turn active-session count and the idle-expiry sweep.
#IfNotIndex mod_copilot_chat_session idx_user_status
ALTER TABLE `mod_copilot_chat_session` ADD INDEX `idx_user_status` (`user_id`, `status`);
#EndIf

-- ============================================================================
-- mod_copilot_chat_turn — append-only ledger, same philosophy as T7.
-- ============================================================================
#IfNotTable mod_copilot_chat_turn
CREATE TABLE IF NOT EXISTS `mod_copilot_chat_turn` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `session_id` BIGINT(20) NOT NULL COMMENT 'references mod_copilot_chat_session.id',
    `seq` INT(11) NOT NULL COMMENT 'turn sequence number within the session',
    `role` VARCHAR(16) NOT NULL COMMENT 'user | assistant | tool',
    `content` LONGTEXT NOT NULL COMMENT 'JSON turn content',
    `tool_calls` LONGTEXT DEFAULT NULL COMMENT 'JSON: tool calls issued this turn, if any',
    `verification_verdict` LONGTEXT DEFAULT NULL COMMENT 'JSON, per-check V1-V6 verdicts',
    `correlation_id` VARCHAR(64) NOT NULL,
    `tokens_in` INT(11) DEFAULT NULL,
    `tokens_out` INT(11) DEFAULT NULL,
    `cost_usd` DECIMAL(10,6) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_session_id` (`session_id`)
) ENGINE=InnoDB COMMENT='Clinical Co-Pilot: append-only chat turn ledger';
#EndIf

-- ============================================================================
-- mod_copilot_trace — append-only observability source of truth (I12). PHI
-- lives here deliberately (the chart's own MySQL protection domain), never in
-- third-party observability SaaS (T16).
-- ============================================================================
#IfNotTable mod_copilot_trace
CREATE TABLE IF NOT EXISTS `mod_copilot_trace` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `correlation_id` VARCHAR(64) NOT NULL,
    `span_id` VARCHAR(64) NOT NULL,
    `parent_span_id` VARCHAR(64) DEFAULT NULL,
    `kind` VARCHAR(32) NOT NULL COMMENT 'extract | digest | cache_lookup | llm_reduce | chat_turn | tool_call | verify | render | warm | alert_eval',
    `started_at` DATETIME(6) NOT NULL,
    `duration_ms` INT(11) DEFAULT NULL,
    `status` VARCHAR(16) NOT NULL COMMENT 'ok | error | retried | degraded',
    `error_class` VARCHAR(128) DEFAULT NULL,
    `error_detail` TEXT DEFAULT NULL,
    `model` VARCHAR(64) DEFAULT NULL,
    `tokens_in` INT(11) DEFAULT NULL,
    `tokens_out` INT(11) DEFAULT NULL,
    `cost_usd` DECIMAL(10,6) DEFAULT NULL,
    `pid` BIGINT(20) DEFAULT NULL,
    `user_id` BIGINT(20) DEFAULT NULL,
    `payload_ref` VARCHAR(255) DEFAULT NULL COMMENT 'pointer to full request/response payload if persisted out-of-row',
    PRIMARY KEY (`id`),
    KEY `idx_correlation_id` (`correlation_id`)
) ENGINE=InnoDB COMMENT='Clinical Co-Pilot: append-only trace/span ledger (observability source of truth)';
#EndIf

-- ============================================================================
-- mod_copilot_qa — U12 post-mortem QA accuracy agent verdicts (append-only,
-- advisory only, T22 / docs/build-notes.md "U12 additions"). A dedicated
-- Gemini Flash instance re-reads each served doc/chat_turn against that row's
-- OWN stored fact set, post-hoc, decoupled from the serving path (zero
-- latency on the request). One row per target, idempotent on
-- (target_type, target_id) — the sweep skips anything already scored.
-- density_ratio/fact_utilization_rate are pure trace math (no LLM); concurs/
-- salience_ok/flags come from the Flash verdict. NEVER a serving gate (T15):
-- the deterministic V1-V6 verifier remains the only gate.
-- ============================================================================
#IfNotTable mod_copilot_qa
CREATE TABLE IF NOT EXISTS `mod_copilot_qa` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `target_type` ENUM('doc','chat_turn') NOT NULL COMMENT 'which ledger this verdict scores: mod_copilot_doc or mod_copilot_chat_turn',
    `target_id` BIGINT(20) NOT NULL COMMENT 'references mod_copilot_doc.id or mod_copilot_chat_turn.id per target_type',
    `correlation_id` VARCHAR(64) NOT NULL COMMENT 'ties this verdict back to the original request trace (I12)',
    `pid` BIGINT(20) NOT NULL,
    `user_id` BIGINT(20) DEFAULT NULL,
    `model` VARCHAR(64) DEFAULT NULL COMMENT 'e.g. gemini-2.5-flash; NULL when status=unavailable (no call was ever made)',
    `concurs` TINYINT(1) DEFAULT NULL COMMENT 'Flash reviewer verdict: does it concur with the rendered response overall',
    `salience_ok` TINYINT(1) DEFAULT NULL COMMENT 'false = a high-priority out-of-range/critical fact was not surfaced near the top',
    `flags` LONGTEXT DEFAULT NULL COMMENT 'JSON list of {claim_ref, class: emphasis|paraphrase|omission|salience|other, note}',
    `density_ratio` DECIMAL(6,4) DEFAULT NULL COMMENT 'deterministic: unique cited clinical entities / narrative length',
    `fact_utilization_rate` DECIMAL(6,4) DEFAULT NULL COMMENT 'deterministic: fraction of extracted facts left uncited',
    `reviewer_note` TEXT DEFAULT NULL,
    `tokens_in` INT(11) DEFAULT NULL,
    `tokens_out` INT(11) DEFAULT NULL,
    `cost_usd` DECIMAL(10,6) DEFAULT NULL,
    `status` ENUM('ok','unavailable','error') NOT NULL COMMENT 'ok = Flash verdict recorded; unavailable = no ADC/LLM, deterministic metrics only; error = sweep attempt failed',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_target` (`target_type`, `target_id`) COMMENT 'idempotent sweep: one verdict per target, ever',
    KEY `idx_correlation_id` (`correlation_id`),
    KEY `idx_pid` (`pid`)
) ENGINE=InnoDB COMMENT='Clinical Co-Pilot: append-only advisory post-mortem QA verdicts (U12, T22), never a serving gate';
#EndIf

-- ============================================================================
-- mod_copilot_trace_payload — out-of-row storage for full request/response
-- payloads (prompts, tool args/results, verifier findings) that a trace span
-- points at via `mod_copilot_trace.payload_ref` (ARCHITECTURE.md §3.2). PHI
-- lives here deliberately, in the same MySQL protection domain as the chart
-- (T16) — never a third-party observability SaaS. Append-only; ACL-gated
-- read access happens at the dashboard layer, not here.
-- ============================================================================
#IfNotTable mod_copilot_trace_payload
CREATE TABLE IF NOT EXISTS `mod_copilot_trace_payload` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `payload_ref` VARCHAR(64) NOT NULL COMMENT 'the token mod_copilot_trace.payload_ref carries',
    `correlation_id` VARCHAR(64) NOT NULL,
    `kind` VARCHAR(32) NOT NULL COMMENT 'prompt | tool_args | tool_result | verifier_findings | qa_review',
    `payload_json` LONGTEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_payload_ref` (`payload_ref`),
    KEY `idx_correlation_id` (`correlation_id`)
) ENGINE=InnoDB COMMENT='Clinical Co-Pilot: append-only out-of-row trace payload storage (PHI stays in module MySQL, T16)';
#EndIf

-- ============================================================================
-- mod_copilot_ui_event — minimal append-only ledger backing the two
-- over-reliance dashboard indicators (ARCHITECTURE.md §2.5/§3.3): citation
-- click-through rate and facts-panel opens. Written by the tiny client ping
-- endpoint (`public/event.php`) only — never PHI-bearing, just a correlation
-- id + event type.
-- ============================================================================
#IfNotTable mod_copilot_ui_event
CREATE TABLE IF NOT EXISTS `mod_copilot_ui_event` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `correlation_id` VARCHAR(64) NOT NULL,
    `pid` BIGINT(20) DEFAULT NULL,
    `user_id` BIGINT(20) DEFAULT NULL,
    `event_type` VARCHAR(32) NOT NULL COMMENT 'citation_click | facts_panel_open',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_correlation_id` (`correlation_id`),
    KEY `idx_event_type` (`event_type`)
) ENGINE=InnoDB COMMENT='Clinical Co-Pilot: append-only UI engagement pings (over-reliance indicators, §2.5)';
#EndIf

-- ============================================================================
-- background_services row — the warm worker (I7: warmer only, failure
-- degrades latency, never correctness). Inserted inactive; ModuleManagerListener
-- activates it on enable() and deactivates on disable(), so the tick only ever
-- runs while the module is actively enabled. A cron entry hitting
-- library/ajax/execute_background_services.php every 5 minutes is a hard
-- deployment requirement documented in build-notes.md — never added to core.
-- ============================================================================
#IfNotRow background_services name clinical_copilot_worker
INSERT INTO `background_services` (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`) VALUES
    ('clinical_copilot_worker', 'Clinical Co-Pilot Warm Worker', 0, 0, CURRENT_TIMESTAMP(), 5, 'clinicalCopilotWorkerRun', '/interface/modules/custom_modules/oe-module-clinical-copilot/src/worker_entry.php', 100);
#EndIf

-- ============================================================================
-- Initial mod_copilot_cadence seed rows (versioned config, v1).
-- ============================================================================
#IfNotRow mod_copilot_cadence code_set cadence:acr
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('cadence:acr', 'P1Y', '{"analyte":"acr","loinc":["14957-5"],"description":"Urine albumin/creatinine ratio - annual"}', 'v1', CURRENT_TIMESTAMP());
#EndIf

#IfNotRow mod_copilot_cadence code_set cadence:a1c
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('cadence:a1c', 'P3M', '{"analyte":"a1c","loinc":["4548-4"],"description":"Hemoglobin A1c - quarterly"}', 'v1', CURRENT_TIMESTAMP());
#EndIf

#IfNotRow mod_copilot_cadence code_set cadence:lipids
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('cadence:lipids', 'P1Y', '{"analyte":"lipids","loinc":["2093-3","18262-6","2085-9","2571-8"],"description":"Lipid panel - annual"}', 'v1', CURRENT_TIMESTAMP());
#EndIf

#IfNotRow mod_copilot_cadence code_set unit_conversion
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('unit_conversion', NULL, '{"a1c":{"canonical":"%","from":{"mmol/mol":{"formula":"ngsp_percent = (ifcc_mmol_mol / 10.929) + 2.15"}}},"glucose":{"canonical":"mg/dL","from":{"mmol/L":{"multiplier":18.018}}},"cholesterol":{"canonical":"mg/dL","from":{"mmol/L":{"multiplier":38.67}}},"triglycerides":{"canonical":"mg/dL","from":{"mmol/L":{"multiplier":88.57}}}}', 'v1', CURRENT_TIMESTAMP());
#EndIf

#IfNotRow mod_copilot_cadence code_set lab_turnaround
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('lab_turnaround', NULL, '{"default_days":3,"per_analyte_days":{"a1c":2,"acr":3,"lipids":2}}', 'v1', CURRENT_TIMESTAMP());
#EndIf

#IfNotRow mod_copilot_cadence code_set rate_limit_breaker
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('rate_limit_breaker', NULL, '{"max_requests_per_minute":30,"breaker_error_threshold":5,"breaker_window_seconds":60,"breaker_cooldown_seconds":120,"max_active_sessions_per_user":3,"max_turns_per_user_per_hour":60,"daily_llm_spend_cap_usd":50.0,"hourly_llm_burn_cap_usd":10.0,"per_tick_worker_llm_budget_usd":2.0}', 'v2', CURRENT_TIMESTAMP());
#EndIf

-- U12 (ARCHITECTURE.md §3.7): the ONE mutable slice of breaker state -- a
-- manual force-open/manual-reset flag, ACL-gated + audit-logged at the point
-- of use (the dashboard action, not here). Automatic trip/reset is computed
-- fresh from mod_copilot_trace on every isOpen() call (spend/error-rate
-- windows naturally roll off), so it needs no persisted state of its own;
-- only the manual override needs a durable flag.
#IfNotRow mod_copilot_cadence code_set rate_limit_breaker_state
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('rate_limit_breaker_state', NULL, '{"forced_open":false,"forced_at":null,"forced_by":null,"forced_reason":null}', 'v1', CURRENT_TIMESTAMP());
#EndIf

-- T22: threshold config for the QA-driven rerun decision -- which Flash
-- verdict fields must hold for a doc to count as QA "ok" rather than "low".
-- Versioned so a future tuning pass (e.g. relaxing salience_required) is a
-- version bump, never an in-place semantic change (E5).
#IfNotRow mod_copilot_cadence code_set qa_threshold
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('qa_threshold', NULL, '{"concurrence_required":true,"salience_required":true,"low_score_below":0.600}', 'v1', CURRENT_TIMESTAMP());
#EndIf

-- U12/U9: the worker heartbeat "dead-man switch" row (ARCHITECTURE.md §3.5:
-- "this alert cannot ride the worker that died: it surfaces via /copilot/ready
-- and the dashboard"). Written by WorkerTick::recordHeartbeat() on every tick;
-- read (never written) by ReadyCheck and the dashboard/alert evaluator to
-- detect staleness. Module-owned config row, UPDATE permitted here only (I3).
#IfNotRow mod_copilot_cadence code_set worker_heartbeat
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('worker_heartbeat', NULL, '{"last_tick_at":null,"tick_count":0}', 'v1', NULL);
#EndIf

-- U12 (ARCHITECTURE.md §3.5): the seven alert thresholds, versioned config so
-- tuning them is a version bump, never silent drift. `heartbeat_stale_multiplier`
-- is multiplied by the background_services execute_interval (minutes) to get
-- the staleness window.
#IfNotRow mod_copilot_cadence code_set alert_thresholds
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('alert_thresholds', NULL, '{"p95_latency_ms":15000,"warm_miss_rate_pct":20.0,"error_rate_pct":5.0,"tool_failure_rate_pct":2.0,"verification_failure_rate_pct":10.0,"spend_burn_multiplier":2.0,"heartbeat_stale_multiplier":2.0,"eval_window_minutes":15}', 'v1', CURRENT_TIMESTAMP());
#EndIf

#IfNotRow mod_copilot_cadence code_set synthesis_retry
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('synthesis_retry', NULL, '{"auto_retry_count":3}', 'v1', CURRENT_TIMESTAMP());
#EndIf

-- ============================================================================
-- U5 ControlProxy out-of-range thresholds (lab contract C3 proof (a): parsed
-- numeric vs. a versioned threshold). Read by DbLabContractConfigProvider
-- (U4) into LabContractConfig::thresholdByAnalyte, keyed by the SAME
-- unit-conversion analyte buckets as the `unit_conversion` row above (a1c,
-- glucose, cholesterol, triglycerides) — NOT the coarser `cadence:lipids`
-- monitoring bucket, for the identical reason DbLabContractConfigProvider's
-- own docblock already documents for unit conversion: `cadence:lipids`
-- bundles four LOINC codes (total cholesterol, LDL, HDL, triglycerides)
-- under one monitoring interval, but total cholesterol/LDL/HDL share one
-- conversion-and-threshold bucket ("cholesterol") while triglycerides use a
-- separate one. A "lipids" threshold row would never be looked up by
-- LabRowProcessor (it resolves the analyte via analyteForLoinc(), which only
-- ever yields "cholesterol"/"triglycerides"/"a1c"/"glucose") — so "lipids" is
-- split into its two real analyte buckets here, matching config, not renamed.
--
-- Known accepted limitation (see the U5 report): the "cholesterol" bucket
-- covers three distinct LOINC codes (total cholesterol, LDL, HDL) under one
-- threshold+direction, so a single "high" threshold is a simplification for
-- LDL/total cholesterol risk targets and is directionally WRONG for HDL
-- (where low, not high, is the abnormal direction). Splitting this further
-- needs per-LOINC (not per-analyte-bucket) threshold config — a versioned
-- config/schema extension per the T13 extension model, out of scope here.
--
-- Endocrinology targets used (ADA general targets for non-pregnant adults
-- with type 2 diabetes); each is its own versioned row so a target change
-- ships as a version bump (E5), never an in-place semantic change.
-- ============================================================================
#IfNotRow mod_copilot_cadence code_set threshold:a1c
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('threshold:a1c', NULL, '{"value":7.0,"direction":"high","description":"ADA general A1c target for most non-pregnant adults with type 2 diabetes: <7.0%"}', 'v1', CURRENT_TIMESTAMP());
#EndIf

#IfNotRow mod_copilot_cadence code_set threshold:glucose
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('threshold:glucose', NULL, '{"value":130,"direction":"high","description":"ADA preprandial/fasting plasma glucose target upper bound: 130 mg/dL"}', 'v1', CURRENT_TIMESTAMP());
#EndIf

#IfNotRow mod_copilot_cadence code_set threshold:cholesterol
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('threshold:cholesterol', NULL, '{"value":100,"direction":"high","description":"LDL-C ASCVD-risk target for diabetic patients: <100 mg/dL. Bucket also covers total cholesterol and HDL codes (see table.sql comment above) — accepted simplification, not directionally correct for HDL."}', 'v1', CURRENT_TIMESTAMP());
#EndIf

#IfNotRow mod_copilot_cadence code_set threshold:triglycerides
INSERT INTO `mod_copilot_cadence` (`code_set`, `interval`, `config_json`, `version`, `updated_at`) VALUES
    ('threshold:triglycerides', NULL, '{"value":150,"direction":"high","description":"Normal fasting triglycerides upper bound: 150 mg/dL"}', 'v1', CURRENT_TIMESTAMP());
#EndIf
