# Target User & Use Cases — Clinical Co-Pilot

**Document status:** source of truth for scope. Every agent capability in `ARCHITECTURE.md` must trace back to a use case defined here. If a capability does not map to a use case below, it is out of scope.

> Naming note: the PRD's Stage-4 hard gate names this file `./USERS.md` (the submission-requirements table calls it `./USER.md`). This repo uses `USERS.md` to match the hard-gate text.

---

## 1. The User (narrow and specific)

**Dr. Alani Reyes — primary care physician (family medicine), outpatient clinic.**

| Attribute | Detail |
|-----------|--------|
| Role | Attending PCP, board-certified family medicine |
| Setting | Mid-size ambulatory clinic on OpenEMR; not inpatient, not ED |
| Panel / day | **18–22 patients/day**, 15-minute slots, mix of chronic-disease follow-ups, acute visits, and a few annual wellness exams |
| Device | Clinic workstation + the exam-room computer already logged into OpenEMR; occasionally a tablet |
| Constraints | ~**90 seconds between rooms**; charts before the visit at most 8:50–9:00 AM; documents *during* and *after* each visit; chronically behind on the inbox |
| Access | Sees **her own panel** and covers for two partners on their days off; does **not** have org-wide access |
| Tolerance | Low tolerance for a confidently wrong answer; will abandon a tool that is slow (>a few seconds) or that she cannot trust; high tolerance for "I don't have that in the record" |

**Who this is *not*:** not an ED resident on overnight intake, not a hospitalist rounding on admissions, not a medical coder, not a patient. Those users have different data, latency, and refusal needs and are explicitly out of scope for Week 1.

### Secondary users (acknowledged, not primary)
- **Medical assistant (MA)** rooming the patient 5 minutes earlier — same clinic, *narrower* access.
- **Supervised resident** — same questions, but responses may need attending review.

These shape the **authorization** requirements (the agent must behave differently by who is asking) but the agent's capabilities are designed for Dr. Reyes.

---

## 2. The Workflow (where the agent enters her day)

**The 30 seconds before she opens it:** Dr. Reyes has just closed the previous patient's note, glanced at the schedule, and is walking to the next room. She knows the patient's name and maybe "diabetes follow-up." She has not re-read the chart. The patient is already waiting.

**What she needs from it:** in the time it takes to knock and sit down — *who is this, why are they here, what changed since last time, and what needs my attention today.*

**What she does with the output:** she walks in oriented, confirms/adjusts with the patient, and acts (orders, prescribes, refers, reassures). If something is flagged (an overdue lab, an abnormal result, a med gap), she addresses it in the visit instead of discovering it a week later in the inbox.

Two distinct moments:
1. **Morning sweep (8:50–9:00 AM):** a pass over today's schedule to pre-flag which patients have something that changed or needs attention.
2. **Per-room (all day):** a 90-second, patient-specific recap and then *follow-up questions* as the visit unfolds.

---

## 3. Use Cases

Each use case states the trigger, what the agent does, the data it touches, the acceptance bar, the failure mode it must handle, and **why a conversational agent is the right shape** (vs. a dashboard, sorted list, or better chart view).

### UC-1 — "Catch me up on this patient" (per-room recap)
- **Trigger:** Dr. Reyes opens the patient and asks, e.g., *"What do I need to know for today?"*
- **Agent does:** produces a 3–5 line grounded recap — reason for today's visit, what changed since the last encounter, active problems, current meds, most recent/abnormal labs — each claim attributable to a record.
- **Data:** encounters, problem list, prescriptions, recent lab results (`form_encounter`, `lists`, `prescriptions`, `procedure_result`).
- **Acceptance:** ≤ a few seconds to first content; every clinical claim cites a source; says "not on file" rather than inventing.
- **Failure mode guarded:** incomplete record (new patient, missing labs) → explicit "no prior history on file," never a fabricated summary.
- **Why an agent:** the *right* recap differs per patient and per visit; a fixed dashboard shows everything (too much in 90s) or the wrong slice. Natural language lets her ask for exactly today's relevant subset, and immediately follow up.

### UC-2 — Follow-up questions mid-visit (multi-turn)
- **Trigger:** during the visit — *"What were her last three A1c values?"* then *"and what's she currently on for it?"* then *"when was the last time we adjusted the dose?"*
- **Agent does:** answers each, **carrying context** (the patient, the condition just discussed) across turns without re-specifying.
- **Data:** labs trend, medication history (incl. recently discontinued), encounter notes.
- **Acceptance:** later turns resolve pronouns/context correctly; each answer grounded and source-cited.
- **Failure mode guarded:** ambiguous query ("the last result" — which test?) → asks a clarifying question rather than guessing.
- **Why an agent (multi-turn):** clinical reasoning is a conversation — each answer determines the next question. A dashboard cannot anticipate the branch; a search bar loses the thread between queries. **This is the use case that justifies multi-turn conversation and context retention.**

### UC-3 — Medication reconciliation & interaction check (tool chaining + verification)
- **Trigger:** *"She's on these meds — I'm about to add tramadol, any problem?"*
- **Agent does:** retrieves the current + recently-discontinued med list, then checks the proposed addition against interaction/domain rules, and **flags or refuses to affirm** anything that violates a constraint.
- **Data:** `prescriptions` (active + end-dated), a domain constraint source (interaction/dosage rules).
- **Acceptance:** the med list matches the record exactly; a real interaction is surfaced; the agent never says "no interactions" when it cannot verify.
- **Failure mode guarded:** domain-constraint violation (dangerous interaction, dose over threshold) → response is blocked/flagged, not stated as safe.
- **Why an agent (tool chaining):** this requires *sequential* tools — fetch meds, then evaluate a rule against them, then synthesize — and a verification gate on the output. **This is the use case that justifies tool chaining and the verification layer.**

### UC-4 — "Anything abnormal or overdue?" (proactive attention-flagging)
- **Trigger:** morning sweep, or *"anything I should flag today?"*
- **Agent does:** surfaces abnormal results since the last visit and overdue screenings/labs, grounded in the record, ranked by clinical salience.
- **Data:** `procedure_result` (abnormal flags), problem list, encounter dates.
- **Acceptance:** flags real abnormalities (e.g., high A1c, high WBC) with the value and date; no false "all normal."
- **Failure mode guarded:** stale data / missing result dates → distinguishes "no recent labs" from "labs normal."
- **Why an agent:** salience is contextual and explainable on demand ("*why* did you flag this?") — a static abnormal-flag list cannot justify itself or let her drill in.

### UC-5 — Authorization-aware refusal (safety invariant, all use cases)
- **Trigger:** a request for a patient **not on her panel/coverage**, or a request from a lower-privilege user (MA), or an attempt to extract data beyond the asker's rights.
- **Agent does:** enforces the *asking user's* access; refuses or limits, and says why — without leaking the protected content.
- **Data:** the user's identity/scope (inherited from OpenEMR auth), the patient-to-provider relationship.
- **Acceptance:** no cross-panel disclosure; refusal reveals nothing about the withheld record; the attempt is audit-logged.
- **Failure mode guarded:** privilege escalation / data-exfiltration prompt → denied and logged.
- **Why an agent:** the agent is a new query surface over PHI; if it does not itself enforce access it becomes a bypass of OpenEMR's ACLs. This is a **non-negotiable invariant**, tested adversarially (ties directly to audit findings S-3 and the AI ACL gap).

---

## 4. Explicit Non-Goals (what it must refuse or defer)

- **Not** a generic medical Q&A / differential-diagnosis chatbot ("what causes chest pain?" untethered to the record).
- **Not** an autonomous order/prescribe actor — it drafts and flags; the physician acts. Human-in-the-loop for any write.
- **Not** a coder/biller assistant.
- **Not** a cross-patient population query tool ("show me all my diabetics with A1c > 9") in Week 1 — panel-level analytics is a different user and access model.

---

## 5. Traceability (capabilities → use cases)

| Agent capability | Justified by | Would be unjustified without |
|------------------|--------------|------------------------------|
| Multi-turn conversation + context | UC-2 | (would be a search bar) |
| Tool chaining | UC-3 | (would be single-shot lookups) |
| Verification / source attribution | UC-1, UC-3, UC-4 | (would be an ungrounded chatbot) |
| Domain-constraint enforcement | UC-3 | (would be unsafe) |
| Proactive flagging / ranking | UC-4 | (would be a dashboard) |
| Authorization-aware refusal | UC-5 (invariant) | (would be an ACL bypass) |
| Low-latency first response | UC-1, per-room 90s window | (would be unusable at the point of care) |

**The bar:** not that an agent is technically possible, but that this conversational agent is the thing Dr. Reyes would *choose* in her 90-second window over any static view.
