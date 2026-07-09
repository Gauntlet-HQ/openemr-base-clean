# OpenEMR System Audit

The below is a system audit per directions in the Project Requirements Document "AgentForge Clinical Co-Pilot".  The Challenger is directed to determine the best course of action to develop an AI agent that will be integrated into the existing OpenEMR application. The architecture for this project is developed under separate cover.  

The scope of this document is to audit the OpenEMR application to accommodate the AI agent, because the agent must meet specific functional requirements that both meet customer needs and assure that patient records contained in the application or passed to other services do not violate HIPAA guidelines for patient privacy.  

This audit was performed using the Opus 4.3 agent in the CursorAI IDE.  No remediation has been completed, but will be documented with functional versions of the agent.  

> **Revision (2026-07-09).** This revision reconciles the audit with the current state of the repository. Since the previous revision the codebase has grown two AI capabilities beyond the original patient-history summary: (a) an **AI observability ledger** (`src/Services/AI/Observability/*`, `bin/ai-observability-report.php`, `OBSERVABILITY.md`) that records per-transaction step timings, token counts, estimated cost, and success/failure — and **deliberately stores no patient identifiers or clinical content**; and (b) an **AI differential-diagnosis feature** (`src/Services/AI/DiagnosisSuggestionService.php`), a second service that transmits clinical PHI to OpenAI. The diagnosis service is **service-layer only — it has no REST route or UI entry point yet**, so it is not user-reachable, but it inherits every AI-path finding below. The observability ledger adds operational monitoring (partially addressing the "no metrics" performance gap) but is **not** a PHI-disclosure audit trail and therefore does not close S-2 / C-1.

---

## Table of Contents

1. [One-Page Summary — Key Findings](#1-one-page-summary--key-findings)
2. [Security Audit](#2-security-audit)
3. [Performance Audit](#3-performance-audit)
4. [Architecture Audit](#4-architecture-audit)
5. [Data Quality Audit](#5-data-quality-audit)
6. [Compliance & Regulatory Audit](#6-compliance--regulatory-audit)
7. [Consolidated Risk Register](#7-consolidated-risk-register)

---

## 1. One-Page Summary — Key Findings

OpenEMR is a trusted product currently in use by Primary Care Offices. It uses PHP EHR, MySQL, a REST/FHIR API runs on a Symfony HttpKernel stack, while every web request boots the ~863-line `interface/globals.php`. 

This audit uses five dimensions as listed in the table of contents, and has been demonstrated with live queries against 1,000 synthetic sample patients. 

The live patient database was generated using the following query:  

"Create sample patient data for 1000 patients to be used by OpenEMR.  The sample data must include patient names, sex, birthdate, ethnicity, intake information 85% of patients with 15% new patients and should be identified as having no patient history.  10% of new patients have identified a visit condition with the remaining 5% reporting a wellness visit, meaning they have no problems.  90% of all patients have some prescription medication either current or recently discontinued.  87% have bloodwork with 13% having some abnormality like high a1C, low testosterone, or high white blood cell count."

This database was generated to demonstrate what kind of information streams the agent should be able to handle.

The full risk register at the end ranks every finding by severity and will need to be addressed during the development of the Co-Pilot.

**Bottom Line Up Front:**  The OpenEMR requires a series of remediations that have been documented in this audit in order to operate as a functional environment for the AI agent to be introduced.  A risk register was generated and will be incorporated into development plans going forward.

Security & compliance (highest priority). Currently, the AI paths that transmit PHI to OpenAI do so with no programmatic BAA/zero-retention gate. There are now **two such paths** — the patient-history summary and the newer differential-diagnosis service (S-1/C-5). Both minimize identifiers (name/DOB excluded) but still send clinical facts tied to a retrievable record. Per the code comments there is no audit entry, so PHI disclosures are invisible to the audit trail, which means that HIPAA standards for accountability cannot be met. The new observability ledger records operational metrics only (no identifiers, no clinical content) and does **not** satisfy this disclosure-accounting duty. Other issues detected by the audit include a least-privilege violation, and there is no real encryption for patient data at the application layer.  All of these issues will need to be remediated before any interfaces can be developed and employed.  

Performance & latency. There are several system calls that will cause serious latency issues with the system. (P-1, Critical for UX, P-2, P-4, P-5 and P-6). Data calls are either passed through multiple processes or subject to write-locks and will cause latencies of up to 30 seconds, which will violate the medical professional's need to have all information at hand in under 90 seconds for review and employment.

Architecture. New capabilities feature a sidecar-type application that will require a REST API.  Additionally, Railway will be employed for cloud deployment (a `railway/entrypoint.sh` and deployment image are now present). The AI feature set has expanded into a small `src/Services/AI/` package (two summary/diagnosis services, an OpenAI client behind `OpenAiChatClientInterface`, and an `Observability/` sub-package); the diagnosis service is not yet wired to an endpoint.  

Data quality. The sample dataset is internally clean and referentially intact (no duplicates, no orphan records, valid formats), but has systematic gaps that are direct agent failure modes: SSN and primary-provider are 100% empty, `sex` is binary-only, and phone/email formats are unrealistically uniform. This list was synthesized by the developer and will need improvement before further testing.  


---

The audits below were generated using the information supplied.  The recommendations at the bottom of each section will be incorporated into the Plan of Action for the development of the Co-Pilot.  

## 2. Security Audit

Covers authentication, authorization, data-exposure vectors, PHI handling, and HIPAA-relevant gaps.

### 2.1 Authentication — generally strong

| Area | Finding | Severity | Evidence |
|------|---------|----------|----------|
| Login flow | Multi-stage: password → optional MFA → session + CSRF key | Info | `library/auth.inc.php:29-63`; `interface/main/main_screen.php:143-416` |
| Password hashing | Configurable `gbl_auth_hash_algo`; default `PASSWORD_DEFAULT` (bcrypt/argon2); legacy migration via `password_verify`/`hash_equals` | Low (+) | `src/Common/Auth/AuthHash.php:36-52,187-224` |
| MFA / 2FA | TOTP + U2F supported; secrets encrypted at rest — but optional, only enforced if a user has registrations | Medium | `src/Common/Auth/MfaUtils.php:22-24,167`; `interface/main/main_screen.php:171` |
| Brute-force | Per-user (default 20) and per-IP (default 100) counters + timing-attack mitigation; can be disabled by setting limits to 0 | Low (+) | `src/Common/Auth/AuthUtils.php:1163-1230`; `library/globals.inc.php:2202-2227` |
| OAuth2 / JWT | League OAuth2 resource server, scope enforcement, SMART scopes; password grant exists (documented non-production) | Low | `src/RestControllers/Authorization/BearerTokenAuthorizationStrategy.php:327+`; `src/RestControllers/Subscriber/AuthorizationListener.php:134-192` |

### 2.2 Authorization — consistent, with an AI-specific gap

- ACL model: gacl-based RBAC via `AclMain::aclCheckCore($section,$value,$user)` (`src/Common/Acl/AclMain.php:166+`); superuser bypass at `:174-176`.
- REST coverage: 97 of 98 standard routes call `RestConfig::request_authorization_check()`; the one exception (`POST /api/background_service/$run`) is documented and still requires authentication (`apis/routes/_rest_routes_standard.inc.php:712-723`).

Finding S-3 (High) — AI feature under-scoped. The AI summary reads clinical history (encounters, social history, active problems) but authorizes with `patients/demo` (demographics) rather than `patients/med` (medical records) used by comparable endpoints:

```106:110:apis/routes/_rest_routes_standard.inc.php
    "GET /api/patient/:puuid/history_summary" => function ($puuid, HttpRestRequest $request) {
        RestConfig::request_authorization_check($request, "patients", "demo");
        $return = (new PatientHistorySummaryRestController())->getSummary($puuid, $request);
```

The UI endpoint has the same mismatch (`interface/patient_file/summary/ai_history_summary.php:31`), while e.g. the allergy endpoint requires `patients/med` (`_rest_routes_standard.inc.php:272-274`). A demographics-only user can trigger AI summarization of medical history — a minimum-necessary / least-privilege violation.

> The newer `DiagnosisSuggestionService` reads the same class of medical data (current visit, active medications, problem list) but has **no REST route or UI hook yet**, so no ACL currently guards it. When it is wired to an endpoint it must be authorized at `patients/med` (or a dedicated ACO) rather than repeating the `patients/demo` mistake above.

### 2.3 Data exposure vectors

| Finding | Severity | Evidence |
|---------|----------|----------|
| S-4 API exception messages returned to clients — raw `$e->getMessage()` in JSON error bodies can leak paths/SQL fragments | High | `apis/dispatch.php:41-44`; `src/RestControllers/Subscriber/ExceptionHandlerListener.php:50-54` |
| Dynamic SQL column building in patient finder from `$_GET['sColumns']` (mitigated by `escape_sql_column_name()` but relies on escaping, not allowlist) | Medium | `interface/main/finder/dynamic_finder_ajax.php:54,217-259` |
| Core session cookie `HttpOnly=false` and `Secure=false` by default (intentional for multi-tab UX; increases XSS/session-theft surface) | Medium | `src/Common/Session/SessionConfigurationBuilder.php:26-27,83-90`; `src/Common/Session/SessionUtil.php:7-17` |
| `.env` protection depends on web-server config; no in-repo root `.htaccess` deny rule | Medium | `.gitignore:5`; only 9 `.htaccess` files, none at project root |
| SQL injection generally mitigated by parameterized `sqlStatement($sql,[$binds])`; residual legacy string-concat exists | Low–Medium | `library/forms.inc.php:94`; commented example `interface/usergroup/usergroup_admin.php:519-530` |
| XSS helpers sound (`text()`/`attr()` in `library/htmlspecialchars.inc.php`); AI UI uses safe `textContent` | Low (+) | `interface/patient_file/summary/demographics.php:1115-1116` |
| CSRF: HMAC tokens; enforced on AI UI endpoint | Low (+) | `src/Common/Csrf/CsrfUtils.php`; `ai_history_summary.php:27` |

### 2.4 PHI handling & HIPAA-relevant gaps

| Finding | Severity | Evidence |
|---------|----------|----------|
| S-1 AI features transmit clinical PHI to OpenAI with no programmatic BAA gate (warnings are code comments only), no data-retention control, no runtime enable/disable — now on **two** paths (history summary + diagnosis suggestions) | Critical | `src/Services/AI/PatientHistorySummaryService.php:105-110`; `src/Services/AI/DiagnosisSuggestionService.php:106-111`; `src/Services/AI/OpenAiChatClient.php:10-12,65-76`; `.env.example` |
| S-2 No audit-trail entry for AI PHI disclosure — no `EventAuditLogger` calls anywhere in the AI services/controller/UI. The observability ledger records ops metrics only (no user, no patient id, no data categories) and is explicitly identifier-free, so it does **not** fill this gap | High | `src/Services/AI/*`, `src/Services/AI/Observability/MarkdownObservabilityRecorder.php:13,173-175`, `ai_history_summary.php` (absence) |
| S-6 `patient_data` / clinical tables not encrypted at the application layer — `database_encryption` global exists but `CryptoGen` only covers code paths that call `encryptForDatabase` (not patient demographics) | Medium | `src/Common/Crypto/CryptoGen.php:65-67`; `library/globals.inc.php:1028-1032` |
| Encryption in transit to OpenAI is HTTPS (positive); OAuth/API cookies use `Secure=true` | Low (+) | `OpenAiConfig.php:27,57-59` |
| Audit logging exists and defaults on, but is configurable/disable-able and does not cover AI events | Low–Medium | `src/Common/Logging/EventAuditLogger.php:672-694`; `library/globals.inc.php:2778-2789` |
| Minimum-necessary: AI prompts deliberately exclude name/DOB but send broad clinical context (encounter reason, social history, all active problems/medications). Applies to both AI services | Medium | `PatientHistorySummaryService.php:12-13,132-190`; `DiagnosisSuggestionService.php:17-19,184-214` |
| No AI-specific data-retention/deletion — OpenAI-side copies governed entirely by provider policy | High | (absence of retention code) |

### 2.5 Security recommendations (not implemented)

1. Gate both AI services (history summary + diagnosis suggestions) behind a shared runtime config flag; require a confirmed OpenAI BAA + zero-retention before enabling in production. Ideally enforce this once in `OpenAiChatClient`/`OpenAiConfig` so no service can bypass it.
2. Raise AI authorization to `patients/med` (or a dedicated ACO) on the existing UI and REST paths, and apply the same before exposing the diagnosis service through any endpoint.
3. Add `EventAuditLogger` entries for every AI request (user, patient UUID/pid, timestamp, data categories sent). The observability ledger is not a substitute — it is identifier-free by design.
4. Stop returning `$e->getMessage()` in API error responses (`dispatch.php`, `ExceptionHandlerListener.php`).
5. Set `cookie_secure` for core sessions in HTTPS deployments; add root web-server deny rules for `.env`/`vendor/`.
6. Document DB-level (TDE) encryption as a HIPAA at-rest requirement for `patient_data`.

---

## 3. Performance Audit

Covers where the system is slow, bottlenecks, how the data is structured, and constraints on AI-agent response latency.

### 3.1 Data structure & indexing

Query execution runs primarily through the legacy ADODB surface (`library/sql.inc.php` → `QueryUtils`), with Doctrine DBAL used for migrations/audit but not on the hot read path (`PatientService`, `EncounterService`, and the AI services all use ADODB/`QueryUtils`).

Index coverage on common lookup columns is mostly good, with one notable gap:

| Table | Column | Indexed? | Evidence |
|-------|--------|----------|----------|
| `patient_data` | `pid` | ✅ UNIQUE | `sql/database.sql:8467` |
| `patient_data` | `pubpid` | ❌ none | column `:8379`; keys only `:8467-8471` |
| `lists` | `pid` | ✅ | `sql/database.sql:7709` |
| `form_encounter` | `pid`,`date` | ✅ composite + date | `sql/database.sql:2060` |
| `prescriptions` | `patient_id` | ✅ | `sql/database.sql:8749` |
| `procedure_order` | `patient_id` | ✅ | `sql/database.sql:10408-10409` |
| `history_data` | `pid` | ✅ | `sql/database.sql:3009` |

P-6 (High at scale): external-ID lookups on `patient_data.pubpid` (the column the sample generator populates) will table-scan without an index.

### 3.2 Query anti-patterns in the service layer

| Finding | Severity | Evidence |
|---------|----------|----------|
| P-5 `PatientService::search()/getOne()` runs 3 SQL round-trips (COUNT DISTINCT uuids → fetch uuids → full multi-join hydration incl. `patient_data.*`). The AI REST controller pays this just to resolve a UUID→pid. | High | `src/Services/PatientService.php:420-423,504-516`; `PatientHistorySummaryRestController.php:42` |
| P-7 `getEncountersForPatientByPid()` has no LIMIT — fetches all encounters | High | `src/Services/EncounterService.php:679-681` |
| P-8 `QueryUtils::escapeTableName()` runs `SHOW TABLES` on invocation | High | `src/Common/Database/QueryUtils.php:50-55` |
| `EncounterService::getMostRecentEncounterForPatient()` uses the full ~15-join `search()` even for LIMIT 1 | Medium–High | `EncounterService.php:127-133` |
| `PatientIssuesService::getActiveIssues()` unbounded + LEFT JOIN | Medium | `PatientIssuesService.php:89-99,173-211` |
| `SocialHistoryService::getHistoryData()` defaults to `SELECT *` on a wide (~30 longtext col) table; AI uses only 4 fields | Medium | `SocialHistoryService.php:43-57` |
| `BaseService` query-in-loop (`splitAndProcessMultipleFields`, per-code `addCoding`) | Medium | `BaseService.php:587-589,559-564` |

> The new `DiagnosisSuggestionService` reuses the same `getMostRecentEncounterForPatient()` (heavy join for a single row) and unbounded `getActiveIssues()` calls, so it inherits P-7 and the LIMIT-1-via-full-`search()` cost. Each AI call also now appends one row to `OBSERVABILITY.md` under `LOCK_EX`; the write is best-effort and swallows I/O errors so it cannot break the request, but it is a small additional per-call filesystem operation (and a potential contention point if the ledger were ever placed on shared/slow storage).

### 3.3 Bottlenecks & infrastructure

| Area | Finding | Severity | Evidence |
|------|---------|----------|----------|
| Application cache | No Redis/APCu application-data cache; only file-based GACL cache + optional Redis *sessions* | High (for AI) | GACL `src/Gacl/Gacl.php:309-311`; Redis only in `src/Common/Session/Predis/*` |
| Twig cache | Compiled-template cache disabled (no `cache` option) | Low–Medium | `src/Common/Twig/TwigContainer.php:70` |
| Session locking | Core avoids lock via read-only sessions, but REST forces a writable session (`$sessionAllowWrite = true`), so the OpenAI call holds the write-lock and serializes concurrent same-session requests | High (REST) | `src/RestControllers/Subscriber/SiteSetupListener.php:189-190`; `SessionUtil.php:37-46` |
| Bootstrap cost | `interface/globals.php` (~863 lines) loads per request: autoloader, Kernel/DI, DB connect, full `globals` table scan, auth check, module discovery, HTTP audit-log write | High | `interface/globals.php:450-453,725-727,748-753,847-849` |
| Per-request auth | `authCheckSession()` + `session_tracker` update hit the DB on every authenticated request | Medium | `src/Common/Auth/AuthUtils.php:841-846`; `src/Common/Session/SessionTracker.php:85` |
| SQL audit | Standard `Execute(..., true)` logs every query | Medium | `QueryUtils.php:222` |

### 3.4 Constraints on AI-agent response latency

The AI history-summary request is fully synchronous and sequential:

```
Client (UI fetch or REST GET)
  → globals.php bootstrap (~863 lines + globals table scan + auth + modules)
  → ACL check
  → [REST only] PatientService::getOne()  — 3 heavy SQL queries (UUID→pid)
  → summarizeByPid():
       1. EncounterService::getMostRecentEncounterForPatient()  (heavy JOIN, LIMIT 1)
       2. SocialHistoryService::getHistoryData()               (SELECT * history_data)
       3. PatientIssuesService::getActiveIssues()             (unbounded lists JOIN)
       4. OpenAiChatClient::chat()                            (BLOCKING HTTP, up to 30s)
  → JSON response
```

Hard latency floors an integrated AI agent must design around:

1. Blocking OpenAI round-trip, 30s timeout, not env-tunable — `OpenAiConfig.php:28` (`DEFAULT_TIMEOUT_SECONDS = 30.0`; `fromEnvironment()` reads only `OPENAI_API_KEY`/`OPENAI_MODEL`); `OpenAiChatClient.php:49-60`. HTTP client is synchronous Guzzle via `oeHttp` (`src/Common/Http/oeHttpRequest.php:170`).
2. No summary caching — `PatientHistorySummaryResult` is a transient DTO; identical re-requests repeat all DB + OpenAI work (`src/Services/AI/PatientHistorySummaryResult.php`).
3. 3+ sequential DB queries before the model call, several over-fetching (full joins / `SELECT *`) for a handful of fields.
4. REST UUID→pid resolution adds the 3-query `PatientService` pipeline.
5. Full `globals.php` bootstrap per call; the demographics UI pays it twice (page load + AJAX endpoint).
6. REST session write-lock held for the entire external call, blocking concurrent agent actions in the same session.
7. No async/queue/worker — the caller must block for the whole PHP request lifecycle; there is no job-id + poll pattern.

Practical expectation: ≥1 s best case (new patient / no OpenAI call), typically 2–15 s when the model is invoked, 30 s worst-case timeout.

> **New (positive): the AI paths are now instrumented.** Each call is wrapped in an `AiObservability` transaction that measures every step (`fetch_recent_encounter`, `fetch_social_history`, `fetch_active_issues`, `openai_chat`) with a monotonic timer and records token counts and estimated cost to `OBSERVABILITY.md`; `bin/ai-observability-report.php` aggregates failure rate, average latency, and spend. This gives real per-step latency data to target the fixes below — but it is measurement only and does not change the synchronous, uncached, blocking call path. The differential-diagnosis service uses the same transaction pattern (`diagnosis_suggestion`), so both AI paths share these latency floors.

### 3.5 Performance recommendations (not implemented)

Add async job + poll for OpenAI; cache summaries keyed on `(pid, max(history_updated_at))`; use a lightweight `SELECT pid FROM patient_data WHERE uuid=?` (or `findByPid`) instead of `getOne()`; add `KEY pubpid (pubpid)`; release/avoid the REST session write-lock for read endpoints; expose `OPENAI_TIMEOUT_SECONDS`; enable the Twig cache in production.

---

## 4. Architecture Audit

Covers system organization, where data is stored, how layers interact, and integration points for new capabilities.

### 4.1 System organization / layers

| Path | Role |
|------|------|
| `/src` | Modern PSR-4 (`OpenEMR\`): `Services/`, `RestControllers/`, `FHIR/`, `Events/`, `Core/`, `Common/`, `BC/` |
| `/library` | Legacy procedural PHP: SQL helpers, ACL, document classes, globals definitions |
| `/interface` | Web UI page controllers; each `require`s `interface/globals.php` |
| `/apis` | REST/FHIR/portal entry (`dispatch.php`) + route tables (`routes/_rest_routes_*.inc.php`) |
| `/templates` | Modern Twig (282+ files); legacy Smarty elsewhere |
| `/interface/modules` | Extension modules (Laminas `zend_modules/` + `custom_modules/oe-module-*`) |
| `/portal` | Patient portal (separate MVC + Twig) |
| `/sites/<site>` | Per-site config + filesystem document storage |

Two bootstrap chains:
- Web UI: `interface/*.php` → `interface/globals.php` (Kernel, DB, globals→`OEGlobalsBag`, modules, session).
- REST API: `apis/.htaccess` → `apis/dispatch.php` → `ApiApplication` → `OEHttpKernel` (Symfony) → route finders → `HttpRestRouteHandler::dispatch()` → controller → service. There is no `public/api` directory.

Modern ↔ legacy bridging is pervasive (not a clean boundary): modern services `require_once` legacy includes (`BaseService` → `custom/code_types.inc.php`), both layers share `$GLOBALS`/`OEGlobalsBag`, and `QueryUtils` wraps the same ADODB connection opened by `library/sql.inc.php`.

### 4.2 Where data is stored

| Store | Role | Evidence |
|-------|------|----------|
| MySQL/MariaDB | System of record — all clinical, admin, module, API metadata | `library/sql.inc.php`; `src/Common/Database/QueryUtils.php` |
| Filesystem `sites/<site>/documents/` | Default binary document storage | `library/classes/Document.class.php:1054+` |
| CouchDB (optional) | Alternate document *binary* store (metadata stays in MySQL `documents`) via `document_storage_method` | `library/classes/CouchDB.class.php`; `Document.class.php:999-1028` |
| LDAP/AD (optional) | Authentication only, not a data store | `src/Common/Auth/AuthUtils.php:871,898` |
| Redis (optional) | Session storage in prod/K8s; not an app cache; not in `development-easy` | `src/Common/Session/Predis/*` |
| Elasticsearch | Not used by core (only an optional Monolog log handler dep) | `composer.lock` |

The Docker `development-easy` stack additionally runs Mailpit (dev SMTP capture) and Selenium (E2E only).

### 4.3 How layers interact

- Event system: Symfony `EventDispatcherInterface` owned by `src/Core/Kernel.php`, shared with modules. 82 event classes in `src/Events/` (`RestApiCreateEvent`, `RestApiSecurityCheckEvent`, `ServiceSaveEvent`, demographics `RenderEvent`/`SectionEvent`, `ModuleLoadEvents`, …).
- Service container: two overlapping mechanisms — `OpenEMR\Core\Kernel` (Symfony `ContainerBuilder`, holds dispatcher) and the transitional static locator `OpenEMR\BC\ServiceContainer` (`getLogger()`, `getCrypto()`, PSR-17 factories). A full PSR-11 DI container is explicitly a not-yet-done goal (per `ServiceContainer` and `RoutesExtensionListener` comments).
- Typical flow: route closure → `RestConfig::request_authorization_check()` → RestController → Service → `QueryUtils` → MySQL.
- Globals/session as service locator: `$GLOBALS`/`OEGlobalsBag`, `$_SESSION`/`SessionUtil`, and `OEGlobalsBag::getKernel()` are read throughout; `new Service()` is still common instead of injection.

### 4.4 Integration points for new capabilities

| Capability | Extension point |
|------------|-----------------|
| Standard REST endpoint | Add closure to `apis/routes/_rest_routes_standard.inc.php` + a `src/RestControllers/` class (as the AI route does) |
| Dynamic route (no core edit) | Listen to `RestApiCreateEvent::EVENT_HANDLE`, call `$event->addToRouteMap()` |
| FHIR resource | `src/Services/FHIR/` service + `_rest_routes_fhir_r4_us_core_3_1_0.inc.php` (or `addToFHIRRouteMap()`) |
| Portal API | `_rest_routes_portal.inc.php` / `addToPortalRouteMap()` |
| Save hooks | `ServiceSaveEvent::EVENT_PRE_SAVE/EVENT_POST_SAVE` via `ServiceEventTrait` |
| Demographics UI card | `SectionEvent` + `CardInterface` (event-driven, unlike the AI inline hook) |
| Custom module | `interface/modules/custom_modules/oe-module-<name>/openemr.bootstrap.php` — register listeners, `ServiceContainer::override()` |
| External-service client | Pattern of `src/Services/AI/OpenAiChatClient.php`: config from env via `OEEnvBag`, PSR-3 logging, generic exceptions |

### 4.5 The AI feature as a reference integration

The AI code now forms a small package under `src/Services/AI/`:
- `OpenAiConfig` (env-driven) → `OpenAiChatClient` (HTTP via `oeHttp`), behind `OpenAiChatClientInterface`; `chatWithUsage()` returns an `OpenAiChatResult` (content + model + `TokenUsage`), with `chat()` a thin text-only wrapper.
- `PatientHistorySummaryService` (reuses `EncounterService`, `SocialHistoryService`, `PatientIssuesService`; returns `PatientHistorySummaryResult`).
- `DiagnosisSuggestionService` (reuses `EncounterService` + `PatientIssuesService`, partitions active `lists` rows into medications vs. conditions; returns `DiagnosisSuggestionResult`; depends on narrow `RecentEncounterProviderInterface` / `ActivePatientIssuesProviderInterface` collaborator interfaces).
- `Observability/` sub-package: `AiObservability::begin()` hands out an `AiTransaction` that `measure()`s each step, records `TokenUsage`/cost via `TokenPricing`, and on `commit()` writes an `AiTransactionRecord` through an `ObservabilityRecorderInterface` (`MarkdownObservabilityRecorder` → `OBSERVABILITY.md` in prod, `InMemoryObservabilityRecorder` in tests). Uses PSR-20 clock + `hrtime` monotonic timer injection for deterministic tests.

Entry points:
- REST: `GET /api/patient/:puuid/history_summary` → `PatientHistorySummaryRestController` (UUID→pid, maps `OpenAiException`→HTTP 502).
- UI: a button + `loadAiHistorySummary()` inlined in `demographics.php:1091-1125` → AJAX to `ai_history_summary.php` (globals + CSRF + ACL, reads pid from session).
- Diagnosis suggestions: **no entry point yet** — `DiagnosisSuggestionService::suggestByPid()` is exercised only by isolated tests and the `diagnosis-suggestions` skill. Wiring a REST controller / UI hook (with `patients/med` authorization and disclosure auditing) is the outstanding integration step.

Architectural notes: the service layer follows the recommended modern pattern (constructor injection with nullable defaults, interfaces for cross-boundary collaborators, immutable result DTOs, strict types, PSR-20 clock). The observability recorder is cleanly abstracted behind an interface. Two rough edges remain: the history-summary UI hook is ad-hoc inline HTML/JS rather than the `RenderEvent`/`SectionEvent` card pattern used elsewhere on the same page, and `MarkdownObservabilityRecorder` writes to a repo-root Markdown file rather than a durable store (fine for a demo ledger, not for production telemetry).

---

## 5. Data Quality Audit

Assessed against the live development database: 1,000 generated sample patients (`pubpid LIKE 'SMPL%'`), which are 100% of the `patient_data` table. Covers missing fields, inconsistent formatting, duplicate records, and stale data.

### 5.1 Completeness — missing fields

| Field | Missing/empty (of 1,000) | Assessment |
|-------|--------------------------|------------|
| fname, lname, DOB, sex, race, ethnicity | 0 | Complete |
| street, city, state, postal_code, phone_home, email | 0 | Complete |
| status (marital), title | 0 | Complete |
| `ss` (SSN) | 1,000 (100%) | Gap — never populated by the generator |
| `providerID` (primary/PCP) | 1,000 (100%) | Gap — no primary provider assigned on `patient_data` (encounters do reference a provider) |

Referential completeness is otherwise intact: 0 patients without an encounter, 0 without a `history_data` row, 0 encounters missing `provider_id`, 0 problems missing a diagnosis code, 0 prescriptions with an empty drug.

### 5.2 Consistency — formatting

| Check | Result | Note |
|-------|--------|------|
| Phone format | 1 pattern for all 1,000: `(NNN) NNN-NNNN` | Internally consistent, but unrealistically uniform |
| Email validity | 0 invalid (all `first.lastNNN@example.com`) | Single reserved domain — uniform |
| DOB format | 0 not `YYYY-MM-DD` | Consistent |
| Postal code | 0 with length ≠ 5 | Consistent |
| Names | 0 with non-alpha characters | Consistent |
| `sex` values | `Male`=514, `Female`=486 | Binary only — no `Unknown`/other/declined values |
| `state` | 10 distinct | Drawn from a fixed 10-city pool |

### 5.3 Duplicates & identity

| Check | Result |
|-------|--------|
| Exact `fname+lname+DOB` duplicate groups | 0 |
| Duplicate `pubpid` | 0 |
| Duplicate `email` | 0 |
| Duplicate SSN | n/a (all empty) |
| Missing/zero `uuid` | 0 |

No duplicate or near-duplicate patient identities were found.

### 5.4 Stale / temporal data

| Check | Result | Note |
|-------|--------|------|
| Encounter date range | 2023-07-03 → 2026-07-07 | ~3-year spread |
| Encounters older than 2 years | 316 | Expected for "established" history |
| Encounters older than 3 years | 1 | Edge of the generated window |
| Prescriptions active / discontinued | 975 active / 368 discontinued | "current or recently discontinued" as specified |
| Discontinued rx missing `end_date` | 0 | Consistent |
| Problems missing `begdate` | 0 | Consistent |
| DOB in the future | 0 | Valid |
| Implausible age (<18 or >100) | 0 | Ages constrained to 18–88 |
| Bloodwork result rows | 3,000 (1,000 × 3 analytes) | Complete |

> The "latest" encounter timestamp (`2026-07-07 01:09` UTC) is slightly ahead of local wall-clock because the container runs in UTC and new-patient visits are stamped at generation time — not a data error.

### 5.5 Data-quality assessment

The synthetic dataset is internally clean, consistent, and referentially intact — well suited for demos and for exercising the AI summary path. Its "issues" are therefore mostly representativeness gaps rather than dirty data:

- Real gaps to close in the generator: populate `ss` and `providerID`; include non-binary/`Unknown` `sex` values.
- Realism caveats: uniform phone/email formats and a single `@example.com` domain mean this dataset will not surface the formatting inconsistencies, duplicate/merge candidates, null-heavy records, or stale-data problems that a real patient population exhibits. When production or migrated data is loaded, re-run these same checks — the absence of dirty data here is a property of the generator, not evidence the system enforces data quality (the schema/app layer imposes few such constraints).

---

## 6. Compliance & Regulatory Audit

Assesses HIPAA Security Rule (45 CFR §164.302–318), Privacy Rule (§164.500+), and Breach Notification Rule (§164.400–414) obligations against what the codebase actually implements, with particular attention to the AI/LLM PHI path. This maps the technical findings in §2 to regulatory duties; it is a compliance-readiness assessment, not legal advice.

### 6.1 Audit logging requirements

Regulatory basis: HIPAA §164.312(b) (Audit controls) and §164.308(a)(1)(ii)(D) (Information system activity review) require mechanisms to record and examine activity in systems containing ePHI.

What OpenEMR implements (strong baseline):

| Capability | Implementation | Evidence |
|------------|----------------|----------|
| Central audit logger | `EventAuditLogger::newEvent()` writes to `log` + `log_comment_encrypt` | `src/Common/Logging/EventAuditLogger.php:187,405,700` |
| Tamper-evidence | SHA3-512 checksum of each entry (and a separate API checksum) stored per row | `src/Common/Logging/Audit/LogTablesSink.php:63-94` |
| Configurable event categories | patient-record, scheduling, order, lab-order, lab-results, security-administration, query, http-request, cdr, backup, other | `EventAuditLogger.php:71-80`; `library/globals.inc.php:2785-2845` |
| Standards-based remote audit | IHE ATNA sink (TLS syslog to an external audit repository) | `EventAuditLogger.php:49-65`; `src/Common/Logging/Audit/AtnaSink.php`; globals `:2858-2886` |
| Break-glass emergency access | Interface + checker for logging override access | `src/Common/Logging/BreakglassChecker.php`, `BreakglassCheckerInterface.php` |
| Authentication events | Login success/failure and API token events are audited | `AuthUtils.php:400,489`; `BearerTokenAuthorizationStrategy.php:299,316` |

Compliance gaps:

| Gap | Severity | Detail |
|-----|----------|--------|
| C-1 AI PHI disclosure is not audited | High | No `EventAuditLogger` call exists in `src/Services/AI/*`, `PatientHistorySummaryRestController`, or `ai_history_summary.php`, for either AI service. A disclosure of PHI to a third party (OpenAI) leaves no record of who requested it, for which patient, when, or what data categories were sent — directly undermining §164.312(b) and the §164.528 accounting-of-disclosures duty. The `OBSERVABILITY.md` ledger added since the last revision records that *a* transaction occurred (type, timing, tokens, cost, outcome) but is **identifier-free by design** — it names neither the user nor the patient — so it cannot serve as a §164.528 accounting of disclosures. |
| Audit logging is disable-able | Medium | `enable_auditlog` and each `audit_events_*` category can be turned off (`library/globals.inc.php:2778-2845`); a misconfiguration silently removes the activity record. |
| No log-review workflow / alerting in core | Medium | The system records events but provides limited built-in periodic-review or anomaly-alerting tooling (§164.308(a)(1)(ii)(D) expects active review, not just capture). ATNA export to a SIEM is the intended path but is optional and off by default. |

Required for AI compliance: every AI summary request must emit an audit event capturing user, patient UUID/pid, timestamp, destination (OpenAI), model, and the categories of PHI transmitted — ideally as a §164.528-accountable disclosure.

### 6.2 Data retention policies

Regulatory basis: HIPAA §164.316(b)(2) requires retention of Security-Rule *documentation* (including audit records/policies) for 6 years. HIPAA itself sets no medical-record retention period — that is governed by state law (commonly 6–10 years for adults; longer for minors, e.g., until age of majority + N years) and by CMS/payer rules.

What OpenEMR implements:

| Aspect | Finding | Evidence |
|--------|---------|----------|
|  No automated retention/purge  | No retention window, TTL, archival, or purge job exists for `log`, `log_comment_encrypt`, or clinical tables. Grep for retention/purge/prune/`days_to_keep` across logging code and globals returned nothing. | (absence across `src/Common/Logging/*`, `library/globals.inc.php`) |
| Records grow indefinitely | Audit and clinical data persist until manually removed; retention is entirely an  operational/DBA responsibility , not enforced by the application. | — |
| Deletion is largely soft/manual | Patient/record deletion is administrative; there is no policy-driven lifecycle for stale data. | — |
|  No AI/LLM-side retention control  | Nothing purges or governs data once sent to OpenAI; retention is dictated solely by the provider's policy (default OpenAI API retention is up to 30 days unless a zero-data-retention agreement is in place). | `src/Services/AI/OpenAiChatClient.php` (no retention handling) |

 Compliance gaps: 

-  C-2 (Medium):  absence of an enforced retention schedule means the operator must implement retention externally to satisfy §164.316(b)(2) (6-year audit retention) and applicable state medical-record retention law. The application will neither retain-for-minimum nor purge-after-maximum on its own.
-  C-3 (High):  for the AI path, PHI leaves the retention perimeter entirely. Without a contractual zero-retention (ZDR) BAA, transmitted PHI may persist on the provider's systems beyond the covered entity's control and outside its retention/destruction policy (§164.310(d)(2)(i) media disposal cannot be honored for that copy).

### 6.3 Breach notification obligations

 Regulatory basis:  Breach Notification Rule §164.400–414. An impermissible use/disclosure of unsecured PHI is presumed a breach unless a §164.402(2) four-factor risk assessment shows low probability of compromise. Duties: notify affected individuals  without unreasonable delay, ≤60 days  (§164.404); notify HHS (§164.408 — immediately if ≥500 individuals, else annually); notify media if ≥500 in a state/jurisdiction (§164.406); and Business Associates must notify the Covered Entity (§164.410).

 Assessment against this system: 

| Consideration | Finding | Severity |
|---------------|---------|----------|
| Detection capability | Tamper-evident checksums (§6.1) support integrity verification, and ATNA/audit logs can evidence access —  good  raw material for breach investigation, *if* enabled and reviewed. | Low (+) |
|  C-4 AI disclosure = potential reportable event  | Sending PHI to OpenAI  without a BAA  is an impermissible disclosure to a third party. If it does not qualify for a low-probability-of-compromise determination, it is a reportable breach — and because AI calls are unaudited (C-1), the covered entity cannot even scope *which* patients were affected. | High |
| No built-in breach-notification workflow | Core provides no incident-tracking, affected-individual enumeration, or notification tooling; this is expected to be operational, but the unaudited AI path makes the "identify affected individuals" step (§164.404(c)) impractical. | Medium |
| "Unsecured PHI" framing | PHI at rest in `patient_data` is not encrypted at the application layer (§2.4, S-6), so it is  not  rendered "unsecured-exempt" by the HHS encryption safe harbor; a DB-level breach would be reportable unless full-disk/TDE encryption is in place. | Medium |

 Key point:  breach *readiness* depends on (a) enabling and reviewing audit logs, (b) auditing the AI disclosure path so affected individuals can be enumerated, and (c) securing PHI at rest to qualify for the encryption safe harbor.

### 6.4 Business Associate Agreement (BAA) implications of sending PHI to an LLM provider

 Regulatory basis:  §164.502(e) and §164.308(b) require a BAA with any entity that creates, receives, maintains, or transmits PHI on behalf of a covered entity. §164.504(e) specifies mandatory BAA terms. An LLM provider (OpenAI) that receives PHI  is a Business Associate  and requires a signed BAA before any PHI transmission.

 What the code does today: 

- **Two** AI services now transmit clinical PHI to `https://api.openai.com/v1/chat/completions`: the history summary (encounter reason/date, social history, active problem list — `PatientHistorySummaryService.php:105-110`) and differential-diagnosis suggestions (current visit, active medications, pre-existing conditions — `DiagnosisSuggestionService.php:106-111`), both via `OpenAiChatClient::chatWithUsage()` (`OpenAiChatClient.php:65-76`; `OpenAiConfig.php:26-28,57-59`).
- Both  minimize identifiers  — name and DOB are deliberately excluded (`PatientHistorySummaryService.php:12-13`; `DiagnosisSuggestionService.php:17-19`). This reduces, but does  not eliminate , PHI: clinical facts tied to a retrievable patient record remain PHI, and de-identification to the §164.514(b) standard is not performed.
- The  only  BAA control is a code comment / `.env.example` note (`OpenAiChatClient.php:10-12`) — there is  no runtime gate  that checks for a BAA or blocks PHI transmission when one is absent, on either path. `OpenAiConfig::fromEnvironment()` still reads only `OPENAI_API_KEY`/`OPENAI_MODEL`.

 BAA compliance requirements (must all hold before production PHI use): 

| Requirement | Status | Reference |
|-------------|--------|-----------|
| Signed BAA with the LLM provider covering PHI |  Not enforced  (comment only) | `OpenAiChatClient.php:10-12` |
| Zero-Data-Retention / training opt-out (no use of PHI for model training) | Not verified in code; depends on provider agreement | — |
| Subcontractor flow-down (provider's own subprocessors bound) | Operational — not addressable in code | §164.308(b)(1) |
| Disclosure limited to minimum necessary | Partial — identifiers stripped, clinical context broad | §164.502(b); `PatientHistorySummaryService.php:98-155` |
| Accounting of disclosures possible |  No  — disclosures are unaudited (C-1) | §164.528 |
| Ability to disable if BAA lapses |  No runtime kill-switch  | `OpenAiConfig::fromEnvironment()` only checks key presence |

 Findings: 

-  C-5 (Critical):  PHI is transmitted to a third-party LLM with  no programmatic BAA enforcement  and no runtime control to block transmission when a BAA/ZDR agreement is not in place. This is the same underlying issue as S-1, viewed through the §164.502(e) BAA lens.
-  C-6 (High):  even with a BAA, the absence of disclosure auditing (C-1) prevents satisfying the §164.528 accounting-of-disclosures requirement for PHI sent to the LLM.

 Recommended controls (not implemented): 

1. Add a required runtime config flag (e.g., `OPENAI_BAA_CONFIRMED=true` plus `OPENAI_ZERO_RETENTION=true`) that must be set before any PHI is sent; fail closed otherwise.
2. Emit a §164.528-grade audit/disclosure record on every AI call (see §6.1).
3. Prefer a HIPAA-eligible deployment (e.g., a provider offering a signed BAA and ZDR, or an in-VPC/self-hosted model) and document it in the security-documentation retained for 6 years.
4. Consider stronger de-identification (§164.514) so the payload is not PHI, which would remove the BAA requirement for that path.
5. Maintain a data-processing inventory listing OpenAI as a Business Associate, the PHI categories sent, and the governing BAA.

---

## 7. Consolidated Risk Register

| ID | Audit | Finding | Severity |
|----|-------|---------|----------|
| S-1 | Security | AI sends clinical PHI to OpenAI without BAA enforcement / retention control — now on two paths (summary + diagnosis) |  Critical  |
| C-5 | Compliance | No programmatic BAA/ZDR gate before transmitting PHI to the LLM (§164.502(e)); applies to both AI services |  Critical  |
| P-1 | Performance | Synchronous, uncached, 30s-timeout OpenAI call on the request path |  Critical (UX)  |
| S-2 | Security | No audit-trail entry for AI PHI disclosure | High |
| C-1 | Compliance | AI PHI disclosure not audited (§164.312(b), §164.528) | High |
| C-3 | Compliance | PHI leaves retention perimeter via LLM; no app/provider retention control | High |
| C-4 | Compliance | Un-BAA'd AI disclosure is a potential reportable breach; affected patients not enumerable | High |
| C-6 | Compliance | Accounting-of-disclosures (§164.528) unsatisfiable for AI path even with a BAA | High |
| C-2 | Compliance | No enforced data/audit retention schedule (§164.316(b)(2) + state law) | Medium |
| S-3 | Security | AI authorized with `patients/demo` instead of `patients/med` | High |
| S-4 | Security | API exception messages leaked to clients | High |
| P-2 | Performance | REST session write-lock held during OpenAI call | High |
| P-3 | Performance | Full `globals.php` bootstrap per (AJAX) request | High |
| P-4 | Performance | REST `getOne()` = 3 queries just to resolve UUID→pid | High |
| P-6 | Performance | Missing index on `patient_data.pubpid` | High (scale) |
| P-7 | Performance | `getEncountersForPatientByPid()` unbounded | High |
| DQ-1 | Data Quality | `ss` and `providerID` 100% empty in sample data | Medium |
| S-5 | Security | Core session cookie not `HttpOnly`/`Secure` by default | Medium |
| S-6 | Security | `patient_data` unencrypted at application layer | Medium |
| S-7 | Security | MFA optional; brute-force limits disable-able | Medium |
| S-8 | Security | New `DiagnosisSuggestionService` is a second PHI→OpenAI path; when wired to a route/UI it must inherit `patients/med` authorization + disclosure auditing (none exist yet) | High (when exposed) |
| A-1 | Architecture | No full DI container; `new Service()` + static locators | Medium |
| A-2 | Architecture | AI UI hook inlined in `demographics.php` vs event/card pattern | Low |
| A-3 | Architecture | AI observability ledger persists to a repo-root Markdown file, not a durable telemetry store | Low |
| DQ-2 | Data Quality | Uniform formats / single email domain — not representative of real data | Low (info) |

> **Note (mitigation, not a finding):** the AI observability ledger added in this cycle partially addresses the "no metrics/monitoring" aspect of P-1 by capturing per-step latency, token, and cost data for every AI call. It does **not** alter the synchronous/uncached/blocking call path (P-1 stands) and, being identifier-free, does **not** satisfy the S-2 / C-1 disclosure-auditing gap.

---

*Prepared from static code review and live queries against synthetic sample data only. Runtime configuration, deployment hardening, and infrastructure controls were not tested. The Compliance & Regulatory section maps implemented controls to HIPAA obligations and is a compliance-readiness assessment, not legal advice. No code or data was modified in the course of this audit.*
