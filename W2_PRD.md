# PRD — Clinical Co-Pilot Week 2

**Multimodal Evidence Agent: document ingestion, audited chart write-back, and
evidence-grounded synthesis.**

This is the blueprint the whole of Week 2 is implemented against. It reconciles
the Week 2 requirements, what is already built, and what remains, as one source
of truth. Design detail lives in [`W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md); this
PRD owns *what* and *why*, acceptance criteria, and milestones. Already-built
code (M0–M3) is validated against this PRD, not rewritten.

---

## 1. Context & problem

Week 1 shipped a **read-only** pre-visit co-pilot for one outpatient
endocrinologist: deterministic PHP pulls + cites chart facts, the LLM only
narrates, three enforcement layers guarantee nothing is written. Week 2 is the
**inverse**: the important recent information arrives as messy documents (a
scanned lab PDF, a front-desk intake form). The co-pilot must *see* those
documents, extract structured facts with citations, let a human verify them,
write them into the chart traceably, and ground its recommendations in retrieved
guideline evidence — without losing the Week 1 discipline.

## 2. User & scenario

Single user profile (unchanged from Week 1): **one outpatient endocrinologist**
prepping for a follow-up visit. The chart has structured data, but the key
recent info is in a scanned lab PDF and an intake form. She asks: *what changed,
what should I pay attention to, and what evidence supports the recommendation?*

### 2.1 Traceability — every W2 capability back to the Week 1 user

Same discipline as Week 1's UC1–UC6 mapping in `USERS.md`: each capability
names the moment in the endocrinologist's follow-up-visit prep it serves.

| Capability | The moment in the physician-prep workflow it serves |
|---|---|
| Lab PDF ingestion (`lab_pdf`) | The scanned lab that arrived since the last visit becomes verified, cited `procedure_result` rows the Week 1 synthesis can read — instead of a PDF she'd have to eyeball mid-clinic. |
| Intake form ingestion (`intake_form`) | The front-desk intake form becomes a chart (patient + demographics, reviewed and corrected) before the first visit, so prep starts from structured data, not paper. |
| Medication-list ingestion (`medication_list`) | An outside med list (discharge list, pharmacy printout) is transcribed exactly as printed, human-verified, and frozen for reference while she preps regimen questions — chart reconciliation deliberately stays a manual clinical step. *(Added after this PRD's original two-type scope; design in `W2_ARCHITECTURE.md` §1–2.)* |
| Hybrid RAG + rerank | Answers her third question — *what evidence supports the recommendation?* — with cited guideline snippets kept separate from patient facts. |
| Supervisor + workers graph | One pre-visit question can gather document facts and guideline evidence in a single traced run (`agent.php`), instead of her chaining tools by hand. |
| Critic | Any drafted answer is verified (V1–V6) before she sees it — a rejected draft becomes a refusal, never uncited or unsafe prose in the 90-second prep window. |
| Eval gate | The flows above cannot silently regress between her clinic days — a >5% rubric regression blocks the PR. |
| Dashboard / observability | When a prep answer looks wrong, the full run is reconstructable by correlation id, and extraction accuracy per doc type is tracked without exposing PHI. |

## 3. Goals / non-goals

**Goals**
- Ingest two document types (lab PDF, intake form) with strict-schema extraction
  and per-fact citations.
- Write derived facts into the chart through one audited, idempotent seam.
- Human verification with an edit → lock lifecycle; accuracy measured for free.
- Hybrid RAG over a guideline corpus that **augments** the Week 1 summarizer and
  chat, keeping patient facts and guideline evidence separate.
- A deterministic supervisor + 2 workers with inspectable handoffs.
- A 50-case eval gate that blocks regressions (the spec's HARD GATE).

**Non-goals**
- A third document type, ColQwen2/multi-vector indexing, a real vector DB, an LLM
  supervisor — all explicitly out (the spec rewards narrowness).
- Rewriting the Week 1 read/verify pipeline; Week 2 is additive.

## 4. Functional requirements

- **FR-1 Intake flow.** Upload intake PDF → extract demographics/chief
  concern/meds/allergies/family history → create patient → review → lock →
  demographics in `patient_data`. Endpoint `public/intake_upload.php`. *(built)*
- **FR-2 Labs flow.** "Labs" tab; upload lab PDF **or** manual entry → extract →
  review → lock → results in `procedure_result` (round-trips to the Week 1
  reader). Endpoint `public/lab_upload.php`. *(built)*
- **FR-3 Strict schemas.** `intake_form.schema.json` / `lab_pdf.schema.json` are
  the source of truth; raw VLM output is validated + parsed before persist. *(built)*
- **FR-4 Citation contract.** Every extracted fact carries
  `{source_type, source_id, page_or_section, field_or_chunk_id, quote_or_value,
  bbox}`. Review UI shows click-to-source + preview; **canvas bbox overlay is
  owed (M6)**. *(partial)*
- **FR-5 Verify → lock lifecycle.** Draft editable; lock commits + freezes;
  elevated ACL unlock → correct → re-commit (append, no silent overwrite). *(built)*
- **FR-6 Audited write-back.** `ChartWriter` is the single sanctioned core-writer;
  idempotent + lineage; enforced by the PHPStan forbidden-write rule. *(built)*
- **FR-7 Hybrid RAG + rerank.** Sparse+dense retrieval over a committed guideline
  corpus, reranked; degrades to sparse-only with no creds. *(foundation built)*
- **FR-8 Evidence augments Week 1.** Summarizer renders a separate guideline-
  evidence section; chat gets a `get_guideline_evidence(topic)` tool. Guideline
  vs patient-fact citations never mix. **Wiring owed (M5).**
- **FR-9 Supervisor + 2 workers.** Deterministic router → `IntakeExtractor` +
  `EvidenceRetriever`; handoffs logged as child trace spans. *(built)*
- **FR-10 Eval gate.** 50 cases, boolean rubrics
  (`schema_valid, citation_present, factually_consistent, safe_refusal,
  no_phi_in_logs`), PR-blocking, fails on >5% category regression. **Owed (M4).**
- **FR-11 Observability.** Correlation id through every step; extraction accuracy
  (`vlm_value` vs verified `value`) per doc_type; PHI-free. *(built; dashboard
  panel owed, M5)*

## 5. Architecture (summary; full detail in `W2_ARCHITECTURE.md`)

- Ingestion pipeline: store source (`\Document`) → `ExtractionClient`
  (multimodal Gemini seam) → `ExtractionSchema` validate → `ExtractionStore`
  draft → `ExtractionReview` verify/lock → `ChartWriter` → core → Week 1 reads
  back.
- Two citation source types (`document`, `guideline`) as a value object separate
  from the Week 1 core-row `Citation` — Week 1 verifier untouched.
- Deterministic `Supervisor` over `IntakeExtractorWorker` +
  `EvidenceRetrieverWorker`; `src/Rag/` hybrid retriever degrades cleanly.

## 6. Data model

New module tables (registered in `table.sql`, `sql/install.sql`,
`sql/uninstall.sql`, `ModuleManagerListener::OWNED_TABLES`):
`mod_copilot_extraction` (header, draft→locked, accuracy) and
`mod_copilot_extracted_fact` (`vlm_value` vs `value`, page/bbox/quote, lineage).
Core tables written: `documents`, `patient_data`, `procedure_*`. Authority:
one source of truth per artifact, lineage columns link staging→core (see
`W2_ARCHITECTURE.md §12`).

## 7. Non-functional / engineering requirements (from the spec)

- **NFR-1 Typed contracts** on every interface (schemas, DTOs). *(built)*
- **NFR-2 Additivity** — no core files edited; CI gate. *(built, green)*
- **NFR-3 Read-only enforcement** relaxed to exactly one writer; PHPStan gate. *(built)*
- **NFR-4 Correlation id** across ingestion, workers, chart writes. *(built)*
- **NFR-5 Timeouts + retries** on all outbound LLM/retrieval calls. *(inherited
  from Week 1 LLM client; retrieval is local — verify/document.)*
- **NFR-6 SLOs + `/ready`** — extend `/ready` to check document storage + vector
  index/reranker reachability; add ingestion p95 SLO + alerts. **Owed (M5).**
- **NFR-7 Structured PHI-free logs** — extend the Week 1 schema; no parallel
  logging. *(built for traces/accuracy)*
- **NFR-8 CI** — schema-validation + contract + extraction-regression tests in the
  PR-blocking suite; dependency audit + security scan. **Owed (M4).**
- **NFR-9 OpenAPI 3.0 + Bruno** for the Week 2 endpoints; contract tests. **Owed (M7).**
- **NFR-10 Integration tests with fixtures + stubbed LLM/VLM**, pass in CI without
  live API. **Owed (M4).**
- **NFR-11 Backup/recovery** — corpus + schemas + golden set reproducible from
  repo; ledgers exported before uninstall. *(documented)*

## 8. Acceptance criteria

- Grader runs the core Week 2 flow (upload → verify → chart) for both doc types
  without guessing branch/env — README + `W2_ARCHITECTURE.md` make it clear.
- Introducing a small regression makes the eval gate fail (HARD GATE).
- Every clinical claim in output carries machine-readable citation metadata;
  patient-record facts and guideline evidence are separated.
- No raw PHI in logs/traces/eval data (PHI-detection check in CI).
- With no creds, the app still runs (manual entry + sparse-only retrieval).

## 9. Milestones & status

- **M0 Migrations + write-path domain** — *done, pushed.*
- **M1 Vision seam + endpoints + review UI + Labs tab** — *done, pushed.*
- **M2 RAG foundation (corpus, sparse+hybrid, rerank seam)** — *done, pushed.*
- **M3 Deterministic supervisor + 2 workers** — *done, pushed.*
- **M4 Eval gate** — 50-case golden set, boolean-rubric runner, PR-blocking,
  >5% regression fails (verified). *done, pushed.*
- **M5 Guideline-evidence surface** — `PatientEvidenceService` + `evidence.php` +
  "Guideline Evidence" tab: cited, topic-grouped evidence as a SEPARATE surface
  (kept off the verified chat/`Fact` pipeline by design). *done, pushed.*
- **M6 Bounding-box overlay** — interactive per-page citation map (inline SVG,
  no external renderer) on the review page. *done, pushed.*
- **M7 API surface + engineering docs** — OpenAPI 3.0 spec, Bruno collection,
  Week 2 cost/latency addendum, DB-backed staging-store test. *done, pushed.*
- **M8 Observability finish (still owed)** — dashboard Week 2 panel (documents
  ingested, per-doc-type accuracy, edit rate); `/ready` document-storage +
  retriever checks; ingestion p95 SLO + alerts. Plus full PHPUnit/PHPStan/PSR-12
  and the DB-backed core-write e2e (`AttachAndExtract`/`ChartWriter`) run in the
  dev stack, and the demo video/deployed link.

## 10. Testing strategy

Every test names the failure mode it guards. Isolated (no DB/model): schema
validation + each failure mode, citation round-trip, accuracy metric, extraction
against stub LLM, retrieval ranking + degradation, supervisor routing. DB-backed
(`tests/Db/`, dev stack): `AttachAndExtract` e2e, `ChartWriter` idempotency,
lock/unlock ACL. Golden set (M4): agent behavior + rubric. Static: additivity +
forbidden-write gates on every change.

## 11. Risks & tradeoffs

Core-write blast radius (mitigated: one writer, idempotent, lineage, lock gate,
PHPStan). Deterministic supervisor over LLM (deliberate; documented). Degrade-to-
manual everywhere with no creds. Intake creates patient at upload (asymmetry with
labs). bbox overlay partial until M6. (Full list: `W2_ARCHITECTURE.md §11`.)

## 12. Resolved decisions

- PRD lives at `W2_PRD.md` (repo root), whitelisted in the module additivity gate.
- Implementation order after the PRD: **M4 → M5 → M6 → M7** (eval gate first — the
  graded HARD GATE, buildable in the cloud env).
- DB-backed tests + full PHPStan/PHPUnit run in the dev stack at M7 (cannot run in
  the cloud env).
