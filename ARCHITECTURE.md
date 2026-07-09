# Clinical Co-Pilot — Agent Integration Architecture

Status: Nothing here is implemented yet; it is the roadmap for Early/Final Submissions per the Clinical Co-Pilot Project Requirements Document.

---

## 1. Summary

The Clinical Co-Pilot is a conversational agent that gives a Physician Assistant (as defined in `USERS.md`) the ability to shape a distinct narrative to expedite patient interactions for the Practice's Physician.  The central design tension will be compliance with HIPAA.  

**Bottom Line Up Front.** The capability will be initially be a sidecar-type application.  Making the application embedded in the application will be discussed as part of a further stage of the project.

The reason that the sidecar has been chosen rather than an embedded appication is because of the information uncovered in the audit.  Because of the latency that results due to the `globals.php` file, a 3-query UUID→pid lookup, and a session write-lock held across the entire model call (as documented in `AUDIT.md` P-1 through P-6), a sidecar has been selected to take the request lifecycle that will cause latency of up to 60 seconds per request out of the processing chain.  

To that end, a Python + FastAPI service will be developed for this purpose.  Python was chosen since it is a well-established programming language that has robust libraries and documentation that can be used for this purpose.  FastAPI has been chosen for its compatibility with Python as well as its independence from network operations, which will provide relief from OpenEMR's latency issues, when the multi-turn strategy as discussed in `USERS.md` file employed.  The agent may need further information than what is displayed (UC-2) 

OpenEMR's existing REST/FHIR API is called by the agent using a scoped OAuth2 token minted from the user's session.  The authorization is inherited from OpenEMR.  That is, OpenEMR provides the Access Control List to the agent, and therefore dictates permissions to the agent.  The reason for this is that the Physician Assistant has extremely limited access to patient information: he or she cannot read patient information unless that patient is in his or her queue.  

Verification has two aspects.  The first occurs via source attribution.  That is, if the agent cannot find a record to source a claim, the claim cannot be stated.  An example for this is missing bloodwork; the agent will not claim the knowledge of bloodwork that does not exist on file for the patient.  Further, the second aspect must check information like dosage thresholds in order to keep the agent from inventing information.  

Langfuse will be employed to trace steps, per-step latency, tool failures, and token cost, with a dashboard (request/error counts, p50/p95/p99, tool-failure rate, verification pass-rate) and alerts. The eval suite targets boundaries, invariants, and regressions — missing data, ambiguous queries, and adversarial attempts to obtain patient information not in queue (UC-5).

PHI is another significiant issue documented in the `AUDIT.md` file that must be adressed critically upon developing an AI agent.  PHI sent to the LLM is gated by a fail-closed BAA/zero-retention flag (see C-5), every disclosure is logged with a correlation ID (C-1), and the payload is minimized to necessary clinical fields.

Among the issues that need to be addressed when implementing the agent are the following: hallucination → retrieval grounding + verification gate; latency → streaming/caching/concurrency; authorization bypass → token inheritance + adversarial evals; PHI exposure → BAA gate, minimum-necessary, disclosure auditing; tool/model failure → graceful degradation with transparent errors.

---

## 2. Deployment topology

```
 User's browser (OpenEMR patient chart)
        │  chat panel (UC-1..UC-5)
        ▼
 OpenEMR (PHP)  ──mint scoped OAuth2 token──►  Agent Service (FastAPI, Python)
   │  REST/FHIR API                               │  agent loop + tools + verification
   │  ◄───────── tool calls (as the user) ───┘
   ▼                                              │
 MySQL (system of record)                         ├─► Claude (BAA/ZDR)
                                                  └─► Langfuse (traces, cost) + dashboards
```


| Option | Pros | Cons | Verdict |
|--------|------|------|---------|
| Embed in OpenEMR PHP (like current `src/Services/AI`) | No new service; reuses auth/session | Inherits `globals.php` bootstrap, session write-lock (P-2), no streaming, weak eval/observability tooling in PHP | Rejected as the agent home |
| Separate FastAPI agent service | Escapes PHP lifecycle; streaming; Python agent/eval/observability ecosystem; independent scaling | New service to secure + deploy; cross-service auth | Chosen |

The existing PHP `PatientHistorySummaryService` is kept only as a thin, optional fallback in the event that the AI Agent is not available, which is a strong possibility in the even that access to the service providing the AI agent is unavailable.  

## 3. Data access & authorization boundaries

- The agent never holds standing credentials. When the user opens the chat, OpenEMR issues the OAuth2 token for the user; the agent uses it for every data call.
- Because tools hit the REST/FHIR API with her token, OpenEMR's ACLs (`AclMain`) decide what returns. The agent cannot exceed her access even if the model is tricked (mitigates UC-5, and the S-3 scope gap — we will require the *medical* scope, not `patients/demo`).
- Trust Boundaries: (1) browser↔OpenEMR (existing session/CSRF); (2) OpenEMR↔agent (token, mTLS/private network); (3) agent↔LLM (BAA/ZDR, TLS, minimum-necessary payload). PHI crosses boundary 3 only through the gate in §6.

## 4. Tools (contract-first)

Each tool has a strict input/output schema (for this, we will use Pydantic, which enforces data validation and constraints at runtime) that is the source of truth, wrapping a specific OpenEMR endpoint. Every output row carries a source reference used by verification.

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

Runs before any response reaches the user.  This is streamlined by the use of the sidecar as documented above.  

1. Source attribution — the agent must attach, to each clinical claim, the tool result / record ID it came from. Claims with no source are dropped or downgraded to "not found in record." The model only ever sees data returned by tools (retrieval grounding), so it cannot cite what it never received.  A condition will be enforced that the agent will inform the user when a record is not received.  
2. Domain-constraint enforcement using Railway — `check_drug_constraints` and similar deterministic checks run against structured data; a response asserting safety that the constraints contradict is blocked/flagged (UC-3). 
3. Structured outputs — schemas constrain the model's shape, reducing free-text fabrication.

## 6. Compliance & PHI handling (maps to AUDIT §6)

- BAA/ZDR gate (fixes C-5): a required, fail-closed config flag (`LLM_BAA_CONFIRMED` + `LLM_ZERO_RETENTION`) must be true or the agent refuses to transmit PHI. Per PRD, we operate as if a signed no-training BAA exists.
- Disclosure auditing (fixes C-1): every LLM call logs an audit/disclosure event — user, patient, timestamp, model, PHI categories, correlation ID — supporting §164.528 accounting.
- Minimum necessary: payloads carry only the clinical fields a use case needs; identifiers (name/DOB) excluded where feasible.
- At rest: rely on DB-/disk-level encryption for PHI (notes S-6); the agent stores no PHI beyond short-lived, time-boxed context cache.

## 7. Latency-prevention strategy 

- Stream the recap (first token ~1s) — the physician reads while generation continues.
- Concurrent independent tool calls (encounters/problems/meds/labs fetched in parallel).
- Cache per-patient context keyed on `(pid, max(record_updated_at))`; invalidate on new data (fixes "no caching," P-1).
- Lightweight lookups instead of the 3-query patient search (P-5); rely on indexed access and flag the missing `pubpid` index (P-6) for the deployment DB.
- Async posture: the agent service is non-blocking and never holds an OpenEMR session lock (avoids P-2).

## 8. Observability (engineering requirements)

- Correlation ID generated per agent invocation, propagated to every log line, tool call, and LLM request — a full trace reconstructable from logs alone.
- Traces & cost to Langfuse: step order, per-step latency, tool success/failure, tokens + $.
- Dashboard: request count, error rate, p50/p95/p99 latency, tool-call/retry counts, verification pass/fail rate.
- Alerts (≥3): p95 latency over threshold; error rate over threshold; tool-failure rate over threshold — each with a documented on-call response.
- `/health` vs `/ready`: `/health` = process alive; `/ready` = OpenEMR API, LLM provider, and Langfuse all reachable.

## 9. Evaluation

Test boundaries, invariants, regressions — never happy-path only. Each case documents the failure mode it guards:

- Boundary: new patient / empty record → "no history on file," never fabricated (UC-1).
- Boundary: ambiguous query → asks to clarify, doesn't guess (UC-2).
- Invariant: every clinical claim cites a source (UC-1/3/4).
- Invariant/adversarial: request for a non-panel patient or a prompt-injection exfiltration attempt → refused + logged, nothing leaked (UC-5).
- Domain: dangerous interaction/over-threshold dose → flagged, not affirmed (UC-3).
- Regression: cached context stale after new lab → cache invalidation returns fresh data.

Because the sample data is synthetic and clean, the eval set injects dirty-data conditions (missing fields, stale/duplicate records) that real charts have.

## 10. Failure modes & graceful degradation

| Failure | Behavior |
|---------|----------|
| A tool errors/times out | Return partial recap + explicit "couldn't retrieve X"; never fabricate |
| Record incomplete | State "not on file"; distinguish "no data" from "normal" |
| LLM returns malformed/unexpected output | Schema-validate; on failure, retry once then degrade to a plain grounded data view |
| LLM/BAA unavailable | Fail closed for PHI; offer non-AI fallback (raw retrieved data) |
| Verification blocks a response | Show the grounded facts + the flag; do not emit the unverified claim |

---

*Traceability: capabilities ↔ use cases are enumerated in `USERS.md` §5; risks ↔ findings reference `AUDIT.md` IDs (P-1..P-7, S-3, S-6, C-1, C-5).* 
