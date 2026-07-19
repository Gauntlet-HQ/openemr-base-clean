# Week 2 AgentForge — Remediation Plan (FINAL_REVIEW)

**Purpose:** a self-contained execution spec. Hand this to a fresh-context agent; it
spawns one subagent per workstream (parallel where independent), applies fixes on
branch `FINAL_REVIEW`, and verifies against the acceptance checks below.

**Branch:** `FINAL_REVIEW` (already created off `main`). All work lands here. Do **not**
push to `main` until a human reviews.

**Module root:** `interface/modules/custom_modules/oe-module-clinical-copilot/`
(paths below are relative to it unless prefixed with repo-root paths like `.github/`).

---

## How to execute (fresh agent, read first)

1. Work only on `FINAL_REVIEW`. Keep each workstream on-topic; commit per workstream
   with a conventional-commit message (`fix(...)`, `feat(...)`, `docs(...)`).
2. **Spawn subagents in priority waves.** P0 first (they are the graded non-negotiables),
   then P1, then P2 (docs — cheap, high grading value), then P3 (bonus). Within a wave,
   the workstreams are independent — run them in parallel.
3. **Guardrails — do not break these:**
   - Additivity: the module must stay self-contained under `oe-module-clinical-copilot/`
     (plus the existing root `railway-*`/`Dockerfile.railway` deploy layer). Run
     `ops/ci/check-additivity.sh origin/main` — it must pass.
   - Keep the isolated + DB test suites green. Every new behavior gets a test with a
     documented failure mode (repo convention).
   - Strict typing, `declare(strict_types=1)`, PSR-3 logging, no raw PHI in logs
     (see repo `CLAUDE.md`). New PHP files get the standard docblock header.
4. **Global acceptance (all must hold before calling this done):**
   - `php ops/eval/run-evals.php` exits **0** (currently exits 1 — see W1b).
   - `openemr-cmd phpunit-isolated` (alias `pit`) green; DB suite green where touched.
   - `openemr-cmd phpstan` (level 10) clean for changed files.
   - `ops/ci/check-additivity.sh origin/main` passes.
   - A CI workflow runs the eval gate and **blocks** on a seeded regression (W1a).

---

## Coverage summary (from the FINAL_REVIEW audit)

Legend: ✅ MET · 🟡 PARTIAL · ❌ MISSING/REGRESSED

| Deliverable | Status | Note |
|---|---|---|
| Two doc types (lab PDF, intake form) | ✅ | `attach_and_extract` equiv, both wired |
| Source doc stored in OpenEMR + procedure-chain writer | ✅ | `ChartWriter`, idempotent re-lock |
| Lab schema (name/value/unit/range/date/flag/citation) | ✅ | but `collection_date` dropped at parse (W5) |
| Intake schema (demographics/concern/meds/allergies/family) | 🟡 | source citation removed (W6) |
| Raw VLM can't bypass schema validation | ✅ | `ExtractionSchema::validate` gatekeeper |
| Hybrid RAG (sparse + dense + fusion) | ✅ | TF-IDF + pgvector + RRF |
| Rerank before answer model | 🟡 | reranker only on offline path, not Postgres/prod (W7) |
| Guideline corpus + top-k + PHI scrub | ✅ | `src/Rag/corpus/`, scrubber allowlist |
| Supervisor + intake-extractor + evidence-retriever | ✅ | classes exist, handoffs logged |
| Supervisor wired into the live/deployed app | ❌ | only referenced from tests (W3) |
| Critic rejects uncited/unsafe claims (HARD GATE) | 🟡 | Verifier exists, gate OFF by default, not on agent path (W2) |
| Correlation ID + child-span tracing (4 levels) | 🟡 | supervisor→worker only; sub-calls + ingest tree not linked (W8) |
| Citation shape `{source_type,…}` | ✅ | `SourceCitation` has all 5 + bbox |
| Visual PDF bbox overlay | ❌ | removed in `0eade04`; data still stored (W4) |
| Click-to-source UI + doc preview | 🟡 | real PDF iframe ✅, deep-link/click ❌ (W4) |
| 50-case golden set, all Week-2 categories | ✅ | `ops/eval/cases.json` = exactly 50, all Week-2: ext-intake/ext-lab ×22, ret ×12, ref ×8, miss ×8. NOT Week-1 carryover. |
| Boolean rubrics (5 cats) | ✅ | `EvalGate.php` `schema_valid/citation_present/factually_consistent/safe_refusal/no_phi_in_logs` |
| >5% regression / floor gate logic | ✅ | `EvalGate.php` floor 0.90, tol 0.05 |
| Eval gate wired to PR-blocking CI | ❌ | no workflow invokes it (W1a) |
| Eval gate green on clean checkout | ❌ | `ref-07` fails: non-integer `page` accepted (W1b) |
| Per-encounter trace fields | 🟡 | missing retrieval-hits / extraction-confidence / eval-outcome as trace cols (W15) |
| `/health` + `/ready` exist | ✅ | distinct files |
| `/ready` probes W2 deps (doc storage, pgvector, reranker) | 🟡 | probes DB/LLM/breaker, not pgvector/doc-storage (W15) |
| Timeouts + retry + circuit breaker | ✅ | Guzzle timeouts, one-retry-then-degrade, `CadenceCircuitBreaker` |
| Dashboard (req/error/latency/eval-per-cat) | ✅ | `dashboard.php` incl. p50/p95 + per-rubric eval |
| Alerts (extraction-fail / RAG-latency / eval-regression) | 🟡 | framework exists, 3 named alerts absent (W15) |
| Cost & latency report | ✅ | `ops/cost-analysis.md` + `ops/load/RESULTS.md` |
| Bruno/Postman collection | ✅ | `ops/bruno/` incl. "05 - Week 2 Ingestion" |
| README W1 vs W2 + env docs | ✅ | `README.md` + `docs/configuration.md` |
| OpenAPI 3.0 spec | 🟡 | `ops/api/openapi.yaml` present; no contract tests (W9) |
| W2_ARCHITECTURE.md (named deliverable) | 🟡 | content in root `ARCHITECTURE.md` Part 2; confirm/point the named file (W14) |
| Backup & recovery plan (RPO/RTO) | ❌ | MISSING; golden-set-reproducible half done (W12) |
| Data model doc (owner/lineage/ACL/validation) | ❌ | MISSING; pieces scattered (W13) |
| Integration test (fixture doc → answer, stubbed) | 🟡 | covered as separate stubbed segments, not one flow (W1e) |
| Lab trend chart widget | ❌ | data exists, rendered as tables only (W16) |
| Third doc type | 🟡 | maps to RAG doc-insert flow; frame in docs (W17) |

---

## P0 — Graded non-negotiables (do these first)

### W1a — Wire the eval gate into PR-blocking CI
**Spec:** "Eval-driven CI is non-negotiable… we will introduce a small regression and
confirm your CI gate fails." A manually-installed, `--no-verify`-bypassable local hook
does not satisfy a grader.
**Do:**
- Add `.github/workflows/w2-eval-gate.yml` that, on `pull_request` and `push`, runs
  `php ops/eval/run-evals.php` and fails the job on non-zero exit. Also run the module
  PHPUnit contract/schema tests (W1c) and `ops/ci/check-additivity.sh` in the same
  workflow. (If the team prefers GitLab, add the equivalent job to `.gitlab-ci.yml` —
  but GitHub is the live deploy path now, so prefer `.github/workflows`.)
- Keep the existing `pre-push` installer as the local convenience path; the CI job is
  the authoritative gate.
**Acceptance:** open a throwaway PR that flips one eval expectation → the workflow is
**red**; revert → green. Document the seeded-regression demo in `W2_ARCHITECTURE.md`.
**Files:** `.github/workflows/w2-eval-gate.yml` (new), `ops/eval/run-evals.php`,
`ops/ci/run-eval-gate.sh`.

### W1b — Make the gate GREEN (fix `ref-07`)
**Symptom:** `php ops/eval/run-evals.php` exits 1 today: `safe_refusal` 93.8% because
`ref-07` (`page:"one"`, a string) is **not** refused — the extraction path accepts a
non-integer `page`.
**Do:** enforce `page` as a positive integer in `ExtractionSchema::validate()` (reject
non-int / string pages) so the bad case is refused. Confirm the lab schema requires an
integer `page`. Re-run; `safe_refusal` returns to 1.000. If the baseline itself is stale,
regenerate `ops/eval/baseline.json` **only after** the behavior is correct.
**Acceptance:** `php ops/eval/run-evals.php` exits 0; `ref-07` refused.
**Files:** `src/Ingest/ExtractionSchema.php`, `src/Ingest/schema/lab_pdf.schema.json`,
`ops/eval/cases.json`, `ops/eval/baseline.json`.

### W1c — Add contract/schema/extraction tests to the blocking suite
**Do:** ensure the CI workflow (W1a) runs the existing PHPUnit tests that guard the
supervisor-worker contract, schema validation, and extraction regression
(`tests/Db/Worker/WorkerTest.php`, `tests/Isolated/Ingest/ExtractionSchemaTest.php`,
`ExtractionAccuracyTest.php`, `tests/Isolated/Verify/ClaimSchemaTest.php`,
`tests/Isolated/Fact/FactSchemaValidationTest.php`). No new tests required unless a gap
is found; the fix is wiring them into the gate.
**Acceptance:** the CI job fails if any of these fail.

### W1d — OpenAPI contract tests
**Context:** `ops/api/openapi.yaml` (OpenAPI 3.0.3) exists and covers the Week-2 endpoints,
but **nothing tests the implementation against it** — it's hand-maintained.
**Do:** add a contract test that loads `openapi.yaml` and asserts each declared endpoint's
request/response shape matches the implementation (at minimum: endpoints exist, methods,
required params, response content-type). Wire it into the CI workflow (W1a).
**Files:** `ops/api/openapi.yaml`, `tests/…/OpenApiContractTest.php` (new).

### W1e — One end-to-end integration test (fixture doc → answer, fully stubbed)
**Context:** the pipeline is covered as *separately*-stubbed segments; there is no single
test walking a fixture document upload → VLM extract (stub) → ChartWriter commit →
retrieval (stub) → cited answer, runnable in CI without live APIs.
**Do:** add one integration test that drives the full ingestion→answer path over a fixture
PDF/image with `StubLlmClient`/`CountingLlmClient` and a real `SparseRetriever`/corpus.
Assert: source doc stored, facts committed, answer carries citations, critic runs.
**Files:** `tests/Db/…/W2EndToEndTest.php` (new), fixtures under `tests/fixtures/`.

> **NOTE — eval cases are already Week-2 (no +50 needed).** `ops/eval/cases.json` = 50
> cases, all Week-2: `ext-intake-*`/`ext-lab-*` (extraction, 22), `ret-*` (RAG retrieval,
> 12), `ref-*` (refusals, 8), `miss-*` (missing-data, 8) — the spec's exact categories,
> each carrying a `doc_type`. NOT Week-1 carryover. Citations are checked as a *rubric*
> across the extraction/retrieval cases, not a separate category. Optional enhancement:
> add citation-specific and agent-routing cases for depth — but the 50-case requirement is
> already met, so this is not a gap.

### W2 — Critic that actually blocks uncited/unsafe claims (HARD GATE)
**Spec:** "Critic agent that rejects uncited claims or unsafe action suggestions."
**Current:** the `Verifier` (V2 citation resolution, V5 banned-claim lint) is the critic,
but `VerificationPolicy::GATE_ENFORCED_DEFAULT = false` and it runs only on the
chat/synthesis read path — **not** on the Supervisor multi-agent flow.
**Do:**
- Flip `GATE_ENFORCED_DEFAULT` to `true` (or set `CLINICAL_COPILOT_VERIFY_ENFORCE=1` in
  every served environment — prefer the code default so a grader sees it enforced out of
  the box). Confirm V2/V5 then block/retry/degrade uncited + unsafe output.
- Add a **critic stage to the Supervisor path**: after the evidence-retriever/answer
  composition, run the verifier (or a thin `CriticWorker` wrapping it) so the multi-agent
  flow cannot emit an uncited/unsafe claim. Record it as a `worker`/`verify` child span.
- Update `docs/SECURITY.md` finding #1 and `HANDOFF.md` (the gate was intentionally off
  "for QA"; that rationale no longer applies for the Week-2 submission).
**Acceptance:** an eval/integration case with a fabricated uncited claim is **blocked**
(not just logged) on the agent path; a banned causation/dosing claim is refused.
**Files:** `src/Verify/VerificationPolicy.php`, `src/Verify/VerifiedGeneration.php`,
`src/Agent/Supervisor.php`, new `src/Agent/CriticWorker.php` (optional), tests.

### W3 — Wire the Supervisor into the live app
**Spec:** graders "run the core Week 2 flow" and must see supervisor routing + logged
handoffs in the deployed demo.
**Current:** `Supervisor` + both workers are correct but only referenced from
`tests/Isolated/Agent/SupervisorTest.php`. No production call site.
**Do:** add a real entry point that drives the supervisor graph end-to-end — a
`public/agent.php` (or route the existing chat "answer" action through the Supervisor)
that: accepts a patient + question, routes to extract/retrieve workers, runs the critic
(W2), returns a grounded, cited answer. Emit the full span tree. Add it to the API
collection (W10) and reference it in the demo script.
**Acceptance:** hitting the endpoint produces a correlation-ID-linked trace
`supervisor → worker(s) → critic`; the answer carries citations; visible in the
dashboard/trace UI.
**Files:** `public/agent.php` (new) or `src/Controller/ChatController.php`,
`src/Agent/Supervisor.php`.

---

## P1 — Required features with concrete gaps

### W4 — Restore the bounding-box overlay ON the real PDF + click-to-source
**Spec:** "A visual PDF bounding-box overlay is required" + "Click-to-source UI for
citation snippets."
**Current:** overlay removed in `0eade04`. **Bbox data is still stored and still passed to
the template** (`IngestController.php:203` sets `f.bbox`) — backend untouched.
**Do (must actually render the PDF, not a blank rectangle — that was the prior complaint):**
- Render the source PDF page to a canvas with a bundled **pdf.js** (or a server-rendered
  page image endpoint), and draw the normalized-0–1000 bbox as a highlight over the real
  page. Wire click-a-row / click-a-citation → scroll the pane to that page + flash the box.
- Add a `#page=N` deep-link on the citation cell as a minimum-viable fallback if pdf.js is
  too heavy for the timeline.
- Add a small render/interaction note to `W2_ARCHITECTURE.md`.
**Acceptance:** on the lab verify screen, each cited field highlights its region on the
actual rendered page; clicking a citation navigates the preview.
**Files:** `templates/oe-module-clinical-copilot/extraction_review.html.twig`,
`public/extraction_review.php`, asset bundling for pdf.js.

### W5 — Carry `collection_date` end-to-end
**Symptom:** schema + prompt request `collection_date`, but `ExtractionSchema::parse()`
never reads it; the chart date is human-entered (defaults to today).
**Do:** add `collectionDate` to `ParsedExtraction`, populate it in
`ExtractionSchema::parse()`, and use it as the default for
`procedure_order.date_collected` / `procedure_result.date` (human can still override on
the review screen). Add a parse test asserting the extracted date flows through.
**Acceptance:** uploading a lab PDF with a printed collection date prefills that date
(not today).
**Files:** `src/Ingest/ExtractionSchema.php`, `src/Ingest/ParsedExtraction.php`,
`src/Ingest/ChartWriter.php`, `public/extraction_review.php`, tests.

### W6 — Intake source citation, as OPTIONAL (satisfy spec without over-constraining)
**Context:** citations were removed from intake because *requiring* them over-constrained
the VLM and broke extraction. The spec still lists "source citation" for intake.
**Do:** re-add `page`/`quote` to `intake_form.schema.json` as **optional** properties (NOT
in `required`), so the model may cite when it can and the extraction never fails for a
missing citation. Parse them through when present. Surface them in the intake review UI
(non-blocking). This is present-when-available, matching the lab reconciliation reasoning.
**Acceptance:** intake extraction still succeeds with no citations; when the model returns
a page/quote, it is captured and displayed.
**Files:** `src/Ingest/schema/intake_form.schema.json`, `src/Ingest/ExtractionSchema.php`,
`templates/.../intake_review.html.twig`, tests.

### W7 — Rerank on the production (Postgres) path + full citation provenance
**Symptom:** `HeuristicReranker` is wired only into the offline `HybridRetriever`; the
deployed `PostgresGuidelineRetriever` returns raw `ts_rank`/cosine order — no rerank.
**Do:**
- Run the reranker inside `PostgresGuidelineRetriever` (over-fetch candidates, then
  `HeuristicReranker::rerank(query, candidates, topK)`), so the deployed config has a real
  second stage. (Optional stretch: add a `CohereReranker` behind `RerankerInterface` if an
  API key is available; keep heuristic as the default/fallback.)
- Propagate `section` + `url` into the citation: `EvidenceSnippet::forChunk()` currently
  passes `pageOrSection: null` — carry `chunk.section` (and url) so guideline citations
  cite the RAG DB fully (source + section + chunk id + quote). **This is the "narrative
  cites the RAG DB" requirement.**
**Acceptance:** the Postgres retrieval path reorders candidates via the reranker; guideline
citations in the answer include section/url provenance.
**Files:** `src/Knowledge/PostgresGuidelineRetriever.php`, `src/Rag/EvidenceSnippet.php`,
`src/Rag/HeuristicReranker.php`, tests.

### W8 — Deepen tracing to the spec's 4 levels + one correlation tree
**Symptom:** workers are child spans of the supervisor, but the `retrieve` span kind is
never recorded, the intake worker emits no `vision_extract` child, and the
ingest/`chart_commit` spans are disconnected root spans.
**Do:** record a `retrieve` child span inside `EvidenceRetrieverWorker` around the RAG
call, and a `vision_extract` child inside `IntakeExtractorWorker` around the VLM call;
thread the supervisor span id so ingest/`chart_commit` writes attach under the same tree
when driven from the agent path. Confirm a single correlation ID reconstructs
`supervisor → worker → {retrieve|vision_extract} → chart_commit`.
**Acceptance:** the dashboard waterfall for one correlation ID shows a connected 4-level
tree.
**Files:** `src/Agent/EvidenceRetrieverWorker.php`, `src/Agent/IntakeExtractorWorker.php`,
`src/Ingest/AttachAndExtract.php`, `src/Observability/TraceRecorder.php` (span kinds
already allow `retrieve`/`vision_extract`).

---

## P2 — Deliverable docs & observability wiring

> Audit result: OpenAPI spec, Bruno collection, cost/latency report, README W1/W2 split,
> health/ready, dashboard, and circuit breaker are **already present**. Only two docs are
> outright missing (W12, W13); the rest are small completeness/wiring fixes. (OpenAPI
> contract tests were moved to **W1d**.) Do NOT rewrite what exists — extend it.

### W12 — Backup & recovery plan (MISSING — net-new)
`RPO`/`RTO` appear nowhere in the repo. Write `docs/W2_BACKUP_RECOVERY.md`: how extracted
docs, derived FHIR records, and the eval golden set are backed up; automatic + manual
recovery procedures; RPO/RTO estimates. State clearly that the eval golden set is
**reproducible from the repo alone** (`ops/eval/cases.json` + `baseline.json` +
`run-evals.php`, deterministic, no DB) — that half is already satisfied.

### W13 — Data model / lineage / authority doc (MISSING — net-new)
Write `docs/W2_DATA_MODEL.md`. For each of the four artifact types — extracted lab
observations, intake facts, guideline chunks, citation records — define: authoritative
**owner** (which system/table is source of truth), **lineage** (where it came from →
`document_id`/`committed_core_pk` provenance), **access control** (ACL, per `SECURITY.md`),
and **validation rules** (the JSON schemas / validators). State the "one source of truth
per data type, no silent overwrites" invariant. Source material exists (schema comments in
`table.sql`, `docs/SECURITY.md`, `docs/knowledge-base.md`, the JSON schemas) — assemble it.

### W14 — W2_ARCHITECTURE.md naming + dangling ref (small)
Week-2 architecture content exists in repo-root `ARCHITECTURE.md` **Part 2 (§7–§12)** +
`docs/ingestion-failure-modes.md`. The spec asks specifically for a `W2_ARCHITECTURE.md`.
Create `docs/W2_ARCHITECTURE.md` — either move Part 2 into it or make it a structured
index that points into `ARCHITECTURE.md` §7–12 and covers the required sections (ingestion
flow, worker graph, RAG design, eval gate, risks, tradeoffs, **testing strategy**,
**Week-2 failure modes + recovery**). Fix the dangling `ARCHITECTURE_COMPLETE.md`
cross-reference (the file does not exist — repoint or remove). *(Correction at
execution time: root `ARCHITECTURE_COMPLETE.md` exists on `FINAL_REVIEW` and its
referenced anchors — U9/U10 rows, WORKER block, OPEN-2 — resolve; the refs were
verified valid and left in place.)* Also fold in the W3 seeded-
regression demo note (from W1a) and the W17 third-doc-type framing.

### W15 — `/ready` W2 deps + the three named alerts (small wiring)
- **`/ready`** (`src/Observability/ReadyCheck.php`) honestly returns ok/degraded/error but
  probes DB/LLM/breaker only. Add probes for the three named Week-2 deps: core
  **document storage** (a cheap read/write check), **pgvector / knowledge-Postgres**
  (reuse `KnowledgeBaseStatus`, already computed for the dashboard — wire it into `/ready`
  as `degraded` when down), and the reranker (local `HeuristicReranker` → report
  configured/available; only probe an API if a remote reranker is added in W7).
- **Alerts** (`src/Observability/Alert/`): the framework + several alerts exist, but the
  three spec-named ones are absent/aggregate. Add: extraction-failure-rate alert,
  RAG-retrieval-latency alert (RAG-specific, not aggregate p95), and an eval-regression
  alert (>5% drop in any category → the `EvalGate` `regressions` value is computed but not
  alerted; wire it). Document each alert's expected response action.
- **Trace fields** (optional, per Core Req 7): retrieval-hits, extraction-confidence, and
  eval-outcome are stored in separate tables, not the per-encounter trace. If time allows,
  surface them per correlation ID (a dashboard join is enough; a schema change is not
  required).

---

## P3 — Extensions (bonus points; do only if P0–P2 are green)

### W16 — Lab trend chart widget
Trend data exists (`src/Capability/VitalsTrend.php`, `LabSliceReader`) but renders as
delta tables. Add a small inline SVG sparkline/line-chart widget over the extracted
Observation series in `doc.html.twig` (no external chart lib — inline SVG, theme-aware).

### W17 — Third document type (framing + optional real type)
Present the **knowledge-document upload** (`knowledge_upload.php` / `KnowledgeDocumentIngestor`,
which ingests PDFs/images via the vision path into the RAG corpus) as the third ingestion
type in `W2_ARCHITECTURE.md` — honestly noting it feeds the guideline corpus rather than a
patient chart, with referral-fax / medication-list as the documented next patient-attached
type. Optionally implement a `medication_list` `DocType` if time allows.

---

## Suggested subagent batching

- **Wave 1 (P0, parallel):** W1a–e (eval-gate+CI, fix RED `ref-07`, wire PHPUnit/contract/
  integration tests), W2 critic-on+wired, W3 supervisor→production. These are the graded
  gates — land them first and verify the eval gate is green **and** CI-blocking.
- **Wave 2 (P1, parallel):** W4 bbox overlay (on real PDF), W5 collection_date, W6 intake
  citation (optional), W7 rerank-on-prod + citation provenance, W8 tracing depth.
- **Wave 3 (P2, parallel):** W12 backup/recovery (write), W13 data-model doc (write),
  W14 W2_ARCHITECTURE.md naming, W15 `/ready` deps + 3 named alerts.
- **Wave 4 (P3, optional):** W16 trend chart, W17 third-type framing.

After each wave: run the global acceptance checks. Do not proceed to the next wave with a
red eval gate.
