# Clinical Co-Pilot — Tradeoffs & Decision Record

Companion to [../ARCHITECTURE.md](../ARCHITECTURE.md) (high-level architecture + agent layer: chat, verification, observability), [../ARCHITECTURE_COMPLETE.md](../ARCHITECTURE_COMPLETE.md) (the complete build spec), and [../USERS.md](../USERS.md) (user/use-case source of truth). The spec says **what** to build; this one records **why**, including the alternatives we rejected and the evidence that killed them. If a decision here seems wrong later, re-open it against the reasoning below — don't relitigate from scratch.

---

## T1 — LLM narrates, never extracts (core survived scrutiny; delivery surface revised by T14)

**Chosen:** N deterministic Capabilities produce typed facts + citations; one LLM reduce pass writes prose over facts it is handed.
**Rejected:** per-capability LLM calls — ×N cost/latency/hallucination surface, flaky regressions, no upside. Also rejected: LLM-side extraction of any kind, because extraction is exactly the step that must be verifiable, fixture-testable, and citable.
**Consequence:** every correctness property of the system is testable without an LLM in the loop; the LLM can only mis-narrate facts, never invent them, and mis-narration is bounded by strict output schemas.

## T2 — PHP in-process custom module (D1)

**Chosen:** `oe-module-clinical-copilot` under `interface/modules/custom_modules/` — the host's sanctioned extension point.
**Rejected:**
- *TS + Zod external service with direct MySQL* — rejected outright. Bypasses OpenEMR's ACL and PHI audit logging (an architectural liability in an EMR, not a style point), re-implements joins the host's services already own (e.g. the medication union), and creates two sources of truth for extraction logic. The TS idea was inherited from an unrelated rubric; once questioned, no one defended it.
- *TS sidecar via the fork's FHIR/OAuth2 API* — defensible (auth/audit/joins stay the host's problem) but still loses: it adds a second deployable to operate and secure, registers OAuth2 client rows in **core** tables (so its "zero footprint" is illusory), and its trust boundary is a network hop instead of a function call. This fork's one existing side-daemon (`ccdaservice`) is where the repo index flagged a cross-patient concurrency race — side-daemons here have a track record.
**Decisive factors:** one language on a monolith; the module inherits auth/ACL/session, `EventAuditLogger`, `QueryUtils`, Twig, the Symfony event system, and the `background_services` worker framework for free; a self-contained module directory has zero upstream merge-collision surface on a fork that tracks upstream.

## T3 — External view, no write-back (D2)

**Chosen:** the doc renders on a module page; it is never written into the chart.
**Rejected:** write-back into a note/LBF — freezes a generated doc into the clinical record (medico-legal weight, versioning burden) and makes the freshness invariant unenforceable: a note in the chart can't be recomputed when facts change.
**Consequence:** write-back remains cleanly severable future scope; nothing in the design blocks it later.

## T4 — Medications from BOTH `prescriptions` and `lists` (D3)

**Chosen:** UNION of the two, reusing the host's own `PrescriptionService` query.
**Evidence:** `src/Services/PrescriptionService.php` — the fork's FHIR MedicationRequest layer already unions them. In-house scripts land in `prescriptions`; externally-reported/reconciled meds land in `lists` (type=medication).
**Rejected:** `prescriptions` alone — silently drops externally-prescribed meds, which for an endocrinologist is precisely the metformin you can't miss.

## T5 — Freshness by content-addressing, not timestamps or watermarks (D4) — the load-bearing decision

The original plan's invariant read: *"read checks `max(lab_date) > computed_at` → recompute or flag stale."* It died in three stages, and the record matters:

1. **Late arrival** kills date comparison: clinical dates lag ingestion. A result ingested today carrying a three-week-old specimen date passes `max(lab_date) < computed_at` while the cache is stale — the exact failure the invariant exists to prevent.
2. **In-place mutation** kills the first fix (monotonic `max(id)` watermarks, proposed and then retracted during scrutiny): lab corrections update `procedure_result` rows in place (no new row, and the table has no updated-at column); soft deletes are UPDATEs of `activity`/`active` flags; vitals and prescriptions are edited in place. Of the four source tables, exactly one (`lists.modifydate`) has a DB-enforced row version; `prescriptions.date_modified` is app-maintained and this codebase's write paths are three generations deep (modern services, legacy `sqlStatement()` pages, HL7 ingestion) — a column not every path writes is a decoy.
3. **Clock semantics**: any timestamp-vs-timestamp check inherits server/session TZ drift (the fork literally documents TZ juggling on `background_services.lock_expires_at`).

**Chosen:** invert what's cached. Facts are cheap (per-patient indexed queries — verified: `procedure_order(patient_id)`, `form_vitals(pid)`, `prescriptions(patient_id)`, `lists(pid)`); the LLM reduce is the only expensive step. So: recompute facts on every read; cache only the narrative, keyed by `(pid, digest(facts ‖ versions))`. Staleness detection stops being a check and becomes cache addressing — serving a narrative over facts that no longer hold is *structurally unreachable*, without knowing or enumerating how data can change.
**Also rejected along the way:**
- *Event-driven invalidation as correctness* — legacy/HL7 write paths don't reliably fire Symfony events; acceptable later as a cache-warming hint only.
- *Triggers / binlog CDC* — correct but mutates host schema / wildly over-budget for an additive module.
- *In-SQL digests (`MD5(GROUP_CONCAT(...))`)* — `group_concat_max_len` defaults to 1024 and silently truncates → false-stable digests. Digest in PHP over fetched rows.
**Residual risk, accepted:** a write landing mid-request means the doc reflects a consistent snapshot taken at extraction time; the next read catches it. Beating that requires locking the EMR's write path — out of scope by the additivity principle.

## T6 — Zero core-table mutation (D5)

**Chosen:** no rows, no columns, no triggers on any existing OpenEMR table. Clinical tables are read-only to the copilot. Module-owned tables carry whatever they need.
**Rejected:** a nullable `updated_at` on core tables (proposed mid-design, withdrawn). App-maintained: never written by legacy/HL7 paths — a decoy future code would trust by mistake. DB-maintained (`ON UPDATE CURRENT_TIMESTAMP`): technically sound but a schema mutation of shared clinical tables on a fork tracking upstream — permanent merge liability, and it triggers the host's full `database.sql` + upgrade-file + `v_database` CI discipline. Decisively: **the digest design (T5) exists precisely so no mutation-tracking column is ever needed.** If slice-read performance is ever proven hot, the escalation ladder is: (1) nothing — reads are indexed and per-patient; (2) module-owned event warm-hints; (3) only then the column, through the proper upgrade mechanism, cost accepted with eyes open.

## T7 — Append-only doc store (D6)

**Chosen:** the doc store is write-once, append-only — no UPDATE, no DELETE, no pruning (an earlier "retain last N" idea was cut).
**Why:** history and preservation. Each row is an immutable record of "what the physician was shown over fact-set X." That makes the cache a **provenance ledger**: "what exactly did the physician see before this visit" is a medico-legal question the table answers byte-for-byte, and diffing adjacent rows' fact sets explains why the synthesis changed between visits. Growth is bounded by real fact changes × patients, not by reads.
**Consequences accepted:** digest recurrence (facts A→B→A serves the original row with its honest older generated-at); doc *views* are audited separately via the host's `EventAuditLogger` so the ledger stays pure computation history. **Acknowledged double edge:** the ledger that answers "what was the physician shown" for the defense is equally discoverable by the other side — provenance cuts both ways. Accepted with eyes open: a system that *cannot* show what it told the physician is worse in both roles.

## T8 — Two time axes, never mixed (lab contract C1)

**Chosen:** clinical timeline = specimen collection date (drives trends and overdue math: a three-month-old draw resulted yesterday is a three-month-old data point, and the overdue clock starts at collection). System freshness = the digest, using no dates at all.
**Why:** late arrival was only ever dangerous because the original plan used one axis for both jobs. Separated, the failure mode cannot be expressed.

## T9 — Lab contract positions (C2–C4)

- **Preliminary results: shown, labeled, in the in-flight section** — not hidden (an endocrinologist wants to know a preliminary A1c exists), not a trend point, never resets the overdue clock. The in-flight channel (see T10) is what resolved this: information presented in the channel that says "in progress."
- **Empty-string `result_status` presents (`status: unstated`)** — the schema defaults to `''` and manually-entered labs mostly carry it; excluding it would blank real completed values. The initial objection ("that's a waste") rested on reading `''` as "not ready yet" — but unresulted labs have *no result row at all* (that's T10's territory); `''` rows are completed results with thin metadata.
- **No unit, no math — strict** (after genuine hesitation). A unitless "6.5" A1c is *probably* percent; the strict rule still refuses to trend it, because guessing units is the path that presents an IFCC A1c of 48 as 48%. The cost is made measurable instead of argued: a per-analyte unitless-exclusion-rate counter, with loosening available later as a deliberate, versioned config decision — never a read-time inference.
- **Meta-invariant: no silent exclusion.** Every filtered row appears as a visible "N excluded (reason)" fact with citations. Exclusion is a presented fact, never a disappearance.
- **Out-of-range needs one of exactly two proofs** (parsed value vs. threshold, or the lab's own `abnormal` flag + reported range); conflicts are presented flagged, adjudicated by no one — including the LLM.

## T10 — PendingResults as a fifth capability

**Origin:** user requirement — the endo must know a lab drawn two days ago isn't resulted yet, so they don't re-order.
**Why a capability and not a status rule:** an unresulted lab is a `procedure_order` with **no `procedure_result` rows** — invisible to the result slice by construction, and silence reads as "no recent lab," which *invites* the duplicate order. So absence-of-result became a first-class, deterministic fact (active order + result absent), composing with OverdueTests ("overdue **but specimen already drawn** — do not reorder", asserted only when an active order proves it).

## T11 — Worker as pure cache warmer

**Chosen:** the pre-compute worker (a `background_services` row — the host framework already provides scheduling and lease-locking) only warms the narrative cache; reads never depend on it. Worker failure degrades latency, never correctness.
**Why:** this is what makes the worker severable (the original plan's timebox cut-line survives) and what makes the freshness invariant unconditional.
**Degradation rule (absolute):** LLM unavailable on a cache miss → serve fresh facts with "narrative unavailable"; never serve a narrative whose digest mismatches current facts — stale prose can actively contradict live facts sitting beside it.

## T12 — Additivity as an enforceable invariant, not a principle

"Adding on, not remodeling" is held by three tests (repo-diff empty outside the module dir; module-disabled ⇒ host byte-identical behavior; uninstall drops only module state). Chosen over trusting discipline because the fork's history — like every fork's — is that principles drift and tests don't.

## T13 — Extensibility as spine + gated extension points (weeks 2–3 requirement)

**Context:** later project stages build on this system; extensibility is a requirement, not a nice-to-have.
**Chosen:** a declared **stable spine** (fact model, serializer, digest, append-only ledger, read-path contract, degradation rule, additivity) plus five **gated extension points** (new capability, new doc shape/audience, config changes, new delivery surface, warm-hints) — each with its admission gate and its invalidation story. See the Extension model section of ARCHITECTURE_COMPLETE.md.
**Key forward-compat decision made early because it's cheap now and painful later:** `doc_type` and the reduce prompt/schema version are **digest inputs from v1**, even though v1 has exactly one doc type. This lets week-2/3 doc shapes (a rooming checklist, a deeper synthesis) coexist in the same ledger with correct, independent invalidation — without a digest-scheme migration that would orphan every existing ledger row's addressability.
**Rejected:** "we'll generalize when we need it" — the digest is the one component where retrofitting keys breaks the content-addressing story for all prior rows; everywhere else in the system, generalize-later remains the right call (don't gold-plate).
**Gates worth naming:** new capabilities require a USERS.md use-case row (Stage 4 traceability survives extension); new audiences require their own USERS.md pass (a new user is a new §1–§3, not a bolt-on); anything needing core-table mutation, fact caching, ledger mutation, or LLM access beyond capability-produced fact sets is a spine violation, rejected by the invariant table.

## T14 — Chat agent: preloaded context + capability tools (revises T1's delivery surface, deliberately)

**Context:** the case study's core-interface requirement is a *multi-turn conversational agent that invokes tools* — and the user's real second moment (USERS.md UC6: follow-up drill-downs that today send her back into the four-tab shuffle) independently justifies it. The original "one reduce pass, period" scoping was honest for the synthesis doc but banned tool invocation outright (old anti-extension rule wording, I8's neighborhood). This entry is the reasoning of record for re-opening it.

**Chosen:** a per-patient chat session, **preloaded** with the exact content-addressed doc the physician is reading (canonical fact set + verified narrative), whose only tools are the five deterministic capabilities — JSON-Schema'd, patient-pinned server-side (pid injected by the executor, asserted on every returned fact; no tool accepts a patient argument), chaining bounded (≤5 calls, ≤3 rounds). T1's *core* survives intact: the LLM still never extracts and still adjudicates nothing; it gained navigation (choosing which deterministic query to run), not extraction.
**Rejected:**
- *Free-form SQL / generic retrieval tool* — unbounded, unverifiable, un-citable surface; exactly the extraction T1 exists to ban, now steerable by conversation.
- *RAG over note text* — LLM-side extraction of the least-structured data in the chart; uncheckable citations.
- *Fresh un-preloaded agent per question* — repeats the reduce work on turn 1, pays retrieval latency for questions the synthesis already answers, and loses the "you're talking about the doc you're reading" grounding (fact_digest ties the session to the exact doc version shown).
- *A separate chat product beside the doc* — two surfaces to trust separately; the doc is the first message, not a sibling.
**Consequence:** chat inherits every fact-layer invariant for free (I2 facts-never-cached ⇒ tool results always fresh; I5 exclusions surface in answers; I8 conflicts pass through flagged), and adds I10 (structural pinning) and the chaining budget as its own.
**Sharpened during review into I13 (user decision of record): the LLM is I/O-less — the program pulls everything.** "Tool calling" was always executor-mediated; I13 makes the stronger statement explicit: the model can *request*, never *execute* — no query, no network, no host API, under any output it produces. The program pre-pulls all five capabilities at session seed (before clinic, via the worker), so most follow-ups need zero requests; executor-run requests cover only drill-downs beyond the preloaded envelope. This is also the honest answer to V5's limits: banned *actions* are impossible by construction (there is nothing the model can do, only things it can say), so the lint only has to police *phrasing* — and its paraphrase residual is bounded by evals, not by hope.

## T15 — Verification as a deterministic post-generation gate, not prompt discipline (and not an LLM judge)

**Chosen:** every LLM output — reduce pass and chat turn alike — emits structured claims (`{text, citation_ids[], numeric_values[]}`, strict schema) and passes six deterministic checks before rendering: schema (V1), citation resolution against the session fact set (V2), **patient-identity guard** — every cited fact's pid must equal the session's pinned pid, re-checked on output even though the tool executor already asserted it on input (V3), numeric grounding — every actual clinical **value a claim pulls** (results, readings) must exist in a cited fact after canonicalization, while narrative numbers that are not data pulls (dates, ages, frequencies/durations, disease type/stage, medication doses) are exempt and a stray ungrounded value in prose is still caught (V4), banned-claim lint — causation/recommendation/diagnosis/dosing patterns (V5), conflict passthrough (V6). Fail ⇒ one regeneration with the specific findings ⇒ facts-only degradation. V3 is special-cased as sev-1: no retry, session frozen, alert fired — if pinning failed, the bug is upstream of the LLM and continuing is wrong.
**Rejected:**
- *Prompt discipline as the guarantee* — "the prompt says cite everything" is hope, not architecture; the failure mode it misses (right citation, wrong number) is the clinically dangerous one.
- *LLM-judge as the blocking gate* — a second model as last line of defense is a second hallucination surface; kept as an advisory quality signal feeding evals/dashboards, never the gate. **Sharpened during review (user decision):** the advisory judge is promoted to a *visible* second-pass reviewer — a separate Flash instance re-reads each rendered answer against the session fact set and its concurrence/flags display beside the "citations checked" badge (ARCHITECTURE.md §2.5). Reader-facing signal on exactly the territory the deterministic gate can't cover (emphasis, paraphrase, omission); still never blocking.
- *Verifying only the synthesis path* — chat is the higher-risk path (adversarial input, unbounded question space); one gate, both paths.
**Known limitations, stated in the spec (ARCHITECTURE.md §2.4):** deterministic checks can't catch misleading emphasis, subtly-wrong qualitative paraphrase, or omission — bounded by facts-first rendering, narrative evals, and the advisory judge; not solved.

## T16 — Observability: module-owned trace store, in-app dashboard; no PHI to third-party SaaS

**Chosen:** correlation ID (UUIDv7) minted at every entry point and threaded through every capability call, LLM call, verification verdict, audit entry, and PSR-3 log line; per-step spans in append-only `mod_copilot_trace` — including cache hits, degraded reads, and failures (the original design's blind spot: only LLM misses left a record). Prompts/tool payloads contain PHI, so the trace store lives in the module's tables inside the EMR's MySQL — the same protection domain as the chart — with an ACL-gated, audit-logged in-app dashboard (metrics + click-through to span waterfalls and payloads).
**Rejected:**
- *LangSmith/Langfuse/Braintrust SaaS as primary* — ships PHI-laden prompts to a third party; expands the BAA surface for tooling convenience. A **self-hosted** Langfuse exporter is an optional add-on; the trace table stays the source of truth.
- *Logs-only observability (no trace table)* — can't answer "what happened, in what order, how long" with one indexed query; grep is not a dashboard, and per-request cost rollups need rows, not lines.
- *Metrics without payloads* — a wrong-patient or verification failure is only debuggable with the exact prompt/tool args in hand; payload_ref keeps them one click away.
**Consequence:** the four case-study log questions are answerable from one table by correlation ID alone; alert evaluation (7 alerts incl. the sev-1 V3 trip) rides the existing background-service tick — no new daemon (consistent with T2's rejection of sidecars); the worker-heartbeat alert alone lives on pull paths, since a dead worker can't report itself.

## T17 — Chat latency: pre-warm covers turn 1; turns 2+ buy freshness with seconds

**Context:** T5/T11's speed-vs-completeness answer (content-addressed cache + worker pre-warm) is built for the one-shot doc; multi-turn responses depend on conversation history and are not content-addressable that way.
**Chosen:** don't force them to be. The preload (T14) makes turn 1 effectively free — the warmed doc *is* the context. Turns 2+ run fresh capability queries (per-patient indexed reads, verified cheap in T5) plus one LLM call, landing in the few-seconds band USERS.md §1 explicitly tolerates for explicit interactions. Uncertainty is communicated, not hidden: tool failures are named in the answer ("vitals lookup failed — answering from labs and meds only"), and the chaining budget degrades transparently rather than silently truncating.
**Rejected:** caching chat responses (keyed on what? history × phrasing — near-zero hit rate, staleness risk returns); speculative pre-answering of predicted follow-ups (cost with no evidence of hit rate — revisit only if trace data shows recurring question shapes); streaming unverified tokens for perceived speed (defeats T15 — the stream shows staged progress, prose renders only post-verification).

## T18 — LLM platform: Google Gemini on Vertex AI, hosted on GCP

**Chosen:** Gemini through **Vertex AI** — Pro tier for synthesis and chat, Flash for the advisory judge — with exact model version strings pinned and folded into `prompt_version` (a digest input: model upgrades invalidate exactly the affected docs, E5 discipline). Structured output via Vertex `responseSchema` constrained decoding; native function calling for the five tools; **context caching** for the session preamble so turns 2+ pay incremental tokens only. Auth by GCP service account (`google/auth`, ADC) — no API keys. Integration is direct Vertex REST via Guzzle against a module-pinned, versioned contract.
**Why this provider surface:** Vertex is HIPAA-eligible under the GCP BAA and the deployment already lives on GCP — one cloud, one BAA, one IAM story. The BAA is the *legal* layer; **egress redaction** (direct identifiers tokenized before any Vertex call, re-hydrated post-verification — ARCHITECTURE.md §4) is the *engineering* layer on top of it, so the compliance story never rests on paper alone. Provider-*enforced* JSON schema materially strengthens V1 (rejection becomes rare instead of retried-often), and context caching is what makes the preloaded-session design (T14) affordable across turns.
**Rejected:**
- *Consumer Gemini API (AI Studio keys)* — no BAA path; disqualified outright for anything touching PHI, and key management is strictly worse than service-account ADC.
- *Anthropic/OpenAI direct* — capable models, but a second vendor relationship and BAA for no architectural gain here; neither changes the design (the verifier assumes nothing provider-specific beyond schema-constrained output, which all majors now offer). Revisit only if eval quality forces it — the model is a pinned, versioned, swappable input by construction.
- *Full Vertex PHP SDK* — its generative surface is thin/lagging; a hand-pinned REST contract is smaller and honest about what we depend on. **Accepted risk:** REST contract drift; mitigated by the pinned version string and the eval suite as a canary on any bump.

## T19 — Mid-conversation staleness: detect and disclose, don't re-pull (user decision of record)

**Context:** the chat session is seeded once with the doc's fact set (T14). If the chart changes mid-conversation, preloaded facts go stale — the same class of failure T5 exists to kill on the synthesis path.
**Chosen:** no automatic re-seed or regeneration per turn. **Rationale (the tradeoff, stated plainly):** re-pulling full context every turn is cost without benefit — a result that landed one minute ago rarely changes the answer to the question being asked, and context re-seeding also discards the conversation the physician is mid-thought in. Instead, every turn runs the *cheap* half of the machinery — fact re-extraction + digest compare (T5's whole point is that this step is inexpensive and LLM-free) — and on drift the answer renders under a visible "chart changed — refresh to re-seed" banner with a one-click fresh session. Tool results are always live regardless (I2). Staleness is therefore *detected and disclosed on every turn*, never silent; what's traded away is only the automatic context mutation.
**Rejected:**
- *Re-seed every turn* — cost and context-window churn per turn, cache-defeating (context caching keys on the stable preamble), and it silently rewrites the ground the conversation stood on.
- *No check at all* — silent staleness; the corrected-lab-mid-chat case is exactly the trust-killer USERS.md §1 names.
- *Auto-regenerate the synthesis on drift* — turns a background data event into a surprise UI change mid-read.
**Supporting spec fix:** `fact_id` now includes the canonical value, so a stale preloaded fact and its corrected re-fetch can coexist unambiguously in the session set (V2 resolution stays deterministic; a value-conflict between them is visible rather than aliased).

## T20 — Scaling: vertical-first, on a resizable server (user decision of record)

**Chosen:** the deployment scales by resizing the machine (GCE machine types: cores, RAM → PHP-FPM worker count), not by replicating the app. **Why:** OpenEMR is a session-holding monolith and the copilot is in-process by design (T2); each open chat connection holds a worker for the turn's duration, so concurrency is a worker-count problem, and worker count is a hardware problem. Horizontal app replicas would immediately buy shared-session storage, sticky connections, and a worker-singleton coordination problem — remodeling the host, which additivity forbids.
**Honest ceiling, named:** the biggest sensible single machine serves the v1 target (a clinic, tens of concurrent clinicians) with room; the R8/R9 load tests convert "how many users per core" from guess to number. Beyond that (multi-clinic/hospital scale): first read replicas for fact extraction, then extracting the chat loop into its own service — a deliberate re-opening of T2 recorded here in advance, so future-us re-litigates a documented decision instead of discovering an accident.

## T21 — Recovery asymmetry: the synthesis is disposable, the conversation is not (user decision of record)

**The principle (full failure model in ARCHITECTURE.md §6):** a summary that fails is a non-event — generation is idempotent and side-effect-free (facts recomputed fresh, ledger append-only, digest-addressed slot), so it is instantly rerun up to a bounded count (default 3, versioned config) with nothing to corrupt and no state to clean up. The territory that actually matters is failure *mid-conversation*: a chat turn is stateful, mid-thought, and non-replayable. **The failsafe is structural, not procedural: the verified summary and the full facts panel sit on the same screen as the chat, and they are served from the ledger and deterministic reads — never from the live LLM.** Any chat failure therefore degrades to a working reference surface the physician cross-checks historical data against; the chat can die, the chart-review job always completes.
**Rejected:**
- *Making chat turns replayable/retryable like the synthesis* — replaying a turn re-runs tools against possibly-changed data under possibly-stale context; a "retried" answer that silently differs from what a retry of the same question *should* say is worse than an honest "turn failed, ask again."
- *Treating all failures uniformly* — spending engineering on synthesis-failure exotica (checkpointing, resumable generation) buys nothing when rerun-from-scratch is free; spending it on chat resilience buys less than the adjacency failsafe already provides. The asymmetry is where the leverage is.
**Consequence:** every §6.2 failure row ends at the same floor by construction, and the capability-crash rule (§6.1: no digest, no ledger write over a partial fact set) exists because the one thing worse than a missing synthesis is a synthesis whose silence about a crashed domain reads as "nothing notable there."
