# Target User & Use Cases — Clinical Co-Pilot

## 1. The User 

**A Physician Assistant**

The User was selected because of the increasingly relevant role of the Physician Assistant in the modern Primary Care Office.  The Physician Assistant will generally handle intake and collect relevant information from the patient before the Physician sees the patient, assist the Physician, act as witness to primary care and provide reporting and follow-up information.  As such, this person is critical in developing the narrative for the visit, and will frequently also make recommendations to the patient in lieu of the Physician, who may be attending to other patients at the end of the visit.  

| Attribute | Detail |
|-----------|--------|
| Role | Physician Assistant, certified by the relevant board |
| Setting | Small outpatient clinic |
| Panel / day | **18–22 patients/day**, 15-minute slots, mix of chronic-disease follow-ups, acute visits, and a few annual wellness exams |
| Device | Clinic workstation + the exam-room computer already logged into OpenEMR; occasionally a tablet; the tablet is connected to the internet and may be connected to a larger network because the Physician has several offices |
| Constraints | ~**90 seconds between rooms**; charts must be prepared before Physician sees them; all intake information must be complete before the Physician sees the charts |
| Access | **Does not have domain-wide access**, does not have access to editing patient notes and recommendations because that is the duty of the Physician |

---

## 2. The Workflow

The Physician Assistant enters the patient room where the patient is waiting.  The Physician Assistant takes relevant information (blood pressure, temperature, reason for visit, prescriptions, previously-existing conditions) and enters that information into the system.  

The AI agent takes this information and creates brief for the Physician.  The brief consists of a bulleted list of no more than 300 words that compiles the information taken by the Physician Assistant that the Physician will use to discuss with the patient.  

The Physician Assistant may use the AI agent to also enter in new prescription information, relevant tests to be performed or prescribed, but may not enter in Physician recommendations.  The Physican Assistant may ask the AI agent to incorporate patient history (previous visits, discontinued medication, complimentary lab orders from other Physicians) into the brief.  

---

## 3. Use Cases

The Use Cases should narrowly define what the AI Agent should do.  Note that the scope is intentionally narrow to avoid scope creep.  

### UC-1 — Intake: Provides data for agent to process
- The Physician Assistant takes intake information from the patient.  
- The Agent produces a 3–5 line grounded recap — reason for today's visit, what changed since the last encounter, active problems, current meds, most recent/abnormal labs — each claim attributable to a record.
- Data required for this visit: problem list, prescriptions, recent lab results (`form_visit`, `lists`, `prescriptions`, `procedure_result`).
- If a field is empty or no data is found, the agent returns the message "not on file".  
- If a record is incomplete, (new patient, missing labs), the agent returns the message "no prior history on file," never a fabricated summary.

### UC-2 — Mid-Visit: Agent uses the data to provide Physician Assistant to create a narrative for the Physician
- Discussion of lab work and effects of prescriptions (e.g., A1c and use of Metformin)
- Agent provides lab trends, medication history (incl. recently discontinued), accepts notes. 
- Agent asks a clarifying question if information is missing (no prior labs, other medications used previously) rather than guessing.

### UC-3 — Medication reconciliation & interaction check: Agent adds relevant information to interaction
- Agent retrieves the current + recently-discontinued med list, then checks the proposed addition against interaction/domain rules, and any prescription interactions/counterindications/dosage rules. 
- Agent never says "no interactions" when it cannot verify, it asks for further clarification if history is incomplete, or if a medication needs to be noted/warned here.  

### UC-4 — Agent calls attention to potential issues
- Agent identifies abnormal results since the last visit and overdue screenings/labs, grounded in the record, ranked by clinical salience.
- Agent identifies stale data / missing result dates → distinguishes "no recent labs" from "labs normal."

### UC-5 — Authorization-aware refusal: permissions
- Agent prevents fulfilling a request beyond the asker's rights (patient is not assigned to PA, access to editing Physician notes).
- Agent enforces access; refuses or limits, and says why — without leaking the protected content.
- Data required: the user's identity/scope (inherited from OpenEMR auth), the patient-to-provider relationship.
- Agent prevents cross-panel disclosure; refusal reveals nothing about the withheld record; the attempt is audit-logged.
- Agent prevents and logs attempts at privilege escalation / data-exfiltration prompt.

---

## 4. Traceability (capabilities → use cases)

| Agent capability | Justified by | Would be unjustified without |
|------------------|--------------|------------------------------|
| Multi-turn conversation + context | UC-2 | (would be a search bar) |
| Tool chaining | UC-3 | (would be single-shot lookups) |
| Verification / source attribution | UC-1, UC-3, UC-4 | (would be an ungrounded chatbot) |
| Domain-constraint enforcement | UC-3 | (would be unsafe) |
| Proactive flagging / ranking | UC-4 | (would be a dashboard) |
| Authorization-aware refusal | UC-5 (invariant) | (would be an ACL bypass) |
| Low-latency first response | UC-1, per-room 90s window | (would be unusable at the point of care) |


