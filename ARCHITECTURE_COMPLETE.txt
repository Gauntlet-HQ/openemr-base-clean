# Clinical Co-Pilot — Complete Build Spec

**This is the full, detailed spec.** The one-page high-level overview required by the case study lives in [ARCHITECTURE.md](ARCHITECTURE.md), which begins with the ~500-word summary and then covers the agent layer (chat agent, verification, observability) that builds on the fact layer specified here.

**User & use cases (source of truth):** [USERS.md](USERS.md) — outpatient endocrinologist, the 8:50 AM pre-visit sweep, UC1–UC6. Every capability below traces to a UC there (traceability table in USERS.md §5); no capability may be added without one.
**Job:** Pre-visit synthesis — is control on target, are meds working, what's overdue, what's in flight.
**Platform:** This OpenEMR fork. Additive only.

Rationale and rejected alternatives live in [docs/clinical-copilot-tradeoffs.md](docs/clinical-copilot-tradeoffs.md) (T1–T21, referenced below). This doc is the buildable **what**. Repo conventions, canonical commands, and blast-radius live in the root `INDEX.md` / `repomap.json`; implementers follow house conventions from there (strict_types, PSR-4, QueryUtils, Twig escaping filters, CSRF on POSTs, file-header docblocks).

---

## Core principle (T1, revised by T14)

The LLM **narrates and navigates — it never extracts.** Deterministic Capabilities produce typed facts + citations; the LLM's only powers are (a) writing prose over facts it is handed and (b) choosing which Capability-tool to invoke next in a chat turn. It never parses raw rows and never adjudicates data conflicts (I8).

```
SYNTHESIS:  5 deterministic Capabilities  →  typed facts + citations
                    ↓ canonicalize + digest
            1 LLM reduce pass (cache-missed only)  →  VERIFY  →  prioritized, cited doc

CHAT (UC6): session pinned to pid, preloaded with the doc's fact set + narrative
            LLM ⇄ Capability tools (schema'd, pid injected server-side, bounded chaining)
                    ↓ per turn
            VERIFY  →  cited, physician-readable answer
```

The agent layer (chat loop, verification gate V1–V6, observability) is specified in [ARCHITECTURE.md](ARCHITECTURE.md) §1–§3; this doc owns the fact layer, storage, and build units for both.

## Placement (T2, T12)

One PHP module: `interface/modules/custom_modules/oe-module-clinical-copilot/`, PSR-4 namespace `OpenEMR\Modules\ClinicalCopilot\`. Standard custom-module pattern (composer.json, info.txt, openemr.bootstrap.php, table.sql, src/, templates/, tests/).

**Additivity invariant (enforced, see U9):**
1. Repo diff outside the module directory (and the case-study root deliverables: `README.md`, `AUDIT.md`, `USERS.md`/`USER.md`, `ARCHITECTURE.md`, `ARCHITECTURE_COMPLETE.md`, `docs/clinical-copilot-tradeoffs.md`) is empty. These are the submission documents the case study requires at the repo root; the enforcing gate (`ops/ci/check-additivity.sh`) whitelists exactly this set.
2. Module disabled ⇒ host behaves byte-for-byte identically.
3. Uninstall drops only `mod_copilot_*` tables and the module's `background_services` row.

Test 3 is in deliberate tension with T7's provenance-ledger value: uninstall destroys the "what did the physician see" record. Resolution: uninstall requires an explicit operator confirmation and offers export-before-drop; retention/disposal policy is OPEN-2 and ARCHITECTURE.md §4.

**Known exception — deployment configuration.** Test 1 is scoped to *application* code and the root submission docs; it does **not** cover the deploy layer. Standing up the public deployment (the "Deploy It" hard gate) necessarily adds host-level files outside the module — currently `railway.toml`, `docker/railway/Dockerfile`, `.gitlab-ci.yml`, and `.dockerignore`. These are infrastructure-as-config, not host application changes: they do not alter OpenEMR's runtime behavior with the module disabled (Test 2 still holds byte-for-byte), and they touch no `mod_copilot_*` or core clinical table (Test 3 unaffected). The additivity gate deliberately does **not** whitelist them — a deploy-config change is a real, reviewable deviation that should show up in the diff, not be silently absorbed — so `check-additivity.sh` flags them by design; that flag is the acknowledgement, read together with this note, not a violation to suppress.

All existing OpenEMR tables are **read-only** to this module — no rows, no columns, no triggers (T6). Reuse host service objects where they exist (notably `PrescriptionService` for the meds union, T4).

## System invariants

| # | Invariant | Held by |
|---|---|---|
| I1 | A served narrative is content-addressed by the digest of facts recomputed at read time. Staleness is cache addressing, not a check; no timestamps participate. (T5) | read path + digest |
| I2 | Facts are never cached; only narratives are. | read path |
| I3 | Doc store is append-only: no UPDATE, no DELETE, no pruning. (T7) | DocStore + eval |
| I4 | Clinical timeline (collection dates) and system freshness (digest) never mix. (T8) | LabSlice + digest |
| I5 | No silent exclusion: every filtered row appears as a visible "N excluded (reason)" fact with citations. (T9) | all capabilities |
| I6 | Degradation is absolute: LLM unavailable on miss ⇒ fresh facts + "narrative unavailable"; a digest-mismatched narrative is never served. (T11) | read path |
| I7 | Worker failure degrades latency only, never correctness. (T11) | worker as warmer |
| I8 | The LLM adjudicates nothing: conflicts arrive in the facts flagged as conflicts and render as such — in narratives and chat turns alike. | fact schema + reduce + verifier V6 |
| I9 | Additivity (three tests above). (T12) | CI + module tests |
| I10 | Patient pinning is structural: a chat session binds one pid server-side; no tool accepts a patient argument; every produced fact's pid is asserted on tool return AND re-verified on every output citation (verifier V3). (T14, T15) | tool executor + verifier |
| I11 | No unverified prose is ever rendered: every LLM output (reduce or chat turn) passes verification V1–V6 or degrades to facts-only. Generalizes I6. (T15) | verifier + read/chat paths |
| I12 | Every invocation leaves a trace: correlation ID minted at entry, on every span, log line, and stored row — cache hits, degraded reads, and failures included. (T16) | trace store + all paths |
| I13 | **The LLM is I/O-less: the program pulls everything.** The model executes nothing — no query, no HTTP call, no host API. It emits structured *requests*; deterministic module code (the seed pre-pull and the tool executor) validates, pins, executes, and returns facts. Every byte the LLM ever sees was fetched by program code. (T14) | session seeder + tool executor |

## Two time axes (I4)

- **C1 — Clinical date precedence:** `procedure_report.date_collected` → `procedure_order.date_collected` → `procedure_result.date` → `procedure_report.date_report`. First two are authoritative collection dates; last two are fallbacks, and a fallback-dated fact carries `date_source: fallback` in its citation. All trend ordering and OverdueTests math runs on clinical dates.
- **System freshness:** digest only. No dates.

## Fact object — the canonical schema (contracts are the source of truth, ARCHITECTURE.md R3)

Every capability output, tool result, digest input, LLM prompt fact, and verifier check (V2–V4) operates on this one shape. It ships as a JSON Schema file beside the typed PHP fact objects (U3); the schema, not the implementation, is the contract.

```
Fact {
  fact_id:            string            // hash(capability, kind, citations, canonical value) — value
                                        // included so a preloaded fact and a later re-fetch of the same
                                        // datum with a corrected value never collide (V2 stays unambiguous, T19)
  capability:         enum [control_proxy, med_response, vitals_trend, overdue_tests, pending_results]
  capability_version: string            // digest input
  kind:               enum [result, trend_point, med_event, vital, overdue_item,
                            pending_order, preliminary_result, exclusion, conflict,
                            derived_delta, derived_count, derived_span, expected_result_date]
                      // derived_* facts are computed DETERMINISTICALLY by capabilities
                      // (never by the LLM, never by the verifier) and cite the raw facts
                      // they derive from — this is what lets V4 stay strict while prose
                      // says "rose 0.6 over three draws" (each number cites a fact).
                      // expected_result_date comes from versioned lab-turnaround config,
                      // making "result likely back before Thursday" a cited fact, not a guess.
  pid:                int               // asserted on tool return (I10) and re-verified per citation (V3)
  clinical_date:      ISO-8601 | null   // per C1 precedence; null only for kinds without a timeline
  date_source:        enum [collected, fallback]        // C1
  value: {                              // present on result/trend_point/vital kinds
    raw:              string            // verbatim from the chart, length-bounded
    parsed:           number | null     // null ⇒ no numeric claim permitted (C3)
    comparator:       enum [none, lt, lte, gt, gte]     // censored values (C3)
    unit_original:    string            // '' allowed; then parsed math is banned (C4)
    unit_canonical:   string | null
    conversion_version: string | null   // C4 whitelist version, digest input
  } | null
  status:             enum [final, corrected, unstated, preliminary, excluded]   // C2
  flags:              set  [conflict, censored, superseded_n, out_of_range_by_value,
                            out_of_range_by_lab_flag, excluded_reason:<enum>]     // I5, C2–C3
  citations: [ {                        // ≥1 for every fact; the verifier resolves these (V2)
    table:            string            // e.g. procedure_result, prescriptions, lists, form_vitals
    pk:               int
    field:            string | null
    date_source:      enum [collected, fallback]
  } ]
}
```

Tool *input* schemas are the per-tool blocks in ARCHITECTURE.md §1.2. Changing this schema is a `capability_version`/prompt-schema version bump — a digest input — so every affected doc regenerates (E5 discipline).

## Capabilities

Each declares: **code set · table slice · threshold · output schema · invariant.** Extension = one tuple + its eval file; no new parsing path.

| Capability | Use case (USERS.md) | Source | Invariant (→ eval) |
|---|---|---|---|
| ControlProxy | UC1, UC2 | A1c/glucose/lipids — `procedure_order (patient_id, activity=1)` → `procedure_report` → `procedure_result` (LOINC; no pid on result — 3-table join) | out-of-range only with one of the two admissible proofs (C3) |
| MedResponse | UC1, UC3 | UNION `prescriptions` + `lists` (type=medication) via host `PrescriptionService`, paired with labs | shows paired trend + cites both; **never asserts causation** |
| VitalsTrend | UC1, UC3 | weight/BP/BMI — `form_vitals` (pid-indexed) | flagged value must exist in row |
| OverdueTests | UC1, UC4 | max collection date per code + `mod_copilot_cadence` | overdue only if last-draw + interval prove it; composes with PendingResults: reorder-suppression note only if an active pending order proves it |
| PendingResults | UC1, UC4, UC5 | `procedure_order` (activity=1, code set) with status `pending`/`routed` or no `procedure_result` rows | pending shown only if an active order exists with no final/corrected result; never counts as a result, never resets the overdue clock (T10) |

All five capabilities additionally serve **UC6** as the chat agent's tools (matching the ● column in USERS.md §5); the UC columns above list the synthesis use cases each capability exists for.

## Lab slice contract (ControlProxy / OverdueTests / PendingResults input rules)

Slice base: the 3-table join above, `activity = 1` only (mirrors host `ProcedureService`).

### C2 — Status semantics (`result_status` is free-text varchar, default `''`)

| Status | ControlProxy value | OverdueTests clock | Notes |
|---|---|---|---|
| `final` | presented | resets clock | |
| `corrected` | presented, supersedes | resets clock | supersession rule below |
| `''` (empty) | presented, `status: unstated` | resets clock | completed manual-entry results, not labs in flight (T9) |
| `preliminary` | presented in **in-flight section**, labeled | does NOT reset clock | renders beside PendingResults ("preliminary A1c 8.1 — final pending"), never a trend point |
| `cannot be done`, `incomplete`, `error`, `pending`, `canceled` | excluded (flagged, I5) | does NOT reset clock | an unperformed test can't prove the test isn't overdue |
| unrecognized | excluded (flagged, I5) | does NOT reset clock | unknown → exclude-and-flag, never guess |

**Supersession:** within (patient, `result_code`, clinical date): `corrected` > `final` > `''` > `preliminary`; ties → highest `procedure_result_id`. Winner presented; citation notes "supersedes N prior result(s)". Covers in-place and new-row corrections.

### C3 — Value parsing (`result` is varchar(255))

- Numeric parse only for `result_data_type` ∈ {`N`, `S`}; `F`/`E`/`L` never numeric.
- Grammar: optional comparator (`<`, `<=`, `>`, `>=`) + decimal + optional trailing unit token; whitespace tolerant.
- Comparator values are **censored** (value + comparator): support only claims their direction proves, plot with marker, never exact.
- Unparseable → qualitative fact if the capability accepts qualitative, else excluded-and-flagged. **No numeric claim without a parsed numeric.**
- **Out-of-range proofs (exactly two admissible):** (a) parsed numeric vs. threshold (cite value + threshold + threshold version); (b) lab's `abnormal` ∈ {yes, high, low} + reported `range` (cite both). Conflict ⇒ both presented with `conflict` flag; nothing adjudicates (I8).

### C4 — Units (`units` is varchar, default `''`)

- Per-analyte canonical units + conversion whitelist in `mod_copilot_cadence`-style versioned config (version feeds digest): A1c → % (IFCC mmol/mol ↔ NGSP %), glucose → mg/dL (mmol/L ×18.018), cholesterol → mg/dL (×38.67), triglycerides → mg/dL (×88.57). Converted facts carry original + canonical + conversion version.
- **No unit, no math:** empty/unrecognized units ⇒ verbatim presentation with `unit: unknown`, excluded from thresholds and trends, counted in exclusions. Unit guessing banned. Per-analyte unitless-exclusion-rate counter recorded on the doc row (loosening later = versioned config decision, T9).

## Compute model (I1, I2)

```
READ PATH (every read):
  mint correlation_id (I12)
  extract facts fresh (deterministic, per-patient indexed queries)
  canonicalize → digest = hash(facts ‖ capability versions ‖ config/cadence version ‖ code-set version
                               ‖ doc_type ‖ reduce prompt+schema version)
  CAPABILITY-CRASH RULE (ARCHITECTURE.md §6.1): if any capability throws during
    extraction → NO digest, NO ledger write — a synthesis is never computed over a
    partial fact set. Surviving capabilities' facts render under a named banner
    ("VitalsTrend unavailable — synthesis paused"); error span carries correlation_id.
  lookup (pid, digest):
    hit  → serve stored doc
    miss → LLM reduce (transient LLM errors auto-retried up to 3, versioned config,
           breaker-aware — rerun is free: no side effects, append-only ledger)
           → VERIFY V1–V6 (fail ⇒ one retry ⇒ facts-only, I11) → INSERT row → serve
    miss + LLM unavailable after retries → facts + "narrative unavailable" (I6)

CHAT PATH (per turn — full spec ARCHITECTURE.md §1–§2):
  mint correlation_id; load session (pid-pinned, I10) seeded with doc fact set + narrative
  agent loop (≤5 tool calls, ≤3 rounds): LLM ⇄ capability tools
    each tool call: schema-validate args → inject session pid → run capability fresh (I2)
                    → assert returned facts' pid (I10) → add to session fact set → span (I12)
  VERIFY V1–V6 over the complete response (fail ⇒ one retry ⇒ facts-only, I11)
  append turn row (tokens, cost, verdict) → render cited answer

WORKER (warmer only, I7):
  background_services row (host framework: next_run / execute_interval / lease-lock).
  TRIGGER GUARANTEE: background services execute only from logged-in users' AJAX ticks
  or system cron. A cron entry is therefore a HARD deployment requirement
  (every 5 min → library/ajax/execute_background_services.php for the site); without
  it the pre-clinic warm never runs and alert evaluation sleeps whenever nobody is
  logged in. /copilot/ready checks heartbeat freshness; an external uptime probe on
  /ready is the dead-man switch (the worker cannot alert on its own death — see the
  heartbeat alert, ARCHITECTURE.md §3.5).
  WARM POLICY: window = next clinic day's appointments; full-window passes at T-12h
  and T-1h, then the 5-min tick. Regenerate only on digest miss. Per-tick LLM budget
  from §3.7: a chart-churn storm degrades warm coverage, never blows the spend cap;
  cold patients fall back to read-time generation (I7).
  Warming covers synthesis + chat turn 1 (the preload); turns 2+ pay fresh-tool latency
  by design — seconds are inside the UC6 tolerance (T17). Mid-conversation staleness
  policy: per-turn digest check, banner on drift, no auto re-seed (T19).
```

**Canonical serialization:** stable sort keys, ISO-8601 dates, normalized decimals, no map-order leakage. Pure function; the same serialization feeds the digest and the LLM prompt. Digest recurrence (facts A→B→A) serves the original row with its honest generated-at.

## Module-owned tables (ship via module `table.sql`)

```
mod_copilot_doc:
  id (pk) · pid (indexed with computed_at) · fact_digest (unique with pid)
  doc_type (default 'endo-previsit-v1'; also a digest input — column exists for querying)
  appt_id (metadata, NOT key) · doc (JSON: facts + citations + narrative)
  capability_versions (JSON) · prompt_version · computed_at (display only)
  correlation_id · llm_latency_ms · tokens_in · tokens_out · cost_usd
  excluded_counts (JSON, per-analyte incl. unitless-exclusion rate)

mod_copilot_cadence:
  code_set · interval · version · updated_at (nullable; module-owned so allowed)
  (also carries canonical-unit/conversion config, lab-turnaround config for
   expected_result_date facts, §3.7 rate-limit/breaker values, and the synthesis
   auto-retry count (default 3, ARCHITECTURE.md §6.1) — all versioned)

mod_copilot_chat_session:
  id (pk) · pid · user_id · doc_id (fk mod_copilot_doc) · fact_digest
  status (active | frozen) · created_at
  (frozen = verifier V3 sev-1 trip; session is preserved as evidence, never resumed)

mod_copilot_chat_turn:                      -- append-only, same ledger philosophy as T7
  id (pk) · session_id (indexed) · seq · role (user | assistant | tool)
  content (JSON) · tool_calls (JSON) · verification_verdict (JSON, per-check V1–V6)
  correlation_id · tokens_in · tokens_out · cost_usd · created_at

mod_copilot_trace:                          -- append-only; observability source of truth
  correlation_id (indexed) · span_id · parent_span_id
  kind (extract | digest | cache_lookup | llm_reduce | chat_turn | tool_call |
        verify | render | warm | alert_eval)
  started_at · duration_ms · status (ok | error | retried | degraded)
  error_class · error_detail · model · tokens_in · tokens_out · cost_usd
  pid · user_id · payload_ref
```

Doc views and chat data access are audited via host `EventAuditLogger`; the ledgers stay pure computation history (T7). Trace payloads contain PHI and therefore live here — the chart's own MySQL protection domain — never in third-party observability SaaS (T16).

## Build units

Each unit is independently buildable and testable; owned files are disjoint; all paths relative to `interface/modules/custom_modules/oe-module-clinical-copilot/` unless noted. Red/green gates: `openemr-cmd unit-test` (DB-backed), `openemr-cmd phpunit-isolated` (pure logic), `openemr-cmd code-quality` — all must pass per unit.

| Unit | Scope | Owned files | Depends on | Acceptance |
|---|---|---|---|---|
| U1 Module skeleton | composer.json, info.txt, openemr.bootstrap.php, `src/Bootstrap.php` (event subscription, menu/page registration, ACL gate), `table.sql` (all five `mod_copilot_*` tables + background_services row), ModuleManagerListener | module root files, `src/Bootstrap.php` | — | installs/enables/disables/uninstalls cleanly; additivity tests 2–3 pass (I9) |
| U2 Seed + fixtures | Seed script: 3–4 synthetic diabetes patients with landmines — rising A1c, med-dose-vs-A1c mismatch, overdue urine ACR, late-arriving lab, corrected lab (both variants), drawn-but-unresulted order, `"<7.0"` value, unitless value, mmol/mol value, unrecognized status, preliminary result | `tests/Seed/`, fixture JSON | U1 | seed runs idempotently against dev stack; every contract eval below has its known-answer row |
| U3 Fact model + digest | Typed fact objects, canonical serializer, digest fn (versions composed in) | `src/Fact/` | U1 | determinism eval E6; serializer unit tests (isolated suite) |
| U4 LabSlice reader | The full lab contract: join, C1 date precedence, C2 status/supersession, C3 parsing, C4 units; exclusion accounting (I5) | `src/Lab/` | U2, U3 | contract evals: comparator censoring; supersession (both variants); cannot-be-done ≠ clock reset; unitless excluded-but-visible; mmol/mol conversion; unrecognized-status visible exclusion |
| U5 Capabilities | ControlProxy, OverdueTests, PendingResults (consume U4); MedResponse (host PrescriptionService union); VitalsTrend; derived-fact emission (deltas, counts, spans; PendingResults: expected_result_date from turnaround config) | `src/Capability/` | U4 | per-capability known-answer fixtures; capability invariants in table above; pending suppresses reorder note on overdue+ordered fixture; derived facts recomputed in tests equal cited values and cite their raw facts |
| U6 DocStore | Append-only repository + observability columns | `src/DocStore.php` | U1, U3 | E7 append-only (no UPDATE/DELETE paths exist; rows immutable) |
| U7 Reduce | LLM client (Vertex REST, T18), prompt assembly from canonical facts, **egress redaction** (identifier→pseudonym tokenization + post-verification re-hydration, ARCHITECTURE.md §4), degradation rule (I6). Output-schema validation, the fail-closed retry, and conflict passthrough are **owned by U10's verifier** — U7 hands raw model output to the gate, it does not gate | `src/Reduce/` | U3 | degradation test (LLM stub down ⇒ facts-only, I6); prompt-assembly test (prompt bytes = canonical serialization, same input as digest); redaction round-trip test (no direct identifier appears in any outbound payload; rendered answer re-hydrated correctly) |
| U8 Read path + page | Controller + Twig template: facts-first rendering, in-flight section, exclusion notes, history view (`ORDER BY computed_at`), EventAuditLogger on view | `src/Controller/`, `templates/` | U5, U6, U7 | digest evals E1–E5 end-to-end; preliminary renders in-flight and absent from trend; view writes audit-log entry |
| U9 Worker + additivity CI | `background_services` function (appt window → warm); CI checks: additivity test 1 (repo-diff) + module-scoped PHPStan forbidden-write rule (read-only enforcement layer 1, ARCHITECTURE.md §4) | `src/Worker.php`, CI config within module | U8 | worker-dead ⇒ reads still correct and fresh (I7); warm hit serves without LLM call; repo-diff gate green; PHPStan rule fails CI on any write-API call outside `mod_copilot_*` repositories |
| U10 Verifier | Claim-schema output contract; checks V1–V6 (citation resolution, pid guard, numeric grounding, banned-claim lint, conflict passthrough); fail-closed retry/degrade; verdict persistence | `src/Verify/` | U3, U7 | verification evals: seeded wrong-number / wrong-patient / uncited / causation-phrased stub outputs all blocked; V3 trip freezes session + alerts; verdicts recorded per check |
| U11 Chat agent | Session store (pid-pinned, doc-preloaded); tool executor (JSON-Schema args, server-side pid injection, pid assertion on return, ≤5 calls / ≤3 rounds); `POST /copilot/chat` controller (CSRF + ACL + audit); SSE render after verification; chat Twig panel | `src/Chat/`, `src/Controller/ChatController.php`, `templates/chat*` | U5, U10 | multi-turn anaphora fixtures; chaining known-answer (med-date → vitals-window); adversarial evals refuse cross-patient + ACL-denied + injected instructions; tool failure surfaces to model AND user; LLM-down ⇒ facts browser (I6/I11) |
| U12 Observability | Correlation-ID middleware; `mod_copilot_trace` writer on every path (I12); dashboard page (requests, error rate, p50/p95, per-tool failures, retries, verification pass rate, cache hit rate, cost) with click-through to span waterfall + payloads; alert evaluator on worker tick (7 alerts, ARCHITECTURE.md §3.5; heartbeat alert detected on pull paths); rate-limit/circuit-breaker enforcement (§3.7); `/copilot/health` + `/copilot/ready` (module-standalone; no host prober registration — §3.4) | `src/Observability/`, `templates/dashboard*` | U1 (consumed by all) | every eval run leaves reconstructable traces (spot-check: answer the 4 case-study questions from the table alone); cache-hit and degraded paths produce spans; /ready fails when LLM stub down while /health stays green |
| U13 Ops artifacts | Bruno API collection (`/copilot/chat`, `/copilot/doc/:pid`, `/copilot/health`, `/copilot/ready`); baseline profile capture; load tests at 10 and 50 concurrent users (p50/p95/p99 + error rate recorded) | `ops/` within module | U11, U12 | collection runs green against deployed stack without reading source; baseline + load numbers committed alongside eval results |

### Digest evals (deterministic, no LLM)

- **E1 late arrival:** compute → insert lab row with backdated clinical date → digest changes.
- **E2 in-place correction:** UPDATE a result to `corrected`, new value → digest changes.
- **E3 soft delete:** flip `activity`/`active` on a cited row → digest changes.
- **E4 irrelevant churn:** change data outside every slice (other patient, untracked LOINC) → digest unchanged.
- **E5 config drift:** bump cadence/config version → affected docs invalidate, others don't.
- **E6 determinism:** same DB state, two extractions → identical digest.
- **E7 append-only:** after any run, existing rows unmutated, count never decreased.

## Extension model (weeks 2–3 build on this — T13)

The system splits into a **stable spine** and **designed extension points**. Later stages extend at the points; they never modify the spine. Invariants I1–I13 bind extensions exactly as they bind v1 — the eval suite is the enforcement, not convention.

**Stable spine (weeks 2–3 must not change):** the fact model + canonical serializer + digest; the append-only ledger; the read-path contract (fresh facts → digest → content-addressed narrative); the degradation rule; the additivity invariant against the host.

**Extension points, each with its gate:**

| Extension | How | Gate | Invalidation |
|---|---|---|---|
| New capability (new analyte domain, new fact type) | One class implementing the capability interface: code set · slice · threshold · schema · invariant, plus its eval/fixture file. No new parsing path; lab-shaped sources reuse the LabSlice reader. | A traceable UC row in USERS.md §5 (or a new UC defensible under its §1 user) — capabilities without a use case are rejected by construction. | Its version string joins the digest → exactly the affected docs regenerate. |
| New doc shape / audience (e.g. a rooming checklist, a different synthesis depth) | New `doc_type` + its own reduce prompt/schema version over the **same** fact layer. Doc types coexist in the ledger because `doc_type` and prompt version are digest inputs (designed in now for this reason). | Its own USERS.md pass — a new audience is a new §1/§2/§3, per Stage 4's own bar. | Prompt/schema version bump invalidates only that doc_type. |
| Threshold / cadence / unit-conversion changes | Versioned config rows in module tables. | Version bump (never in-place semantics change without one). | Config version is a digest input (E5 guards). |
| New delivery surface (write-back, FHIR export, notifications) | Consumes ledger rows; never bypasses the read-path contract. | Re-opens T3 deliberately (it was deferred, not forgotten) against the tradeoffs doc. | n/a — downstream of the ledger. |
| Warm-hints (event-driven proactive recompute) | Symfony event subscribers as *optimization only*. | T5's boundary: the read-time digest remains the sole correctness authority. | n/a. |

**Anti-extension rule:** anything that requires mutating a core table, caching facts, updating a ledger row, or letting the LLM see data outside **capability-produced, citation-carrying fact sets** is not an extension — it's a violation of the spine and gets rejected against the invariant table, with the tradeoffs doc as the reasoning of record. (The chat agent is the worked example of a *legitimate* extension under this rule: it re-opened T1's delivery surface deliberately — recorded as T14 — but every byte the LLM sees still comes from the capabilities, and every output still passes the verifier. What remains banned: raw-row access, free-form SQL tools, RAG over note text, unverified rendering.)

## Non-goals (v1)

- No write-back into notes/LBF (T3). No real PHI — synthetic patients only (see OPEN-1). No core-table changes of any kind (T6). No per-capability LLM calls (T1 — chat tools remain deterministic capabilities; the chat loop's LLM calls are per-round, not per-capability, T14). No event-driven invalidation as correctness (warm-hints acceptable later, T5). No unit guessing (T9).

## OPEN

1. **PHI/LLM boundary beyond demo.** Synthetic-only this phase; any real-data future requires a named redaction/BAA story before the LLM call leaves the building.
2. **Retention/disposal for the append-only ledgers.** Doc, chat-turn, and trace payloads hold PHI-derived content indefinitely (T7 provenance). Position of record: retention follows the site's medical-record retention policy; disposal only via administrator-operated export-then-purge tooling, never application-level row deletes; uninstall confirms and offers export-before-drop. The purge tooling itself is unbuilt — it must exist before any real-PHI deployment (same gate as OPEN-1).
