# Clinical Co-Pilot — Agent Integration Architecture

**Status:** forward-looking plan (Stage 5). Nothing here is implemented yet; it is the roadmap for Early Submission. Every capability traces to a use case in [`USERS.md`](./USERS.md) and a finding in [`AUDIT.md`](./AUDIT.md).

---

## 1. One-Page Summary (~500 words)

The Clinical Co-Pilot is a conversational agent that gives a primary-care physician (Dr. Reyes, `USERS.md`) a grounded, source-cited patient recap and follow-up answers inside her 90-second between-rooms window. The central design tension, straight out of the audit, is **trust vs. speed under HIPAA**: an ungrounded or non-compliant answer is worse than no tool.

**Where the agent lives.** A **separate agent service** (Python + FastAPI) deployed alongside the OpenEMR fork, *not* embedded in OpenEMR's PHP request cycle. This is the pivotal decision. The audit showed the existing in-PHP AI feature inherits OpenEMR's worst latency traits — a full ~863-line `globals.php` bootstrap per call, a 3-query UUID→pid lookup, and a session write-lock held across the entire model call (`AUDIT.md` P-1..P-6). A sidecar service escapes that request lifecycle, lets us stream tokens, cache aggressively, and use a mature agent/eval/observability stack the PHP codebase lacks.

**How it accesses data.** The agent calls OpenEMR's existing **REST/FHIR API** as the **logged-in clinician**, using a scoped OAuth2 token minted from her session — never a superuser service account. Authorization is therefore *inherited*: OpenEMR's ACLs remain the single enforcement point, and the agent physically cannot read a patient outside the clinician's rights. Each tool maps to a specific endpoint (patient, encounters, problems, meds, labs) with a strict schema.

**Trust model (verification).** Two layers before any response reaches the user: (1) **source attribution** — every clinical claim must carry a reference to the record it came from; unattributable claims are withheld; (2) **domain-constraint enforcement** — a deterministic check for medication interactions/dose thresholds that can flag or block a response the data does not support. Grounding is retrieval-based (the model only sees data we fetched), and structured tool outputs (Pydantic contracts) keep the model from inventing fields.

**Speed strategy.** Stream the recap so first content appears in ~1s; run independent tools concurrently; cache per-patient context keyed on the record's last-updated time (directly addressing the "no caching" finding, P-1). Defer completeness — surface the salient subset first, let follow-up turns (UC-2) pull more.

**Observability & eval (from day one).** Every invocation gets a **correlation ID** propagated across the agent, every tool call, and every LLM interaction. We trace steps, per-step latency, tool failures, and token cost to Langfuse, with a dashboard (request/error counts, p50/p95/p99, tool-failure rate, verification pass-rate) and alerts. The eval suite targets **boundaries, invariants, and regressions** — missing data, ambiguous queries, and adversarial "read a patient I'm not allowed to see" attempts (UC-5).

**Compliance.** PHI to the LLM is gated by a fail-closed BAA/zero-retention flag (fixing the comment-only control, C-5), every disclosure is audit-logged with the correlation ID (C-1), and the payload is minimized to necessary clinical fields.

**Top risks & mitigations:** hallucination → retrieval grounding + verification gate; latency → streaming/caching/concurrency; authorization bypass → token inheritance + adversarial evals; PHI exposure → BAA gate, minimum-necessary, disclosure auditing; tool/model failure → graceful degradation with transparent errors.

---

## 2. Deployment topology — where the agent lives

```
 Clinician's browser (OpenEMR patient chart)
        │  chat panel (UC-1..UC-5)
        ▼
 OpenEMR (PHP)  ──mint scoped OAuth2 token──►  Agent Service (FastAPI, Python)
   │  REST/FHIR API                               │  agent loop + tools + verification
   │  ◄───────── tool calls (as the clinician) ───┘
   ▼                                              │
 MySQL (system of record)                         ├─► LLM provider (BAA/ZDR)
                                                  └─► Langfuse (traces, cost) + dashboards
```

**Decision: sidecar service, not in-PHP.**

| Option | Pros | Cons | Verdict |
|--------|------|------|---------|
| Embed in OpenEMR PHP (like current `src/Services/AI`) | No new service; reuses auth/session | Inherits `globals.php` bootstrap, session write-lock (P-2), no streaming, weak eval/observability tooling in PHP | Rejected as the agent home |
| **Separate FastAPI agent service** | Escapes PHP lifecycle; streaming; Python agent/eval/observability ecosystem; independent scaling | New service to secure + deploy; cross-service auth | **Chosen** |

The existing PHP `PatientHistorySummaryService` is kept only as a thin, optional fallback; the real agent is the sidecar.

## 3. Data access & authorization boundaries

- **Identity:** the agent never holds standing credentials. When Dr. Reyes opens the chat, OpenEMR issues a **short-lived, scoped OAuth2 token** for *her*; the agent uses it for every data call.
- **Enforcement point stays in OpenEMR:** because tools hit the REST/FHIR API with her token, OpenEMR's ACLs (`AclMain`) decide what returns. The agent cannot exceed her access even if the model is tricked (mitigates UC-5, and the S-3 scope gap — we will require the *medical* scope, not `patients/demo`).
- **Trust boundaries:** (1) browser↔OpenEMR (existing session/CSRF); (2) OpenEMR↔agent (token, mTLS/private network); (3) agent↔LLM (BAA/ZDR, TLS, minimum-necessary payload). PHI crosses boundary 3 only through the gate in §6.
- **No cross-patient / population queries** in v1 (matches `USERS.md` non-goals).

## 4. Tools (contract-first)

Each tool has a strict input/output schema (Pydantic) that is the source of truth, wrapping a specific OpenEMR endpoint. Every output row carries a **source reference** used by verification.

| Tool | Backs use case | OpenEMR data | Notes |
|------|----------------|--------------|-------|
| `resolve_patient` | all | patient by UUID | avoids the heavy 3-query search where possible (P-5) |
| `get_recent_encounters` | UC-1, UC-2 | `form_encounter` | bounded/limited (guards P-7) |
| `get_problem_list` | UC-1, UC-2 | `lists` | active problems |
| `get_medications` | UC-2, UC-3 | `prescriptions` | includes recently discontinued |
| `get_lab_results` | UC-1, UC-4 | `procedure_result` | abnormal flags + dates |
| `check_drug_constraints` | UC-3 | interaction/dose rule set | deterministic; feeds verification |

Tool failures return a typed error the agent surfaces transparently ("couldn't retrieve labs") rather than guessing (failure-mode requirement).

## 5. Verification layer

Runs **before** any response reaches the user:

1. **Source attribution** — the agent must attach, to each clinical claim, the tool result / record id it came from. Claims with no source are dropped or downgraded to "not found in record." The model only ever sees data returned by tools (retrieval grounding), so it cannot cite what it never received.
2. **Domain-constraint enforcement** — `check_drug_constraints` and similar deterministic checks run against structured data; a response asserting safety that the constraints contradict is **blocked/flagged** (UC-3). 
3. **Structured outputs** — schemas constrain the model's shape, reducing free-text fabrication.

**Known limitations (to document):** verification confirms *attribution and constraints*, not clinical correctness of the physician's decision; it cannot catch an error already present in the source record; interaction rules are only as complete as the rule set.

## 6. Compliance & PHI handling (maps to AUDIT §6)

- **BAA/ZDR gate (fixes C-5):** a required, fail-closed config flag (`LLM_BAA_CONFIRMED` + `LLM_ZERO_RETENTION`) must be true or the agent refuses to transmit PHI. Per PRD, we operate as if a signed no-training BAA exists.
- **Disclosure auditing (fixes C-1):** every LLM call logs an audit/disclosure event — user, patient, timestamp, model, PHI categories, correlation ID — supporting §164.528 accounting.
- **Minimum necessary:** payloads carry only the clinical fields a use case needs; identifiers (name/DOB) excluded where feasible.
- **At rest:** rely on DB-/disk-level encryption for PHI (notes S-6); the agent stores no PHI beyond short-lived, time-boxed context cache.

## 7. Speed strategy (maps to AUDIT §3)

- **Stream** the recap (first token ~1s) — the physician reads while generation continues.
- **Concurrent** independent tool calls (encounters/problems/meds/labs fetched in parallel).
- **Cache** per-patient context keyed on `(pid, max(record_updated_at))`; invalidate on new data (fixes "no caching," P-1).
- **Lightweight lookups** instead of the 3-query patient search (P-5); rely on indexed access and flag the missing `pubpid` index (P-6) for the deployment DB.
- **Async** posture: the agent service is non-blocking and never holds an OpenEMR session lock (avoids P-2).

## 8. Observability (engineering requirements)

- **Correlation ID** generated per agent invocation, propagated to every log line, tool call, and LLM request — a full trace reconstructable from logs alone.
- **Traces & cost** to Langfuse: step order, per-step latency, tool success/failure, tokens + $.
- **Dashboard:** request count, error rate, p50/p95/p99 latency, tool-call/retry counts, verification pass/fail rate.
- **Alerts (≥3):** p95 latency over threshold; error rate over threshold; tool-failure rate over threshold — each with a documented on-call response.
- **`/health` vs `/ready`:** `/health` = process alive; `/ready` = OpenEMR API, LLM provider, and Langfuse all reachable.

## 9. Evaluation

Test **boundaries, invariants, regressions** — never happy-path only. Each case documents the failure mode it guards:

- **Boundary:** new patient / empty record → "no history on file," never fabricated (UC-1).
- **Boundary:** ambiguous query → asks to clarify, doesn't guess (UC-2).
- **Invariant:** every clinical claim cites a source (UC-1/3/4).
- **Invariant/adversarial:** request for a non-panel patient or a prompt-injection exfiltration attempt → refused + logged, nothing leaked (UC-5).
- **Domain:** dangerous interaction/over-threshold dose → flagged, not affirmed (UC-3).
- **Regression:** cached context stale after new lab → cache invalidation returns fresh data.

Because the sample data is synthetic and clean (`AUDIT.md` §5), the eval set **injects** dirty-data conditions (missing fields, stale/duplicate records) that real charts have.

## 10. Failure modes & graceful degradation

| Failure | Behavior |
|---------|----------|
| A tool errors/times out | Return partial recap + explicit "couldn't retrieve X"; never fabricate |
| Record incomplete | State "not on file"; distinguish "no data" from "normal" |
| LLM returns malformed/unexpected output | Schema-validate; on failure, retry once then degrade to a plain grounded data view |
| LLM/BAA unavailable | Fail closed for PHI; offer non-AI fallback (raw retrieved data) |
| Verification blocks a response | Show the grounded facts + the flag; do not emit the unverified claim |

## 11. Open decisions (to resolve before Early Submission)

- LLM provider/model (structured-output support, context window, cost/latency) and the exact BAA/ZDR terms.
- Agent framework (single-agent tool-caller vs. graph) — leaning single-agent for v1 scope.
- Domain-constraint source (drug-interaction dataset/service) and its coverage/licensing.
- Deployment target/host (must match Stage 2's public deployment) and the OpenEMR↔agent network boundary.

---

*Traceability: capabilities ↔ use cases are enumerated in `USERS.md` §5; risks ↔ findings reference `AUDIT.md` IDs (P-1..P-7, S-3, S-6, C-1, C-5).* 
