# OpenEMR System Audit

**Repository:** `openemr-base-clean`
**Date:** 2026-07-06
**Method:** Read-only static analysis of the working tree (including the uncommitted AI patient-history-summary feature) plus live queries against the running `development-easy` dev database (1,000 generated sample patients).
**Scope:** Five audits — Security, Performance, Architecture, Data Quality, and Compliance & Regulatory.

> All file/line references point at the current working tree. Severities are the auditor's assessment for a HIPAA-regulated production deployment. This document is analysis only; **no remediation has been applied.**

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

OpenEMR is a mature, hybrid PHP EHR: legacy procedural code (`/library`, `/interface`) sits alongside a modern PSR-4 service layer (`/src`). MySQL is the system of record; the REST/FHIR API runs on a Symfony HttpKernel stack, while every web request boots the ~863-line `interface/globals.php`. This audit spans five dimensions — security, performance, architecture, data quality, and compliance — grounded in code (file:line) and live queries against 1,000 synthetic sample patients. The full risk register at the end ranks every finding by severity; the items below are the ones that will most shape a trustworthy Clinical Co-Pilot.

The single most consequential artifact is the in-progress **AI patient-history-summary feature**, which recurs across every lens and previews the risks of the agent to come.

**Security & compliance (highest priority).** The AI path transmits clinical PHI (encounter reason, social history, active problems) to OpenAI with **no programmatic BAA / zero-retention gate** — the only control is a code comment (S-1 / C-5, Critical). It writes **no audit entry**, so PHI disclosures are invisible to the audit trail and cannot satisfy HIPAA's accounting-of-disclosures duty or scope a breach (S-2 / C-1, High). It is authorized with the demographics scope (`patients/demo`) though it reads medical history — a least-privilege violation (S-3, High). Separately, the REST layer returns raw exception messages to clients, leaking internals (S-4, High), and `patient_data` is unencrypted at the application layer, forfeiting HIPAA's encryption safe harbor for at-rest breaches (S-6). OpenEMR's own audit system is strong (tamper-evident SHA3-512 checksums, IHE ATNA export, break-glass) but is disable-able and, critically, not invoked by the AI path.

**Performance & latency.** The AI call is **synchronous, uncached, and blocks for up to 30 seconds** with no async/queue (P-1, Critical for UX). Before it ever calls the model it runs 3+ heavy sequential service queries, and the REST entry resolves a patient UUID→pid through a 3-query `PatientService` search (P-4 / P-5). The REST path also holds the **session write-lock** for the entire external call, serializing concurrent requests (P-2). `patient_data.pubpid` — the very column the sample generator populates — has **no index** and will table-scan at volume (P-6). These are the hard latency floors the agent must design around: expect ≥1s best case, 2–15s typical, 30s worst.

**Architecture.** New capabilities integrate cleanly through the modern service layer plus a REST route (with an event-based alternative via `RestApiCreateEvent`) or through custom modules. The AI feature follows this pattern well on the service/REST side, but hooks the UI as inline HTML/JS in `demographics.php` rather than the event/card system — a maintainability smell not to repeat.

**Data quality.** The sample dataset is internally clean and referentially intact (no duplicates, no orphan records, valid formats), but has systematic gaps that are direct **agent failure modes**: SSN and primary-provider are 100% empty, `sex` is binary-only, and phone/email formats are unrealistically uniform. Because it is synthetic, it will not surface the dirty-data problems real records carry — the agent's eval suite must inject those deliberately.

**Bottom line:** the agent's trust, speed, and compliance posture all hinge on fixing the AI PHI path — BAA/ZDR gating, disclosure auditing, correct authorization scope, response caching, and async execution — *before* scaling it into a Clinical Co-Pilot.

---

## 2. Security Audit

Covers authentication, authorization, data-exposure vectors, PHI handling, and HIPAA-relevant gaps.

### 2.1 Authentication — generally strong

| Area | Finding | Severity | Evidence |
|------|---------|----------|----------|
| Login flow | Multi-stage: password → optional MFA → session + CSRF key | Info | `library/auth.inc.php:29-63`; `interface/main/main_screen.php:143-416` |
| Password hashing | Configurable `gbl_auth_hash_algo`; default `PASSWORD_DEFAULT` (bcrypt/argon2); legacy migration via `password_verify`/`hash_equals` | Low (+) | `src/Common/Auth/AuthHash.php:36-52,187-224` |
| MFA / 2FA | TOTP + U2F supported; secrets encrypted at rest — **but optional**, only enforced if a user has registrations | Medium | `src/Common/Auth/MfaUtils.php:22-24,167`; `interface/main/main_screen.php:171` |
| Brute-force | Per-user (default 20) and per-IP (default 100) counters + timing-attack mitigation; **can be disabled by setting limits to 0** | Low (+) | `src/Common/Auth/AuthUtils.php:1163-1230`; `library/globals.inc.php:2202-2227` |
| OAuth2 / JWT | League OAuth2 resource server, scope enforcement, SMART scopes; password grant exists (documented non-production) | Low | `src/RestControllers/Authorization/BearerTokenAuthorizationStrategy.php:327+`; `src/RestControllers/Subscriber/AuthorizationListener.php:134-192` |

### 2.2 Authorization — consistent, with an AI-specific gap

- **ACL model:** gacl-based RBAC via `AclMain::aclCheckCore($section,$value,$user)` (`src/Common/Acl/AclMain.php:166+`); superuser bypass at `:174-176`.
- **REST coverage:** 97 of 98 standard routes call `RestConfig::request_authorization_check()`; the one exception (`POST /api/background_service/$run`) is documented and still requires authentication (`apis/routes/_rest_routes_standard.inc.php:712-723`).

**Finding S-3 (High) — AI feature under-scoped.** The AI summary reads clinical history (encounters, social history, active problems) but authorizes with `patients/demo` (demographics) rather than `patients/med` (medical records) used by comparable endpoints:

```106:110:apis/routes/_rest_routes_standard.inc.php
    "GET /api/patient/:puuid/history_summary" => function ($puuid, HttpRestRequest $request) {
        RestConfig::request_authorization_check($request, "patients", "demo");
        $return = (new PatientHistorySummaryRestController())->getSummary($puuid, $request);
```

The UI endpoint has the same mismatch (`interface/patient_file/summary/ai_history_summary.php:31`), while e.g. the allergy endpoint requires `patients/med` (`_rest_routes_standard.inc.php:272-274`). A demographics-only user can trigger AI summarization of medical history — a minimum-necessary / least-privilege violation.

### 2.3 Data exposure vectors

| Finding | Severity | Evidence |
|---------|----------|----------|
| **S-4 API exception messages returned to clients** — raw `$e->getMessage()` in JSON error bodies can leak paths/SQL fragments | **High** | `apis/dispatch.php:41-44`; `src/RestControllers/Subscriber/ExceptionHandlerListener.php:50-54` |
| Dynamic SQL column building in patient finder from `$_GET['sColumns']` (mitigated by `escape_sql_column_name()` but relies on escaping, not allowlist) | Medium | `interface/main/finder/dynamic_finder_ajax.php:54,217-259` |
| Core session cookie `HttpOnly=false` and `Secure=false` by default (intentional for multi-tab UX; increases XSS/session-theft surface) | Medium | `src/Common/Session/SessionConfigurationBuilder.php:26-27,83-90`; `src/Common/Session/SessionUtil.php:7-17` |
| `.env` protection depends on web-server config; no in-repo root `.htaccess` deny rule | Medium | `.gitignore:5`; only 9 `.htaccess` files, none at project root |
| SQL injection generally mitigated by parameterized `sqlStatement($sql,[$binds])`; residual legacy string-concat exists | Low–Medium | `library/forms.inc.php:94`; commented example `interface/usergroup/usergroup_admin.php:519-530` |
| XSS helpers sound (`text()`/`attr()` in `library/htmlspecialchars.inc.php`); AI UI uses safe `textContent` | Low (+) | `interface/patient_file/summary/demographics.php:1115-1116` |
| CSRF: HMAC tokens; enforced on AI UI endpoint | Low (+) | `src/Common/Csrf/CsrfUtils.php`; `ai_history_summary.php:27` |

### 2.4 PHI handling & HIPAA-relevant gaps

| Finding | Severity | Evidence |
|---------|----------|----------|
| **S-1 AI feature transmits clinical PHI to OpenAI with no programmatic BAA gate** (warnings are code comments only), no data-retention control, no runtime enable/disable | **Critical** | `src/Services/AI/PatientHistorySummaryService.php:83-86`; `src/Services/AI/OpenAiChatClient.php:10-12,52-60`; `.env.example:8` |
| **S-2 No audit-trail entry for AI PHI disclosure** — no `EventAuditLogger` calls anywhere in the AI service/controller/UI | **High** | `src/Services/AI/*`, `ai_history_summary.php` (absence) |
| **S-6 `patient_data` / clinical tables not encrypted at the application layer** — `database_encryption` global exists but `CryptoGen` only covers code paths that call `encryptForDatabase` (not patient demographics) | Medium | `src/Common/Crypto/CryptoGen.php:65-67`; `library/globals.inc.php:1028-1032` |
| Encryption in transit to OpenAI is HTTPS (positive); OAuth/API cookies use `Secure=true` | Low (+) | `OpenAiConfig.php:27,57-59` |
| Audit logging exists and defaults on, but is configurable/disable-able and does not cover AI events | Low–Medium | `src/Common/Logging/EventAuditLogger.php:672-694`; `library/globals.inc.php:2778-2789` |
| Minimum-necessary: AI prompt deliberately excludes name/DOB but sends broad clinical context (encounter reason, social history, all active problems) | Medium | `PatientHistorySummaryService.php:12-13,98-155` |
| No AI-specific data-retention/deletion — OpenAI-side copies governed entirely by provider policy | High | (absence of retention code) |

### 2.5 Security recommendations (not implemented)

1. Gate the AI feature behind a runtime config flag; require a confirmed OpenAI BAA + zero-retention before enabling in production.
2. Raise AI authorization to `patients/med` (or a dedicated ACO) on both UI and REST paths.
3. Add `EventAuditLogger` entries for every AI summary request (user, patient UUID/pid, timestamp, data categories sent).
4. Stop returning `$e->getMessage()` in API error responses (`dispatch.php`, `ExceptionHandlerListener.php`).
5. Set `cookie_secure` for core sessions in HTTPS deployments; add root web-server deny rules for `.env`/`vendor/`.
6. Document DB-level (TDE) encryption as a HIPAA at-rest requirement for `patient_data`.

---

## 3. Performance Audit

Covers where the system is slow, bottlenecks, how the data is structured, and constraints on AI-agent response latency.

### 3.1 Data structure & indexing

Query execution runs primarily through the **legacy ADODB surface** (`library/sql.inc.php` → `QueryUtils`), with Doctrine DBAL used for migrations/audit but **not** on the hot read path (`PatientService`, `EncounterService`, and the AI services all use ADODB/`QueryUtils`).

Index coverage on common lookup columns is mostly good, with one notable gap:

| Table | Column | Indexed? | Evidence |
|-------|--------|----------|----------|
| `patient_data` | `pid` | ✅ UNIQUE | `sql/database.sql:8467` |
| `patient_data` | **`pubpid`** | ❌ **none** | column `:8379`; keys only `:8467-8471` |
| `lists` | `pid` | ✅ | `sql/database.sql:7709` |
| `form_encounter` | `pid`,`date` | ✅ composite + date | `sql/database.sql:2060` |
| `prescriptions` | `patient_id` | ✅ | `sql/database.sql:8749` |
| `procedure_order` | `patient_id` | ✅ | `sql/database.sql:10408-10409` |
| `history_data` | `pid` | ✅ | `sql/database.sql:3009` |

**P-6 (High at scale):** external-ID lookups on `patient_data.pubpid` (the column the sample generator populates) will table-scan without an index.

### 3.2 Query anti-patterns in the service layer

| Finding | Severity | Evidence |
|---------|----------|----------|
| **P-5 `PatientService::search()/getOne()` runs 3 SQL round-trips** (COUNT DISTINCT uuids → fetch uuids → full multi-join hydration incl. `patient_data.*`). The AI REST controller pays this just to resolve a UUID→pid. | **High** | `src/Services/PatientService.php:420-423,504-516`; `PatientHistorySummaryRestController.php:42` |
| **P-7 `getEncountersForPatientByPid()` has no LIMIT** — fetches all encounters | High | `src/Services/EncounterService.php:679-681` |
| **P-8 `QueryUtils::escapeTableName()` runs `SHOW TABLES`** on invocation | High | `src/Common/Database/QueryUtils.php:50-55` |
| `EncounterService::getMostRecentEncounterForPatient()` uses the full ~15-join `search()` even for LIMIT 1 | Medium–High | `EncounterService.php:127-133` |
| `PatientIssuesService::getActiveIssues()` unbounded + LEFT JOIN | Medium | `PatientIssuesService.php:89-99,173-211` |
| `SocialHistoryService::getHistoryData()` defaults to `SELECT *` on a wide (~30 longtext col) table; AI uses only 4 fields | Medium | `SocialHistoryService.php:43-57` |
| `BaseService` query-in-loop (`splitAndProcessMultipleFields`, per-code `addCoding`) | Medium | `BaseService.php:587-589,559-564` |

### 3.3 Bottlenecks & infrastructure

| Area | Finding | Severity | Evidence |
|------|---------|----------|----------|
| Application cache | **No Redis/APCu application-data cache**; only file-based GACL cache + optional Redis *sessions* | High (for AI) | GACL `src/Gacl/Gacl.php:309-311`; Redis only in `src/Common/Session/Predis/*` |
| Twig cache | Compiled-template cache **disabled** (no `cache` option) | Low–Medium | `src/Common/Twig/TwigContainer.php:70` |
| Session locking | Core avoids lock via read-only sessions, but **REST forces a writable session** (`$sessionAllowWrite = true`), so the OpenAI call holds the write-lock and serializes concurrent same-session requests | High (REST) | `src/RestControllers/Subscriber/SiteSetupListener.php:189-190`; `SessionUtil.php:37-46` |
| Bootstrap cost | `interface/globals.php` (~863 lines) loads per request: autoloader, Kernel/DI, DB connect, **full `globals` table scan**, auth check, module discovery, HTTP audit-log write | High | `interface/globals.php:450-453,725-727,748-753,847-849` |
| Per-request auth | `authCheckSession()` + `session_tracker` update hit the DB on every authenticated request | Medium | `src/Common/Auth/AuthUtils.php:841-846`; `src/Common/Session/SessionTracker.php:85` |
| SQL audit | Standard `Execute(..., true)` logs every query | Medium | `QueryUtils.php:222` |

### 3.4 Constraints on AI-agent response latency

The AI history-summary request is **fully synchronous and sequential**:

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

1. **Blocking OpenAI round-trip, 30s timeout, not env-tunable** — `OpenAiConfig.php:28` (`DEFAULT_TIMEOUT_SECONDS = 30.0`; `fromEnvironment()` reads only `OPENAI_API_KEY`/`OPENAI_MODEL`); `OpenAiChatClient.php:49-60`. HTTP client is synchronous Guzzle via `oeHttp` (`src/Common/Http/oeHttpRequest.php:170`).
2. **No summary caching** — `PatientHistorySummaryResult` is a transient DTO; identical re-requests repeat all DB + OpenAI work (`src/Services/AI/PatientHistorySummaryResult.php`).
3. **3+ sequential DB queries before the model call**, several over-fetching (full joins / `SELECT *`) for a handful of fields.
4. **REST UUID→pid resolution** adds the 3-query `PatientService` pipeline.
5. **Full `globals.php` bootstrap per call**; the demographics UI pays it twice (page load + AJAX endpoint).
6. **REST session write-lock** held for the entire external call, blocking concurrent agent actions in the same session.
7. **No async/queue/worker** — the caller must block for the whole PHP request lifecycle; there is no job-id + poll pattern.

**Practical expectation:** ≥1 s best case (new patient / no OpenAI call), typically 2–15 s when the model is invoked, 30 s worst-case timeout.

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

**Two bootstrap chains:**
- **Web UI:** `interface/*.php` → `interface/globals.php` (Kernel, DB, globals→`OEGlobalsBag`, modules, session).
- **REST API:** `apis/.htaccess` → `apis/dispatch.php` → `ApiApplication` → `OEHttpKernel` (Symfony) → route finders → `HttpRestRouteHandler::dispatch()` → controller → service. There is **no** `public/api` directory.

**Modern ↔ legacy bridging** is pervasive (not a clean boundary): modern services `require_once` legacy includes (`BaseService` → `custom/code_types.inc.php`), both layers share `$GLOBALS`/`OEGlobalsBag`, and `QueryUtils` wraps the same ADODB connection opened by `library/sql.inc.php`.

### 4.2 Where data is stored

| Store | Role | Evidence |
|-------|------|----------|
| **MySQL/MariaDB** | System of record — all clinical, admin, module, API metadata | `library/sql.inc.php`; `src/Common/Database/QueryUtils.php` |
| Filesystem `sites/<site>/documents/` | Default binary document storage | `library/classes/Document.class.php:1054+` |
| **CouchDB** (optional) | Alternate document *binary* store (metadata stays in MySQL `documents`) via `document_storage_method` | `library/classes/CouchDB.class.php`; `Document.class.php:999-1028` |
| **LDAP/AD** (optional) | Authentication only, not a data store | `src/Common/Auth/AuthUtils.php:871,898` |
| **Redis** (optional) | Session storage in prod/K8s; not an app cache; not in `development-easy` | `src/Common/Session/Predis/*` |
| Elasticsearch | **Not used by core** (only an optional Monolog log handler dep) | `composer.lock` |

The Docker `development-easy` stack additionally runs Mailpit (dev SMTP capture) and Selenium (E2E only).

### 4.3 How layers interact

- **Event system:** Symfony `EventDispatcherInterface` owned by `src/Core/Kernel.php`, shared with modules. 82 event classes in `src/Events/` (`RestApiCreateEvent`, `RestApiSecurityCheckEvent`, `ServiceSaveEvent`, demographics `RenderEvent`/`SectionEvent`, `ModuleLoadEvents`, …).
- **Service container:** two overlapping mechanisms — `OpenEMR\Core\Kernel` (Symfony `ContainerBuilder`, holds dispatcher) and the transitional static locator `OpenEMR\BC\ServiceContainer` (`getLogger()`, `getCrypto()`, PSR-17 factories). A full PSR-11 DI container is explicitly a not-yet-done goal (per `ServiceContainer` and `RoutesExtensionListener` comments).
- **Typical flow:** route closure → `RestConfig::request_authorization_check()` → RestController → Service → `QueryUtils` → MySQL.
- **Globals/session as service locator:** `$GLOBALS`/`OEGlobalsBag`, `$_SESSION`/`SessionUtil`, and `OEGlobalsBag::getKernel()` are read throughout; `new Service()` is still common instead of injection.

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

End-to-end: `.env` (`OPENAI_API_KEY`, optional `OPENAI_MODEL`) → `OpenAiConfig` → `OpenAiChatClient` (HTTP via `oeHttp`) ← `PatientHistorySummaryService` (reuses `EncounterService`, `SocialHistoryService`, `PatientIssuesService`; returns `PatientHistorySummaryResult`). Two entry points share the same service:
- **REST:** `GET /api/patient/:puuid/history_summary` → `PatientHistorySummaryRestController` (UUID→pid, maps `OpenAiException`→HTTP 502).
- **UI:** a button + `loadAiHistorySummary()` inlined in `demographics.php:1091-1125` → AJAX to `ai_history_summary.php` (globals + CSRF + ACL, reads pid from session).

**Architectural note:** the service/REST wiring follows the recommended modern pattern; the UI hook is ad-hoc inline HTML/JS rather than the `RenderEvent`/`SectionEvent` card pattern used elsewhere on the same page — simpler, but less modular.

---

## 5. Data Quality Audit

Assessed against the live dev database: **1,000 generated sample patients** (`pubpid LIKE 'SMPL%'`), which are 100% of the `patient_data` table. Covers missing fields, inconsistent formatting, duplicate records, and stale data.

### 5.1 Completeness — missing fields

| Field | Missing/empty (of 1,000) | Assessment |
|-------|--------------------------|------------|
| fname, lname, DOB, sex, race, ethnicity | 0 | Complete |
| street, city, state, postal_code, phone_home, email | 0 | Complete |
| status (marital), title | 0 | Complete |
| **`ss` (SSN)** | **1,000 (100%)** | **Gap** — never populated by the generator |
| **`providerID` (primary/PCP)** | **1,000 (100%)** | **Gap** — no primary provider assigned on `patient_data` (encounters do reference a provider) |

Referential completeness is otherwise intact: 0 patients without an encounter, 0 without a `history_data` row, 0 encounters missing `provider_id`, 0 problems missing a diagnosis code, 0 prescriptions with an empty drug.

### 5.2 Consistency — formatting

| Check | Result | Note |
|-------|--------|------|
| Phone format | 1 pattern for all 1,000: `(NNN) NNN-NNNN` | Internally consistent, but unrealistically uniform |
| Email validity | 0 invalid (all `first.lastNNN@example.com`) | Single reserved domain — uniform |
| DOB format | 0 not `YYYY-MM-DD` | Consistent |
| Postal code | 0 with length ≠ 5 | Consistent |
| Names | 0 with non-alpha characters | Consistent |
| `sex` values | `Male`=514, `Female`=486 | **Binary only** — no `Unknown`/other/declined values |
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

The synthetic dataset is **internally clean, consistent, and referentially intact** — well suited for demos and for exercising the AI summary path. Its "issues" are therefore mostly *representativeness* gaps rather than dirty data:

- **Real gaps to close in the generator:** populate `ss` and `providerID`; include non-binary/`Unknown` `sex` values.
- **Realism caveats:** uniform phone/email formats and a single `@example.com` domain mean this dataset will **not** surface the formatting inconsistencies, duplicate/merge candidates, null-heavy records, or stale-data problems that a real patient population exhibits. When production or migrated data is loaded, re-run these same checks — the absence of dirty data here is a property of the generator, not evidence the system enforces data quality (the schema/app layer imposes few such constraints).

---

## 6. Compliance & Regulatory Audit

Assesses HIPAA Security Rule (45 CFR §164.302–318), Privacy Rule (§164.500+), and Breach Notification Rule (§164.400–414) obligations against what the codebase actually implements, with particular attention to the AI/LLM PHI path. This maps the technical findings in §2 to regulatory duties; it is a compliance-readiness assessment, **not legal advice**.

### 6.1 Audit logging requirements

**Regulatory basis:** HIPAA §164.312(b) (Audit controls) and §164.308(a)(1)(ii)(D) (Information system activity review) require mechanisms to record and examine activity in systems containing ePHI.

**What OpenEMR implements (strong baseline):**

| Capability | Implementation | Evidence |
|------------|----------------|----------|
| Central audit logger | `EventAuditLogger::newEvent()` writes to `log` + `log_comment_encrypt` | `src/Common/Logging/EventAuditLogger.php:187,405,700` |
| **Tamper-evidence** | SHA3-512 checksum of each entry (and a separate API checksum) stored per row | `src/Common/Logging/Audit/LogTablesSink.php:63-94` |
| Configurable event categories | patient-record, scheduling, order, lab-order, lab-results, security-administration, query, http-request, cdr, backup, other | `EventAuditLogger.php:71-80`; `library/globals.inc.php:2785-2845` |
| Standards-based remote audit | **IHE ATNA** sink (TLS syslog to an external audit repository) | `EventAuditLogger.php:49-65`; `src/Common/Logging/Audit/AtnaSink.php`; globals `:2858-2886` |
| Break-glass emergency access | Interface + checker for logging override access | `src/Common/Logging/BreakglassChecker.php`, `BreakglassCheckerInterface.php` |
| Authentication events | Login success/failure and API token events are audited | `AuthUtils.php:400,489`; `BearerTokenAuthorizationStrategy.php:299,316` |

**Compliance gaps:**

| Gap | Severity | Detail |
|-----|----------|--------|
| **C-1 AI PHI disclosure is not audited** | High | No `EventAuditLogger` call exists in `src/Services/AI/*`, `PatientHistorySummaryRestController`, or `ai_history_summary.php`. A disclosure of PHI to a third party (OpenAI) leaves **no record** of who requested it, for which patient, when, or what data categories were sent — directly undermining §164.312(b) and the §164.528 accounting-of-disclosures duty. |
| Audit logging is disable-able | Medium | `enable_auditlog` and each `audit_events_*` category can be turned off (`library/globals.inc.php:2778-2845`); a misconfiguration silently removes the activity record. |
| No log-review workflow / alerting in core | Medium | The system records events but provides limited built-in periodic-review or anomaly-alerting tooling (§164.308(a)(1)(ii)(D) expects active review, not just capture). ATNA export to a SIEM is the intended path but is optional and off by default. |

**Required for AI compliance:** every AI summary request must emit an audit event capturing user, patient UUID/pid, timestamp, destination (OpenAI), model, and the categories of PHI transmitted — ideally as a §164.528-accountable disclosure.

### 6.2 Data retention policies

**Regulatory basis:** HIPAA §164.316(b)(2) requires retention of Security-Rule *documentation* (including audit records/policies) for **6 years**. HIPAA itself sets **no** medical-record retention period — that is governed by **state law** (commonly 6–10 years for adults; longer for minors, e.g., until age of majority + N years) and by CMS/payer rules.

**What OpenEMR implements:**

| Aspect | Finding | Evidence |
|--------|---------|----------|
| **No automated retention/purge** | No retention window, TTL, archival, or purge job exists for `log`, `log_comment_encrypt`, or clinical tables. Grep for retention/purge/prune/`days_to_keep` across logging code and globals returned nothing. | (absence across `src/Common/Logging/*`, `library/globals.inc.php`) |
| Records grow indefinitely | Audit and clinical data persist until manually removed; retention is entirely an **operational/DBA responsibility**, not enforced by the application. | — |
| Deletion is largely soft/manual | Patient/record deletion is administrative; there is no policy-driven lifecycle for stale data. | — |
| **No AI/LLM-side retention control** | Nothing purges or governs data once sent to OpenAI; retention is dictated solely by the provider's policy (default OpenAI API retention is up to 30 days unless a zero-data-retention agreement is in place). | `src/Services/AI/OpenAiChatClient.php` (no retention handling) |

**Compliance gaps:**

- **C-2 (Medium):** absence of an enforced retention schedule means the operator must implement retention externally to satisfy §164.316(b)(2) (6-year audit retention) and applicable state medical-record retention law. The application will neither retain-for-minimum nor purge-after-maximum on its own.
- **C-3 (High):** for the AI path, PHI leaves the retention perimeter entirely. Without a contractual zero-retention (ZDR) BAA, transmitted PHI may persist on the provider's systems beyond the covered entity's control and outside its retention/destruction policy (§164.310(d)(2)(i) media disposal cannot be honored for that copy).

### 6.3 Breach notification obligations

**Regulatory basis:** Breach Notification Rule §164.400–414. An impermissible use/disclosure of unsecured PHI is presumed a breach unless a §164.402(2) four-factor risk assessment shows low probability of compromise. Duties: notify affected individuals **without unreasonable delay, ≤60 days** (§164.404); notify HHS (§164.408 — immediately if ≥500 individuals, else annually); notify media if ≥500 in a state/jurisdiction (§164.406); and Business Associates must notify the Covered Entity (§164.410).

**Assessment against this system:**

| Consideration | Finding | Severity |
|---------------|---------|----------|
| Detection capability | Tamper-evident checksums (§6.1) support integrity verification, and ATNA/audit logs can evidence access — **good** raw material for breach investigation, *if* enabled and reviewed. | Low (+) |
| **C-4 AI disclosure = potential reportable event** | Sending PHI to OpenAI **without a BAA** is an impermissible disclosure to a third party. If it does not qualify for a low-probability-of-compromise determination, it is a reportable breach — and because AI calls are unaudited (C-1), the covered entity cannot even scope *which* patients were affected. | High |
| No built-in breach-notification workflow | Core provides no incident-tracking, affected-individual enumeration, or notification tooling; this is expected to be operational, but the unaudited AI path makes the "identify affected individuals" step (§164.404(c)) impractical. | Medium |
| "Unsecured PHI" framing | PHI at rest in `patient_data` is not encrypted at the application layer (§2.4, S-6), so it is **not** rendered "unsecured-exempt" by the HHS encryption safe harbor; a DB-level breach would be reportable unless full-disk/TDE encryption is in place. | Medium |

**Key point:** breach *readiness* depends on (a) enabling and reviewing audit logs, (b) auditing the AI disclosure path so affected individuals can be enumerated, and (c) securing PHI at rest to qualify for the encryption safe harbor.

### 6.4 Business Associate Agreement (BAA) implications of sending PHI to an LLM provider

**Regulatory basis:** §164.502(e) and §164.308(b) require a BAA with any entity that creates, receives, maintains, or transmits PHI on behalf of a covered entity. §164.504(e) specifies mandatory BAA terms. An LLM provider (OpenAI) that receives PHI **is a Business Associate** and requires a signed BAA before any PHI transmission.

**What the code does today:**

- The AI feature transmits clinical PHI (encounter reason/date, social history, active problem list) to `https://api.openai.com/v1/chat/completions` (`PatientHistorySummaryService.php:83-86`; `OpenAiChatClient.php:52-60`; `OpenAiConfig.php:26-28,57-59`).
- It **minimizes identifiers** — name and DOB are deliberately excluded (`PatientHistorySummaryService.php:12-13`). This reduces, but does **not eliminate**, PHI: clinical facts tied to a retrievable patient record remain PHI, and de-identification to the §164.514(b) standard is not performed.
- The **only** BAA control is a code comment / `.env.example` note (`OpenAiChatClient.php:10-12`; `.env.example:8`) — there is **no runtime gate** that checks for a BAA or blocks PHI transmission when one is absent.

**BAA compliance requirements (must all hold before production PHI use):**

| Requirement | Status | Reference |
|-------------|--------|-----------|
| Signed BAA with the LLM provider covering PHI | **Not enforced** (comment only) | `OpenAiChatClient.php:10-12` |
| Zero-Data-Retention / training opt-out (no use of PHI for model training) | Not verified in code; depends on provider agreement | — |
| Subcontractor flow-down (provider's own subprocessors bound) | Operational — not addressable in code | §164.308(b)(1) |
| Disclosure limited to minimum necessary | Partial — identifiers stripped, clinical context broad | §164.502(b); `PatientHistorySummaryService.php:98-155` |
| Accounting of disclosures possible | **No** — disclosures are unaudited (C-1) | §164.528 |
| Ability to disable if BAA lapses | **No runtime kill-switch** | `OpenAiConfig::fromEnvironment()` only checks key presence |

**Findings:**

- **C-5 (Critical):** PHI is transmitted to a third-party LLM with **no programmatic BAA enforcement** and no runtime control to block transmission when a BAA/ZDR agreement is not in place. This is the same underlying issue as S-1, viewed through the §164.502(e) BAA lens.
- **C-6 (High):** even with a BAA, the absence of disclosure auditing (C-1) prevents satisfying the §164.528 accounting-of-disclosures requirement for PHI sent to the LLM.

**Recommended controls (not implemented):**

1. Add a required runtime config flag (e.g., `OPENAI_BAA_CONFIRMED=true` plus `OPENAI_ZERO_RETENTION=true`) that must be set before any PHI is sent; fail closed otherwise.
2. Emit a §164.528-grade audit/disclosure record on every AI call (see §6.1).
3. Prefer a HIPAA-eligible deployment (e.g., a provider offering a signed BAA and ZDR, or an in-VPC/self-hosted model) and document it in the security-documentation retained for 6 years.
4. Consider stronger de-identification (§164.514) so the payload is not PHI, which would remove the BAA requirement for that path.
5. Maintain a data-processing inventory listing OpenAI as a Business Associate, the PHI categories sent, and the governing BAA.

---

## 7. Consolidated Risk Register

| ID | Audit | Finding | Severity |
|----|-------|---------|----------|
| S-1 | Security | AI sends clinical PHI to OpenAI without BAA enforcement / retention control | **Critical** |
| C-5 | Compliance | No programmatic BAA/ZDR gate before transmitting PHI to the LLM (§164.502(e)) | **Critical** |
| P-1 | Performance | Synchronous, uncached, 30s-timeout OpenAI call on the request path | **Critical (UX)** |
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
| A-1 | Architecture | No full DI container; `new Service()` + static locators | Medium |
| A-2 | Architecture | AI UI hook inlined in `demographics.php` vs event/card pattern | Low |
| DQ-2 | Data Quality | Uniform formats / single email domain — not representative of real data | Low (info) |

---

*Prepared from static code review and live queries against synthetic sample data only. Runtime configuration, deployment hardening, and infrastructure controls were not tested. The Compliance & Regulatory section maps implemented controls to HIPAA obligations and is a compliance-readiness assessment, not legal advice. No code or data was modified in the course of this audit.*
