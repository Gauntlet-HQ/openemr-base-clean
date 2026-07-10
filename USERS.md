# USERS.md — Clinical Co-Pilot: Target User, Workflow, Use Cases

> Canonical target-user deliverable. The case study's submission table also
> refers to it as `./USER.md`; a pointer stub at [`USER.md`](USER.md) redirects
> here so either name resolves.

**This document is the source of truth.** [ARCHITECTURE.md](ARCHITECTURE.md) and [ARCHITECTURE_COMPLETE.md](ARCHITECTURE_COMPLETE.md) must trace back to it; every capability built points to a use case here (traceability table at the bottom). Rationale for design decisions lives in [docs/clinical-copilot-tradeoffs.md](docs/clinical-copilot-tradeoffs.md).

---

## 1. The target user (one, deliberately narrow)

**Dr. S., outpatient endocrinologist.** Twenty-patient clinic days, 15-minute follow-up slots, panel dominated by established type-2 diabetes follow-ups. Charts in this OpenEMR fork. Labs arrive from two external labs and in-house draws; a meaningful fraction of her patients get prescriptions from other providers (PCPs, cardiologists) that arrive as reconciled med-list entries, not as her own orders.

**Her actual pain.** Pre-visit chart review is 3–5 minutes per patient spread across four surfaces: the labs tab (per-order, not per-analyte trend), the medications list, the vitals flowsheet, and the last visit note. Under schedule pressure the review gets shallow, and the failure modes are specific and recurring:

- a **corrected** lab value silently replacing the one she remembers;
- an **outside med change** she never sees because she only checks her own prescriptions;
- **overdue monitoring** (urine ACR, lipids) that nobody surfaces until an audit does;
- **duplicate orders** for labs drawn days ago but not yet resulted.

**Her tolerances — these constrain the agent.**
- *Latency:* pre-warmed docs must be effectively instant; a few seconds is acceptable on an explicit refresh. Anything slower than her current four-tab shuffle loses.
- *Trust:* every claim must be cited to a chart record. One uncited or wrong number and she stops opening the page — permanently. Facts with a missing narrative are acceptable; a narrative with wrong facts is fatal.
- *Refusals (what the agent must not do):* no causation claims ("A1c rose after the dose change" is fact; "because of" is banned), no treatment recommendations, no diagnoses, no hidden filtering (anything excluded must say so). She is the clinician; the agent is pre-visit synthesis.

## 2. The workflow moment

**8:50 AM.** Dr. S. is at her desk with the day's schedule open in OpenEMR's tabbed UI. In the thirty seconds before the copilot enters, she is deciding *which charts to open first* — triaging her own day from memory and the schedule grid. She opens the copilot page (menu item next to the schedule).

**What she needs from it:** one screen per scheduled patient — what changed since last visit, whether control is on target, whether the regimen is doing anything, what's overdue, what's in flight — prioritized, so patient #14's rising A1c outranks patient #3's stable everything.

**What she does with the output:** builds her mental agenda for each visit and queues lab orders — including *not* ordering what's already drawn. She does not document from it and it never writes to the chart (architecture D2/T3). By 9:00 she has done ten patients' worth of review in the time one used to take.

**The second moment — the follow-up (UC6).** Roughly a third of the time, the synthesis raises a question the document can't answer at its fixed depth: *"when exactly did the metformin dose change, and who prescribed it?"* — *"show me every A1c, not just the trend"* — *"same question for her lipids."* Today that question sends her back into the four-tab shuffle the copilot exists to eliminate. So each patient's synthesis carries an attached conversation: a chat panel pinned to that patient, preloaded with the exact fact set and narrative she is looking at, that can answer follow-ups by pulling more facts through the same deterministic capabilities — and nothing else.

## 3. Use cases

Each includes the hard-gate answer: **why an agent** — specifically, why LLM-narrated synthesis over deterministic facts (UC1–UC5), and why a multi-turn, tool-invoking conversation (UC6) — **and not a dashboard, sorted list, or better chart view.** The honest baseline for every answer: our facts *are* available as a table on the same page (facts-first rendering, invariant I6). The agent earns its place only where prose does work a grid cannot.

### UC1 — The 8:50 sweep: "what changed, what needs attention, in what order"
For every patient on today's schedule, a prioritized, cited synthesis, pre-warmed before clinic.
**Why an agent:** prioritization across heterogeneous domains (a censored A1c trend, an outside med change, an overdue ACR *with* a pending draw) is a judgment expressed in language — "worth your attention because X, despite Y" — not a sortable column. A dashboard can rank by any one signal; ranking across incommensurable signals with the reason attached *is* the product. This is also why one reduce pass exists at all (T1): ordering and emphasis are the LLM's only jobs in the synthesis pass. (The chat agent, UC6, adds one more — choosing which deterministic query to run next — navigation, never extraction; T14.)
**Traces to:** all five capabilities; worker (U9); read path (U8).

### UC2 — "Is control on target?"
A1c/glucose/lipid trajectory against thresholds, with censored values (`<7.0`), corrections, and unit conversions handled and cited.
**Why an agent:** the chart view already shows numbers; what it can't say is "three A1cs form a rising trend despite two being censored values, and the latest is a correction of the value you saw last month." That sentence — trend + data-quality caveats fused — is the 30-second version of what she currently reconstructs manually. A flowsheet showing the same rows leaves the fusion work with her.
**Traces to:** ControlProxy; lab slice contract C1–C4.

### UC3 — "Are the meds working?" (paired trend, no causation)
Med changes (from **both** her prescriptions and outside/reconciled meds — T4) laid against subsequent lab movement, citing both sides of every pairing.
**Why an agent:** pairing two data domains in time and narrating the juxtaposition — "metformin increased March 3; next two A1cs 8.1 → 8.4" — while *structurally refusing* the causal leap is exactly the narrate-don't-extract split. A dashboard can overlay two series; it cannot write the careful sentence, and an unguarded human reading the overlay writes the causal sentence in their head anyway. The agent's constraint (never asserts causation, enforced by fact schema + eval) is a feature no chart view has.
**Traces to:** MedResponse; VitalsTrend (weight/BP context for regimen changes).

### UC4 — "What's overdue — and what's already handled?"
Monitoring gaps per the cadence table (ACR annual, A1c quarterly…), computed from collection dates, with reorder suppression: "ACR overdue **but specimen drawn Tuesday — result pending, do not reorder.**"
**Why an agent:** honestly, the overdue *list* alone could be a report — and as a bare list it would cause harm, because "overdue" without "already drawn" invites the duplicate order (the exact failure the user named). The value is the composition: overdue-ness, in-flight orders, and preliminary results merged into one statement of what actually needs an action *versus what looks actionable but isn't*. That distinction is contextual synthesis, and it lives in the same prioritized doc as UC1–UC3 so there is no second surface to remember to check.
**Traces to:** OverdueTests; PendingResults; `mod_copilot_cadence` versioned config (ARCHITECTURE_COMPLETE.md).

### UC5 — "What's in flight?" (don't re-order, don't miss the preliminary)
Active orders without final results, and preliminary values, in a distinct in-flight section: "preliminary A1c 8.1 — final pending."
**Why an agent:** the underlying fact is a *absence* (order exists, result doesn't) — absences don't appear in any existing chart view; silence there reads as "no recent lab." Surfacing a non-event as information, phrased with what it implies for this visit ("result likely back before Thursday; defer"), is narration over a fact no dashboard row represents.
**Traces to:** PendingResults; lab contract C2 (preliminary handling).

### UC6 — "Wait — show me that": follow-up questions on the synthesis
A chat panel pinned to one patient, preloaded with the exact fact set + narrative behind the synthesis she just read, answering drill-down and clarification questions by invoking the same deterministic capabilities as read-only tools: "when did the metformin dose change and who prescribed it?", "list every A1c value, not just the trend", "is anything else overdue besides the ACR?"
**Why an agent — and specifically why multi-turn:** the space of follow-ups is unbounded and per-patient; a UI would need a screen per question shape. And her real follow-ups are *anaphoric* — "and the one before that?", "same for lipids", "so when should I re-draw?" — questions that are literally unparseable without the conversation that precedes them. That is the use case that requires multi-turn context; a stateless search box re-asks her to re-specify the patient, the analyte, and the time window on every turn, which is slower than the tabs she already has.
**Why tool chaining:** "did her weight change after the insulin started?" requires MedResponse (find the start date) *then* VitalsTrend (weights since that date) inside one turn — two tool calls where the second's arguments come from the first's result. One-shot retrieval cannot express it.
**Hard boundaries (same refusals as §1):** the agent answers from tool-returned facts only — every claim cited, verified against the fact set before display (see ARCHITECTURE.md, Verification); no causation claims, no recommendations, no diagnoses, no general medical knowledge Q&A ("what's the target A1c for her age?" → refused with a pointer to guidelines, not answered). Off-patient questions are refused: the session is pinned to one pid and the tools are structurally incapable of leaving it.
**Traces to:** all five capabilities as tools; chat agent + verification layer + session pinning (ARCHITECTURE.md); observability trace per turn.

## 4. Users this is deliberately NOT for (v1) — and what would have to change

Per the narrowness this document exists to enforce: these are named exclusions with reasons, not oversights.

| Excluded user | Why not v1 | What would have to change |
|---|---|---|
| **Nurse / MA (rooming workflow)** | Their moment is per-patient at rooming time, not the 8:50 sweep; their needs (vitals entry, med rec confirmation, standing-order protocols) are *write*-shaped, and this system is read-only by invariant (T3, T6). A synthesis tuned for physician triage is noise at rooming. | A rooming-specific doc variant (checklist-shaped, not prose), write-path integration for med rec — a different product on the same capability layer. |
| **Primary care physician** | The capability set is diabetes-narrow by design (code sets, cadence table, thresholds). A PCP's 20-patient day spans the whole problem-list universe; shipping to them with five diabetes capabilities would be the "physicians need help finding information" thesis this doc exists to reject. | Capability tuples per additional domain (the extension model supports it — one tuple + eval file each), per-domain cadence/threshold config, and a re-validated prioritization prompt. |
| **ED resident / hospitalist** | Different data shapes (inpatient encounters, med administration records vs. prescriptions), different latency/acuity tolerances, different EHR surfaces. Nothing about the 8:50 outpatient moment transfers. | Effectively a new USERS.md; only the fact/digest/ledger machinery carries over. |
| **The patient (via portal)** | PHI/LLM boundary is unresolved beyond synthetic data (OPEN-1); reading level, liability, and tone are unsolved product problems; portal is a separate auth domain. | OPEN-1 closed + a consumer-health product pass — out of scope indefinitely. |

## 5. Traceability (hard gate: every capability → a use case)

| Capability | UC1 | UC2 | UC3 | UC4 | UC5 | UC6 |
|---|---|---|---|---|---|---|
| ControlProxy | ● | ● | | | | ● |
| MedResponse | ● | | ● | | | ● |
| VitalsTrend | ● | | ● | | | ● |
| OverdueTests | ● | | | ● | | ● |
| PendingResults | ● | | | ● | ● | ● |
| (worker / pre-warm) | ● | | | | | |
| Chat agent (multi-turn, tool-invoking) | | | | | | ● |
| Verification layer | ● | ● | ● | ● | ● | ● |

The chat agent (UC6) adds no new data access: its tools *are* the five capabilities, patient-pinned and read-only. The verification layer is not a capability but a gate every LLM output (narrative and chat turn alike) passes before display.

No capability exists without a use case; no use case lacks a capability. Any Stage 5 capability proposal that cannot add a row here traceable to a UC — or a new UC defensible under §1's user — is rejected by construction.
