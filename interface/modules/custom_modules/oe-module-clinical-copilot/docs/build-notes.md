# Clinical Co-Pilot — Build Notes (shared context for all build units)

This file is the **single source of shared engineering context** for building the
`oe-module-clinical-copilot` module. Every build unit reads this first, then its
own unit brief. Keep it accurate; if a host API differs from what's written here,
fix this file.

## What we are building

An **additive** OpenEMR custom module: a pre-visit clinical synthesis co-pilot for
one outpatient endocrinologist. Two surfaces over one deterministic fact layer:
(1) a pre-warmed, cited pre-visit synthesis per scheduled patient; (2) a multi-turn
chat agent pinned to a patient. **The LLM narrates and navigates — it never extracts.**

**Authoritative specs (read the relevant sections for your unit):**
- `USERS.md` (repo root) — target user, UC1–UC6 (source of truth).
- `ARCHITECTURE.md` (repo root) — agent layer: chat §1, verification §2, observability §3, auth/PHI §4, failure model §6.
- `ARCHITECTURE_COMPLETE.md` (repo root) — fact object schema, capabilities, lab contract C1–C4, invariants I1–I13, module tables, **build units U1–U13** (the table of owned files + acceptance criteria).
- `docs/clinical-copilot-tradeoffs.md` (repo root) — decision record T1–T21.

## Non-negotiable invariants (from ARCHITECTURE_COMPLETE.md — do not violate)

- **I2** facts never cached; only narratives cached. **I3/E7** doc & trace stores append-only (no UPDATE/DELETE in code). **Documented carve-out (T22):** `mod_copilot_doc`'s advisory QA-annotation columns (`qa_status`, `qa_score`) are the one exception — `DocQaAnnotator` performs a single, monotonic `pending → scored` UPDATE of *those columns only*, never touching the served content (`doc` facts+narrative, `verify_status`, citations). "What the physician was shown" stays immutable; only the post-hoc advisory QA verdict is stamped on. This is a deliberate, narrow exception recorded here so the append-only invariant's intent (an untamperable provenance ledger of shown content) still holds. Alternative considered: store QA verdicts only in `mod_copilot_qa` and LEFT JOIN in `findBest` — cleaner against a strict reading of I3, deferred as it complicates the hot read path; revisit if the ledger's immutability is ever audited literally.
- **I5** no silent exclusion — every filtered row becomes a visible `exclusion` fact with citations + reason.
- **I6/I11** degradation absolute — LLM unavailable ⇒ facts + "narrative unavailable"; no unverified prose ever rendered.
- **I9 additivity** — ZERO diff outside `oe-module-clinical-copilot/` (spec docs are the only exception). No writes to any core OpenEMR table, ever. Module-owned `mod_copilot_*` tables only.
- **I10** patient pinning structural — no tool accepts a patient id; executor injects session pid; every fact's pid asserted on return AND re-verified on output (V3).
- **I12** every invocation leaves a trace (correlation id on every span/row/log).
- **I13** the LLM is I/O-less — it emits structured *requests*; module code validates, pins, executes, returns facts.

## LLM platform (decided — T18) — pin these exactly

- Provider: **Google Gemini on Vertex AI** (HIPAA-eligible under GCP BAA; NOT AI-Studio keys).
- Models: synthesis reduce + chat turns = **Gemini 2.5 Pro** (`gemini-2.5-pro`); advisory second-pass reviewer / LLM-judge = **Gemini 2.5 Flash** (`gemini-2.5-flash`). Version strings are **pinned** and folded into `prompt_version` (a digest input).
- Structured output: Vertex `responseMimeType: application/json` + `responseSchema`.
- Auth: GCP service account via `google/auth` ADC — no API keys in code/config.
- Transport: HTTPS via Guzzle to Vertex REST; certificate verification ON.
- **Synthetic patients only this phase** (OPEN-1). Egress redaction: identifiers → per-session pseudonym tokens before any Vertex call, re-hydrated after verification.
- The module must **degrade cleanly with NO credentials configured** (dev/test default): the LLM client detects missing ADC and reports "unavailable" so synthesis renders facts-only and chat becomes a facts browser. All deterministic logic + tests run with no cloud access.

**T23 (decision of record):** added a **dev/test Gemini API-key fast-path**
(`GeminiApiLlmClient` / `GeminiApiChatLlmClient`, Google AI Studio, keyed by
`CLINICAL_COPILOT_GEMINI_API_KEY`) alongside the Vertex production path, so
the narrated experience can be exercised end to end with synthetic data
before the full Vertex service-account + BAA is provisioned. Both factories
(`LlmClientFactory`, `ChatLlmClientFactory`) select Vertex first if
`CLINICAL_COPILOT_GCP_PROJECT_ID` is set, else the Gemini API-key client if
`CLINICAL_COPILOT_GEMINI_API_KEY` is set, else Unavailable — Vertex always
wins when both are configured. This is **synthetic-data-only, not
HIPAA-eligible, no BAA** (OPEN-1) and does **not** change T18's production
decision: Vertex remains the only path approved for anything resembling
real PHI. Full env-var reference: `docs/configuration.md`.

## Namespace, placement, headers

- Root: `interface/modules/custom_modules/oe-module-clinical-copilot/`
- PSR-4: `OpenEMR\Modules\ClinicalCopilot\` → `src/`
- Every PHP file: `declare(strict_types=1);` after the docblock. PER-CS, 4-space indent.
- File header docblock (use verbatim, adjust @author line only if a file has a prior author):

```php
/**
 * <Brief description>
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
```

## Host APIs — exact signatures (verified against source; use these, do not guess)

**DB — `OpenEMR\Common\Database\QueryUtils`** (static):
- `fetchRecords(string $sql, array $binds = [], bool $noLog = false): array` — SELECT → list of assoc rows.
- `querySingleRow(string $sql, array $params = [], bool $log = true)` — single row assoc.
- `fetchSingleValue(string $sql, string $column, array $binds = [])` — scalar.
- `sqlInsert(string $sql, array $binds = []): int` — INSERT, returns last id.
- `sqlStatementThrowException(string $sql, array $binds = [], bool $noLog = false)` — general stmt, throws on error.
- `startTransaction()/commitTransaction()/rollbackTransaction()`; `inTransaction(callable): mixed`.
- Parameterize with `?`. Never interpolate. Module writes go ONLY to `mod_copilot_*` tables.

**Logging — `OpenEMR\Common\Logging\SystemLogger`** (PSR-3): `new SystemLogger()` then `->error($msg, array $context)`, `->info(...)`, `->debug(...)`. **Never** interpolate variables into the message — use the context array. Always include `correlation_id` in context.

**Audit — `OpenEMR\Common\Logging\EventAuditLogger`**: `EventAuditLogger::getInstance()->newEvent($event, $user, $groupname, $success, $comments = '', $patient_id = null, $log_from = 'open-emr', $menu_item = 'dashboard')` (the class uses `SingletonTrait`, not its own `instance()` method -- verified against src/Common/Logging/EventAuditLogger.php and src/Core/Traits/SingletonTrait.php). `$user`/`$groupname` are the session's `authUser`/`authProvider` strings (see e.g. `interface/modules/custom_modules/oe-module-dorn/src/ReceiveHl7Results.php`), not `authUserID`. For chart-data access use event `patient-record`, action carried in comments with correlation id.

**ACL — `OpenEMR\Common\Acl\AclMain`**: `AclMain::aclCheckCore(string $section, string $value): bool`. Gate every copilot surface with `AclMain::aclCheckCore('patients','med')`. Also register the module's own ACL section (`clinical_copilot`) so a site can grant/deny independently.

**CSRF — `OpenEMR\Common\Csrf\CsrfUtils`**: `CsrfUtils::collectCsrfToken($session, $subject='default'): string` (emit hidden input / header), `CsrfUtils::checkCsrfInput($token, $session, $subject='default')` at top of every POST handler.

**Globals — `OpenEMR\Core\OEGlobalsBag::getInstance()`**: `->getString($k)`, `->getInt($k)`, `->getBoolean($k)`, `->getKernel()`, `->getWebRoot()`, `->getProjectDir()`. Not `$GLOBALS`.

**Session — `SessionWrapperFactory` / `SessionUtil`** for the authed user (`authUserID`). Not raw `$_SESSION`.

**Host clinical services (READ-ONLY use):**
- `OpenEMR\Services\PrescriptionService` — meds; its FHIR MedicationRequest layer already UNIONs `prescriptions` + `lists(type=medication)`. Reuse for MedResponse (T4).
- `OpenEMR\Services\ProcedureService` — procedure orders/reports/results (`activity=1`).
- `OpenEMR\Services\VitalsService` — `form_vitals` (pid-indexed).

**Page bootstrap contract (public/*.php):** set flags (`$ignoreAuth=false`, never set `$sessionAllowWrite` on chat) BEFORE `require_once dirname(__FILE__).'/../../../../globals.php';` (adjust depth), then CSRF → ACL → session identity in that order. Twig autoescape is globally OFF: escape explicitly with `text()`, `attr()`, `xlt()`, `xla()`, `js_escape()` in PHP and `|text |attr |xlt` filters in Twig. PHI only in POST bodies, never URLs/logs/exception messages.

**Background services:** `background_services` row (columns: name, title, active, running, next_run, execute_interval [minutes], function, require_once, sort_order, lock_expires_at). The framework calls `function()` from `require_once` file on logged-in AJAX ticks / cron. A cron entry hitting `library/ajax/execute_background_services.php` every 5 min is a HARD deployment requirement (document it, don't add it to core). Module registers/removes its row in ModuleManagerListener enable/disable/reset — NOT by editing core `sql/database.sql`.

## Module-owned tables (ship in `table.sql`; append-only where noted)

`mod_copilot_doc` (append-only), `mod_copilot_cadence` (config, versioned — module-owned so UPDATE allowed for config only), `mod_copilot_chat_session`, `mod_copilot_chat_turn` (append-only), `mod_copilot_trace` (append-only). Full column lists in ARCHITECTURE_COMPLETE.md "Module-owned tables". Use `#IfNotTable name` / `#EndIf` guards like other modules' install SQL. All must `DROP` cleanly on uninstall (export-before-drop confirmation is the operator's; the SQL just drops `mod_copilot_*`).

## Fact object — the one canonical shape

Every capability output, tool result, digest input, prompt fact, verifier check operates on the single `Fact` shape in ARCHITECTURE_COMPLETE.md ("Fact object"). It ships as **a JSON Schema file** (`src/Fact/schema/fact.schema.json`) beside typed PHP fact objects — the schema is the contract. `fact_id = hash(capability, kind, citations, canonical value)` (value included so a preloaded fact and a corrected re-fetch never collide). `derived_*` facts are computed deterministically by capabilities and cite the raw facts they derive from — this is what lets V4 stay strict while prose says "rose 0.6".

## Testing

- **Isolated (no DB)** — pure logic: fact model, canonical serializer, digest determinism, C3 parsing/censoring, C4 unit conversion, verifier V1–V6 with stub facts, redaction round-trip, prompt-assembly bytes. Put under `tests/` in the module; these are the primary green gate here (host runs them via `composer phpunit-isolated`). Namespace test classes `OpenEMR\Modules\ClinicalCopilot\Tests\`.
- **DB-backed** — LabSlice contract evals, capability known-answer fixtures, digest evals E1–E7, docstore append-only, read-path, chat evals, adversarial evals. Write them even though the full docker stack may not run here; they are part of the deliverable and must be correct.
- **Data providers** carry: `@codeCoverageIgnore Data providers run before coverage instrumentation starts.`
- Every eval documents the failure mode it guards (naming discipline like E1–E7).
- `php -l` must pass on every file. Follow PHPStan level-10 expectations (native types everywhere, no `mixed` unless narrowed, no new baseline entries).

## U12 additions — accuracy agent + telemetry (user decision of record)

The user explicitly wants (a) the advisory accuracy-gauging agent and (b) richer
telemetry to prove the prompts are working. Build these into U12. **Guardrail: the
accuracy agent is ADVISORY, never a blocking gate** — the deterministic verifier
V1–V6 stays the only gate (T15). The agent scores and watches; it never approves.

- **Accuracy agent = post-mortem async QA (user decision of record).** A separate
  **Gemini Flash** instance runs the §2.5 second-pass review as a **post-mortem QA
  pass, decoupled from the serving path** — NOT inline, NOT blocking, zero latency
  on the request. It sweeps recently-served, not-yet-QA'd `mod_copilot_doc` and
  `mod_copilot_chat_turn` rows **on the worker tick** (rides U9's `background_services`
  function; no new daemon), re-reads each rendered answer against that row's stored
  session fact set from scratch, and **logs its verdict to a dedicated append-only
  table `mod_copilot_qa`** (schema below). Idempotent: the sweep skips any target
  already present in `mod_copilot_qa` (unique on target_type+target_id). When ADC/LLM
  is unavailable it writes `status='unavailable'` and moves on — QA lag is acceptable
  and honest (the badge shows "QA pending" until the sweep lands). The inline
  "citations checked" badge (V1–V6) is unchanged and still the deterministic gate;
  the QA verdict is displayed beside it once available, purely advisory.
- **New module table `mod_copilot_qa`** (append-only; U12/U9 adds it to `table.sql`,
  install/uninstall, and the ModuleManagerListener drop list):
  `id (pk) · target_type enum(doc|chat_turn) · target_id · correlation_id (indexed,
  ties to the original request trace) · pid · user_id · model · concurs tinyint ·
  salience_ok tinyint · flags JSON [{claim_ref, class: emphasis|paraphrase|omission|salience|other, note}]
  · density_ratio decimal · fact_utilization_rate decimal · reviewer_note text ·
  tokens_in · tokens_out · cost_usd · status enum(ok|unavailable|error) · created_at ·
  UNIQUE(target_type, target_id)`. This is module-owned so it satisfies additivity;
  it is append-only like the other ledgers (no UPDATE/DELETE in code).
- **Dashboard metrics to add (all from the trace table + the Flash verdict):**
  `reviewer_concurrence_rate`; `salience_score` (reviewer flags a "Salience Failure"
  when a high-priority out-of-range/critical fact is not near the top);
  `narrative_density_ratio` (unique cited clinical entities ÷ narrative length);
  `fact_utilization_rate` / null-data density (% of extracted facts left uncited —
  fluff + capability-tuning signal); `chat_drilldown_rate` (turns needing a tool
  call beyond the preloaded envelope — what the pre-pull couldn't answer). These sit
  beside the already-specced per-check `verification_pass_rate` (V1–V6), cache hit
  rate, p50/p95, cost, and the over-reliance indicators (citation click-through,
  facts-panel opens).
- Deterministic wherever possible: density/utilization/drilldown are pure trace math
  (no LLM). Only concurrence/salience use the Flash verdict, and both are advisory.

## I14 — Extraction conservation / parse-yield telemetry (user decision of record)

Guards **over-stripping**: the deterministic PHP extraction can silently drop a source
entity BEFORE it is ever classified — schema drift (`valueString` vs `valueQuantity`,
a renamed/new key after an OpenEMR update), a join edge — so it never becomes even an
I5 exclusion fact. I5 only accounts for rows the reader *chose* to exclude; it cannot
see a row the reader never counted. I14 closes that gap.

- **Conservation invariant, per capability extraction:**
  `raw_input_rows == presented_facts + exclusion_facts`. Every source row ends as exactly
  one of {presented, excluded-with-reason}. `unaccounted = raw − (presented + excluded)`
  must be **0**. Pure, deterministic, LLM-free.
- **Captured at extraction** (where raw rows are known): each capability's `extract`
  (and the LabSlice reader) counts the raw rows its source query/service returned and
  exposes it on the result — `CapabilityResult.rawInputCount` + `accountedCount`
  (= presented + excluded). LabSlice exposes its raw join-row count so capabilities can
  aggregate it.
- **Telemetry (U12 wires span + metric + alert):** every `extract` trace span records
  `raw_count`, `presented_count`, `exclusion_count`, `unaccounted`. Dashboard metric
  `unaccounted_entity_rate` (parse-yield shortfall). **Alert on any `unaccounted > 0`**
  — a data-shape surprise (§6.3 root-cause class 2): pull the span payload, add the case
  to fixtures BEFORE fixing the mapping.
- **Capability-crash composition:** `unaccounted > 0` does NOT itself abort the synthesis
  (the accounted facts are still valid); it flags the mapping bug loudly. A capability
  that *throws* still follows the §6.1 capability-crash rule (no digest, no ledger write).
- **Tested:** a conservation eval per capability — inject a raw row with an unmapped
  shape; assert it surfaces as an exclusion (accounted) OR trips `unaccounted>0`, never
  silently vanishes.

Owner: **U5** adds `rawInputCount`/`accountedCount` to `CapabilityResult` (and exposes
the LabSlice raw count); **U12** wires the span field, the `unaccounted_entity_rate`
metric, and the alert.

## Warm timing + QA-driven rerun (T22 — user decision of record)

**Warm earlier, leave a QA buffer.** Each appointment's synthesis must be generated
and ready by **T‑30min** (keep the T‑12h and T‑1h full-window passes; the 5‑min tick
must ensure every appointment is warmed AND QA-swept by ~T‑30min). The 30-minute
buffer exists so the post-mortem QA sweep can score the doc and, if it scores low,
trigger a bounded automatic regeneration BEFORE the physician opens the chart.

**QA-driven auto-rerun — synthesis path only (safe because the synthesis is
disposable/idempotent, T21).** On the worker tick, after QA writes a verdict to
`mod_copilot_qa`: if `concurs=false` OR `salience_ok=false` (below a versioned
threshold in `mod_copilot_cadence` config) AND now is before ~T‑5min AND the per-tick
LLM budget + circuit breaker allow (§3.7), enqueue ONE regeneration of that
`(pid, digest)`. **The rerun REUSES the already-pulled fact set — it re-extracts
nothing.** The canonical facts are persisted in the low-scored attempt's
`mod_copilot_doc.doc` JSON (`facts + citations`), so the rerun reads that snapshot and
re-runs ONLY reduce+verify (redaction → LLM → V1–V6), appending the new attempt for the
same digest. This does not violate I2 (facts-never-cached governs the SERVE/read path,
which still recomputes fresh; replaying a stored, already-addressed snapshot to re-roll
its narrative is provenance replay, not serving stale facts).
**Freshness guard (keeps I1 honest):** before re-narrating, recompute the current
digest (the tick does this anyway — cheap, LLM-free). If it still equals the low-scored
doc's digest → reuse the stored facts as above. If it has drifted → skip the QA rerun
and do a normal fresh warm for the new digest instead (re-rolling a stale snapshot would
yield a doc wrong on arrival; content-addressing retires the old one). **Bounded:
max 2 QA-driven reruns per (pid, digest)**; after that stop and let the QA /
verification-failure alert (§3.5) surface it as a prompt/model regression — never an
unbounded loop. Reruns respect the breaker: a QA-fail storm degrades warm coverage,
never blows the cap (I7).

**Physician manual Regenerate (already §6.1 — keep and surface it).** The doc page
always shows a **Regenerate** button; the physician can force a fresh attempt anytime,
independent of the QA loop. Free by construction (append-only, idempotent).

**Schema change — `mod_copilot_doc` (U6 DocStore owns the `table.sql`/install edit +
selection logic; U9 owns the warm-by-T‑30min + rerun loop; U12 owns the QA threshold
+ enqueue):** relax so a fact set carries multiple candidate narratives (best-of-N):
- DROP `UNIQUE(pid, fact_digest)`; add non-unique index `(pid, fact_digest, id)`.
- Add columns: `qa_status enum(pending|ok|low|unavailable) default 'pending'`,
  `qa_score decimal(4,3) null`, `regen_reason enum(none|qa_low|manual|verify_retry)
  default 'none'`, `verify_status enum(passed|degraded) not null default 'passed'`.
- **Serve-selection rule (still NO LLM on read — lookup cost unchanged):** for
  `(pid, digest)` serve the current best = most recent row with
  `verify_status='passed'`, preferring higher `qa_score`; if none passed, serve the
  latest `degraded` (facts-only, I6). Append-only preserved (new attempts are new
  rows; nothing mutated — E7 still holds). Content-addressing preserved: I1 still holds
  (served narrative corresponds to the current facts' digest; we pick best-of-candidates
  for that digest). E1–E6 unchanged (digest computed identically).

Recorded as **T22**, extending T7 (append-only) and T21 (recovery asymmetry): the
synthesis being disposable is exactly what makes quality-driven auto-rerun safe.

## Commits

Conventional Commits, scope `copilot`. One commit per build unit (or per U-pair), e.g.
`feat(copilot): U3 fact model, canonical serializer, and content-address digest`.
Add trailer `Assisted-by: Claude Code`. Do NOT reference model identifiers. Keep all
changes inside the module directory (additivity I9).
