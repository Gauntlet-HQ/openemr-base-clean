# Security Audit — Clinical Co-Pilot Module

**Scope:** `interface/modules/custom_modules/oe-module-clinical-copilot/` (src/, public/, ops/, templates/, sql/) plus the root-level Railway deploy scripts (`railway-*.sh`, `Dockerfile.railway`). Stock/unmodified OpenEMR core (`library/`, the rest of `interface/`, the rest of `src/`) was explicitly out of scope for this pass — this fork's only custom surface is this module and its Google Gemini / Vertex AI LLM integration.

**Method:** read-only fan-out audit, one agent per subtree (Chat/Agent/Verify, Ingest/Doc, Knowledge/Rag, Controller/ReadPath/Fact/Capability, Config/Observability/Worker/Reduce/Lab, public/templates, ops/sql/deploy), each sampling entry points, auth, query construction, file handling, and secrets, then reduced into this report. No source was modified and nothing was exploited — every finding below describes the unsafe construct, not a working payload.

**Posture summary:** This module is unusually disciplined for its size — parameterized queries throughout, no hardcoded secrets, consistent PSR-3 logging, and a real architectural separation between the PHI-bearing OpenEMR MySQL and the non-BAA knowledge Postgres. The one **CRITICAL** finding is not an injection or auth-bypass bug in the traditional sense: it's that the module's own headline safety control — the LLM output verification gate — was turned off by default in the code at audit time (since fixed on FINAL_REVIEW: enforced by default, see Resolution status). Two **HIGH** findings are IDOR-shaped (cross-patient data access via ID enumeration), one is a stored-XSS gap on an admin page, and two are gaps in the PHI-mixing / PHI-scrubbing guards that are described elsewhere as hard protections but currently fail open. The **MEDIUM** and **LOW** findings are mostly defense-in-depth gaps and deploy-script hygiene rather than directly exploitable bugs.

---

## Resolution status (post-audit)

Tracked disposition of the findings acted on since this report was written. Fixes land as `fix(clinical-copilot): …` commits; docs-only reconciliations update ARCHITECTURE.md / this file in place. Findings not listed here remain open with the disposition described in their entry below.

| # | Sev | Disposition | Where |
|---|-----|-------------|-------|
| 1 | CRITICAL | **Fixed (FINAL_REVIEW)** — `GATE_ENFORCED_DEFAULT` flipped back to `true`: the V1–V6 gate blocks/retries/degrades by default on the chat, synthesis, **and** supervisor multi-agent paths (new `CriticWorker` stage). The earlier "off for QA retuning" rationale no longer applies for the Week-2 submission. `CLINICAL_COPILOT_VERIFY_ENFORCE=0` remains a QA-only relaxation; V3 sev-1 stays unconditional. | `src/Verify/VerificationPolicy.php`, `src/Agent/CriticWorker.php` |
| 3 | HIGH | **Fixed** — scrubber switched from a capitalization blacklist to a clinical-term allowlist (`CLINICAL_TERMS` + analyte-code regex); lowercase names no longer leak. Regression test added. | commit `804f2b1` / `KnowledgeQueryScrubber` |
| 4 | HIGH | **Documented as accepted** — chart-wide ACL matches stock OpenEMR's own chart-access model; per-patient/care-team scoping is a deliberate non-goal for v1, with the controller layer named as the extension point if the fork ever tightens it. Reconciled in ARCHITECTURE.md §4. | ARCHITECTURE.md §4 |
| 5 | HIGH | **Fixed** — `span.error_detail` now renders through `\|text` on the dashboard. | commit `9214a3c` / `dashboard.html.twig:406` |
| 6 | HIGH | **Fixed** — `extraction_review.php` binds `extraction_id` to the session patient context via `IngestController::extractionPatientId()`; mismatch → 403 + logged warning. | commit `804f2b1` |
| 12 | MEDIUM | **Fixed** — the "tags are non-PHI by construction" assumption is now enforced, not assumed: `KnowledgeQueryScrubber::scrubTags()` (`src/Knowledge/KnowledgeQueryScrubber.php:143-168`) allowlists every retrieval tag against the closed clinical vocabulary (analyte-code shape + `CLINICAL_TERMS` + the corpus/topic `TAG_VOCABULARY`) before it crosses to the non-BAA store, and `PostgresGuidelineRetriever::safeTags()` (`src/Knowledge/PostgresGuidelineRetriever.php:258-261`) routes through it instead of bare `TagNormalizer` — an unrecognized tag is dropped, both from the SQL `tags &&` bind params and from the full-text keyword bag. Regression tests added (`tests/Isolated/Knowledge/KnowledgeQueryScrubberTest.php`). | `src/Knowledge/KnowledgeQueryScrubber.php`, `PostgresGuidelineRetriever.php` |
| 14 | MEDIUM | **Fixed** — `ChatController::logChatTurn()` (`src/Controller/ChatController.php:827-850`) no longer logs `provider_message` / `assistant_answer`; the record is metadata-only (correlation/session ids, verify status, confidence, attempts, tool-call names/count, tokens, latency, model) plus `provider_message_chars` / `assistant_answer_chars` / `claim_count`, so the `no_phi_in_logs` guarantee now holds on the chat path. Full turn text remains only on the access-controlled `mod_copilot_chat_turn` row. | `src/Controller/ChatController.php` |
| 18 | MEDIUM | **Fixed** — added the `PHP_SAPI !== 'cli'` guard, matching sibling ops scripts, so a direct HTTP request can no longer boot the bootstrap with `$ignoreAuth = true`. | `ops/knowledge/ingest_document.php` |
| 20 | MEDIUM | **Documented as as-built gap** — the SELECT-only MySQL user is a planned defense-in-depth *target*, not wired; the enforced read-only control is the module-scoped PHPStan write-forbidding rule (layer 1). A real split needs two DB roles because `ChartWriter`/`mod_copilot_*` legitimately write. Reconciled in ARCHITECTURE.md §4. | ARCHITECTURE.md §4 |

---

## CRITICAL

### 1. The V1–V6 clinical-safety verification gate is disabled by default
**CWE-670 (Always-Incorrect Control Flow Implementation) / CWE-841 (Improper Enforcement of Behavioral Workflow)**
**Location:** `src/Verify/VerificationPolicy.php:46` (`GATE_ENFORCED_DEFAULT = false`); consumed by `src/Chat/ChatAgent.php:122-134` and `src/Verify/VerifiedGeneration.php:123-146`

`VerificationPolicy::gateEnforced()` defaults to `false` unless the operator explicitly sets `CLINICAL_COPILOT_VERIFY_ENFORCE=1`. The class's own docblock states this is "TEMPORARILY DISABLED for QA... while it is being retuned." With the gate off, the verifier still **runs** and records its verdicts to the trace/ledger (so the dashboard shows what *would* have failed), but citation resolution (V2), numeric grounding (V4), and the banned-claim/causation/diagnosis lint (V5) no longer **block, retry, or degrade** a failing answer — an answer that fails those checks is rendered to the physician anyway. Only the V3 wrong-patient sev-1 freeze is hardcoded to enforce unconditionally regardless of this flag.

This directly contradicts the module's own architecture doc (`ARCHITECTURE.md`, "Key decision 2"): *"verification is a deterministic gate, not prompt discipline... Unverified prose is never rendered."* As currently deployed, unverified prose **is** rendered — the one exception being cross-patient citations.

**Impact:** an ungrounded number, a fabricated citation, or a causation/dosing-advice claim that should have been caught and retried instead reaches the physician's screen, with the "citations checked" badge (per §2.5 of the architecture doc) implying a guarantee the running code is not currently providing.

**Fix:** flip `GATE_ENFORCED_DEFAULT` back to `true` (or set `CLINICAL_COPILOT_VERIFY_ENFORCE=1` in every environment that serves live traffic) before this branch reaches anything other than the QA retuning it was disabled for. The switch is deliberately small and greppable — this is a one-line, low-risk revert once the retuning is done; the risk is only in leaving it off past that.

**Confidence:** firm.

**Resolution (FINAL_REVIEW):** `GATE_ENFORCED_DEFAULT` is now `true` — the gate is enforced out of the box, and the "for QA" rationale above no longer applies to the Week-2 submission. The same policy also gates the Supervisor multi-agent path: a new critic stage (`src/Agent/CriticWorker.php`, recorded as a `verify` child span of the supervisor span) runs the V1–V6 verifier over any composed answer and degrades a rejected draft to a refusal instead of emitting it. `CLINICAL_COPILOT_VERIFY_ENFORCE=0` remains available as an explicit, QA-only relaxation (verdicts still recorded; V3 sev-1 freeze still unconditional).

---

## HIGH

### 2. Lab patient-identity mismatch guard is computed but never blocks the chart write
**CWE-863 (Incorrect Authorization)**
**Location:** `src/Ingest/ExtractionReview.php:79-109` (`lock`/`commitLabs`) → `src/Ingest/ChartWriter.php:287-362` (`commitLabResults`); verdict computed in `src/Ingest/AttachAndExtract.php:151-159` (`recordLabIdentity`)

`LabIdentityMatcher` computes a `match`/`mismatch`/`unknown` verdict comparing the uploaded lab PDF's stated patient name/DOB against the target chart, and persists it to `mod_copilot_extraction.identity_status`. The review template renders a red "may belong to a different patient — do NOT lock" banner on `mismatch`. But `ExtractionReview::lock()` never reads `identity_status` before calling `ChartWriter::commitLabResults()` — a definitive `Mismatch` verdict provides no server-side block. This is a documented design choice (ARCHITECTURE.md §9, decision D1: "it flags for a human; it does not hard-block"), relying entirely on the reviewer seeing and heeding the banner. Given finding #3 below (the guard's own matching logic has a false-mismatch bug on hyphenated/apostrophe'd names), and that the verdict is derived from the same untrusted document being ingested, a reviewer who dismisses the banner (or one that's wrong) can attach one patient's lab results to another patient's chart with nothing else stopping the write.

**Impact:** PHI-mixing — one patient's lab results committed to another patient's permanent chart record, discoverable only by manual review.

**Fix:** at minimum, require an explicit confirmation step (a second click, a typed acknowledgment) specifically gated on `identity_status === 'mismatch'` before `lock` is allowed to proceed, rather than relying on the banner alone; consider hard-blocking `mismatch` entirely and requiring an override action that is itself audit-logged.

**Confidence:** firm.

### 3. Knowledge-query PHI scrubber only strips capitalized names
**CWE-359 (Exposure of Private Personal Information) — PHI reaching a non-BAA third party**
**Location:** `src/Knowledge/KnowledgeQueryScrubber.php:94-152` (`safeKeyword`/`looksLikeProperNoun`)

The scrubber that's supposed to strip patient-identifying text from a chat question before it's sent to the separate (non-BAA) knowledge Postgres and to the Gemini embedding API relies on a "looks like a proper noun" heuristic keyed on capitalization. A patient name typed in lowercase (e.g. "why is jane's a1c high") survives every filter and is sent verbatim as a search term to both the non-BAA Postgres and the embedding API. `PostgresGuidelineRetriever`'s docblock describes this scrubbing in stronger terms than the code actually guarantees.

**Impact:** PHI (a patient name, at minimum) can leave the BAA-covered boundary to a system the architecture doc explicitly designed to never receive it — a compliance/breach-relevant event even though "minimization, not full de-identification" is the doc's stated intent.

**Fix:** don't rely on capitalization as the sole name-detection signal — cross-reference against the pinned session's actual patient name/aliases (which the module already knows server-side) and strip case-insensitively, in addition to whatever general heuristic remains for other patients' names.

**Confidence:** firm.

### 4. Chart-wide ACL, not per-patient authorization, gates every copilot surface
**CWE-862 (Missing Authorization) / IDOR-adjacent**
**Location:** `src/ReadPath/PatientIdentifierLookup.php:38-43` (`exists()`); `src/Controller/DocController.php:91-112`; `src/Controller/ChatController.php:160-226`

Every capability, doc view, and chat session is gated by `AclMain::aclCheckCore('patients','med')` — a chart-wide, role-level permission — with no facility/care-team scoping in this subtree. `PatientIdentifierLookup` itself documents this as "not a per-patient" check. Any authenticated clinical user can view or start a chat session against any other patient's synthesis by changing the `pid` parameter. This matches how much of stock OpenEMR's own chart-access model already works (chart-wide ACL rather than per-assignment), so it may be an accepted-by-convention risk rather than a novel bug introduced by this module — but it's worth naming explicitly because this module additionally hands that same broad access to an LLM, widening the blast radius of a compromised or curious account beyond what a human clicking through screens would casually do.

**Impact:** any authenticated clinical user can pull any other patient's AI-generated synthesis, chat history, or trigger a resource-costing LLM call against a patient outside their care team.

**Fix:** if this fork intends stricter scoping than stock OpenEMR's chart ACL, add a care-team/facility check in the capability/controller layer specifically for the copilot surfaces (which carry the extra cost and blast-radius of an LLM call, not just a page view). If chart-wide ACL is the accepted model here (matching the rest of the EMR), document that decision explicitly in ARCHITECTURE.md §4 rather than leaving it implicit.

**Confidence:** firm (the access-control gap); suspected (whether this diverges from the rest of the fork's accepted model, or matches it).

### 5. Stored XSS via unescaped trace error detail on the observability dashboard
**CWE-79 (Improper Neutralization of Input During Web Page Generation)**
**Location:** `templates/oe-module-clinical-copilot/dashboard.html.twig:406`

`{{ span.error_detail }}` renders with no `|text`/`|attr`/`|xlt` filter — the one unescaped free-text field found across all 9 templates in this module, despite the template's own comment claiming everything is explicitly escaped (autoescape is globally off project-wide, so this field relies entirely on that missing filter). `error_detail` can carry attacker-influenced content: an exception message, a malformed tool response, or text derived from an uploaded document that surfaced as an error.

**Impact:** stored XSS in the admin-only observability dashboard — limited to admin/super users who can already see PHI-adjacent trace payloads, but real, and a single missed filter away from being exploitable by anything that can make an error message contain attacker-controlled HTML/JS.

**Fix:** add `|text` (or the appropriate escaping filter used elsewhere in this same template) to the `span.error_detail` output.

**Confidence:** firm.

### 6. IDOR on lab/intake extraction review — cross-patient view, edit, and chart-write
**CWE-639 (Authorization Bypass Through User-Controlled Key)**
**Location:** `public/extraction_review.php:47-99`, `src/Controller/IngestController.php` (`editField`/`lock`/`unlock`/`reviewViewModel`)

Unlike chat sessions and doc-regenerate correlation IDs elsewhere in this same module (which explicitly bind to `session->userId`), the extraction-review flow accepts an `extraction_id` from the request and only checks the generic module-wide ACL — it never verifies the extraction belongs to a patient the requesting user should be looking at. Any clinical user can view, edit fields on, or **lock (i.e., commit to the real chart)** another patient's staged lab/intake extraction purely by guessing or enumerating `extraction_id` values.

**Impact:** combined with finding #2, this is a second, independent path to cross-patient PHI exposure and unauthorized chart writes — not just a read, but a write to `procedure_order`/`procedure_report`/`procedure_result` for a patient the user was never authorized to touch.

**Fix:** load the extraction's associated `pid` and verify it against the request's authorized-patient context (the same pattern already used correctly by `ChatController`/`DocController` in this module) before allowing any view, edit, or lock action.

**Confidence:** firm.

---

## MEDIUM

| # | Finding | Location |
|---|---|---|
| 7 | Banned-claim/clinical-content lint is a fixed lexicon — paraphrased causation, recommendation, or diagnosis language is not caught (a documented residual in ARCHITECTURE.md §2.4, but worth restating: it compounds finding #1 while the gate is off) | `src/Verify/Config/BannedClaimLexicon.php:48-111`, `ClinicalMentionLexicon.php:73-169` |
| 8 | Chart-derived free text (lab `result` strings, narrative text) is concatenated verbatim into the LLM prompt with only the verification gate as a backstop against prompt-injection-driven output — and that backstop is currently off (finding #1) | `src/Chat/ChatPromptAssembler.php:41-186` |
| 9 | Intake's base64 PDF round-trip is size-checked at initial upload but never re-validated at commit time (`storeSourceDocument`) | `src/Ingest/AttachAndExtract.php:91-124`, `ChartWriter.php:240-275`, `public/intake_upload.php:126-129` |
| 10 | Upload MIME validation falls back to the client-declared `Content-Type` if PHP's `fileinfo` extension is unavailable | `src/Ingest/UploadedDocument.php:145-155`, `public/knowledge_upload.php:186-211` |
| 11 | ~24 free-text `patient_data` columns (address, SSN, employer, etc.) are written from LLM-extracted or human-edited values with no sanitization beyond `PatientService`'s name/sex/DOB/email validation; exploitability depends on downstream rendering escaping these fields, which is largely stock OpenEMR territory and was not itself in scope | `src/Ingest/ChartWriter.php:55-128,212-232` |
| 12 | The `tags` retrieval parameter bypasses `KnowledgeQueryScrubber` entirely, relying on an unenforced "tags are non-PHI by construction" assumption | `src/Knowledge/PostgresGuidelineRetriever.php:64-75,210-213`, `TagNormalizer.php:25-27` |
| 13 | Guideline-chunk citation URLs are never scheme-validated before storage; a `javascript:`/`data:` URL from an ingested document is a latent stored-XSS vector if a downstream renderer doesn't sanitize it (rendering sink not confirmed in this pass) | `src/Rag/GuidelineChunk.php:30-48`, `DocumentMetadata.php:26-35` |
| 14 | `ChatController::logChatTurn()` writes full chat message + assistant answer text to the general system log — the class's own docblock calls this "acknowledged and intentional for the demo," not yet PHI-gated for production | `src/Controller/ChatController.php:839-860` |
| 15 | LLM-narrative and patient-influenced free text is fed into templates that rely entirely on explicit per-field escaping (autoescape is globally off); the templates checked in this pass consistently used the right filters, but the pattern itself is fragile — one missed filter (as in finding #5) is an XSS | `src/ReadPath/DocViewModel.php`, `MedNameResolver.php` |
| 16 | A QA-driven rerun path in the background worker bypasses the per-tick LLM dollar budget entirely (matches the module's own `docs/known-issues.md` BL-8) | `src/Worker.php:279-329` |
| 17 | Check-then-act race in the cost circuit breaker lets concurrent LLM calls burst past the hourly/daily spend cap before cost lands in the trace table | `src/Observability/RateLimit/CadenceCircuitBreaker.php:47-117` |
| 18 | `ops/knowledge/ingest_document.php` lacks the `PHP_SAPI !== 'cli'` guard its sibling ops scripts have, so a direct HTTP request boots OpenEMR with `$ignoreAuth = true` | `ops/knowledge/ingest_document.php:36-38` |
| 19 | The additivity CI gate (`check-additivity.sh`) and eval gate are not wired into any `.github/workflows` file — the module's own README already flags this; the "hard gate" is currently aspirational | `ops/ci/check-additivity.sh` |
| 20 | The documented "SELECT-only MySQL user" defense-in-depth layer (ARCHITECTURE.md §4) is never provisioned by any deploy script — `railway-preinstall-db.sh` grants the app's single DB user `GRANT ALL PRIVILEGES` instead | `railway-preinstall-db.sh:36-40` |
| 21 | `CLINICAL_COPILOT_SEED_DEMO` defaults to `1` — synthetic demo patients auto-seed into whatever database is configured on every deploy unless explicitly disabled | `railway-install-copilot.sh:121` |
| 22 | `railway-preinstall-db.sh` interpolates `MYSQL_DATABASE`/`MYSQL_USER`/`MYSQL_PASS` unescaped into `mariadb -e` SQL strings — SQL-injection-shaped if those env vars are ever attacker-influenced (currently operator-controlled, but the pattern is unsafe regardless) | `railway-preinstall-db.sh:39,44` |
| 23 | Deploy scripts fall back to the well-known `openemr`/`pass` credential pair for the app DB user when not explicitly overridden | `railway-entrypoint.sh:41-42`, `railway-preinstall-db.sh:12-13` |

## LOW

| # | Finding | Location |
|---|---|---|
| 24 | Repository-layer methods (`ChatSessionStore`, `ChatTurnStore`) perform no ownership check themselves — by design, since enforcement lives in the Controller layer, but worth naming as the seam that matters | `src/Chat/ChatSessionStore.php:56-61`, `ChatTurnStore.php` |
| 25 | `ChartWriter` verified as the sole core-table writer, matching the `ForbiddenWriteOutsideRepositoriesRule` whitelist — positive finding, no action needed | `src/Ingest/ChartWriter.php` |
| 26 | Capability drill-down methods clamp `window_months`'s lower bound but not the upper bound at that layer, relying entirely on the tool-schema validator two layers away for defense-in-depth | `src/Capability/ControlProxy.php:116-126`, `MedResponse.php`, `VitalsTrend.php` |
| 27 | `ForbiddenWriteOutsideRepositoriesRule` (the static-analysis read-only enforcement mechanism) fails open when it can't resolve a method-call receiver's type — no live violation found, but a structural gap in the guardrail itself | `tests/PHPStan/Rules/ForbiddenWriteOutsideRepositoriesRule.php:204-217` |
| 28 | `IpRateLimiter`'s APCu counter re-arms its TTL on every accepted request rather than only on creation, so steady legitimate polling traffic (e.g. a readiness probe) can accumulate toward the cap and then stay permanently blocked instead of the window resetting — see companion code-quality finding for the full mechanism | `src/Observability/IpRateLimiter.php:38-59` |
| 29 | Exception detail helper carries a pre-prod TODO acknowledging its verbosity; currently confined to logs and admin-gated trace columns | `src/Reduce/LlmUnavailableException.php:91-100` |
| 30 | Optional local Gemini-key env file has no production guard preventing accidental use outside dev | `src/Config/LlmEnv.php:84-128` |
| 31 | `ready.php`'s per-IP rate limiter fails open without APCu and isn't cluster-wide — a documented tradeoff, not a hidden one | `public/ready.php:28-34` |
| 32 | `ops/railway/seed.sh` self-exports the `CLINICAL_COPILOT_SEED_ALLOW=1` "operator assertion" flag rather than requiring it be set independently, weakening its purpose as a deliberate opt-in | `ops/railway/seed.sh:35` |
| 33 | `railway-preinstall-db.sh` uses `--skip-ssl` for all admin DB connections and passes the root password via `-p"$VAR"` on the command line (process-list visible) | `railway-preinstall-db.sh:19,36,41,62` |
| 34 | `railway-flex-bootstrap.sh` clones an unpinned branch of an external GitHub repo into the web root on every cold boot, with no integrity pinning | `railway-flex-bootstrap.sh:22-28` |

---

## Coverage

**Sampled:** all of `src/` (16 subdirectories), all 13 `public/*.php` entry points, all 9 Twig templates, `sql/install.sql` + `sql/uninstall.sql` + `table.sql`, and every script under `ops/` plus the root `railway-*.sh` / `Dockerfile.railway` deploy layer — entry points, auth/CSRF/ACL bootstrap, query construction, file upload handling, secrets loading, and the LLM egress/redaction boundary were the focus in each subtree, per the standard taxonomy (injection, broken auth/authz, secrets, crypto, deserialization, SSRF/path traversal, insecure defaults, missing validation/encoding).

**Not independently re-verified in this pass:**
- Downstream rendering of citation URLs (finding #13) — flagged as `crosses_subtree` by the originating agent; the actual sink lives in a template not confirmed to sanitize `javascript:`/`data:` schemes.
- Whether the chart-wide ACL model (finding #4) is a deliberate, documented choice elsewhere in this fork versus an oversight — the audit found the technical gap but not a definitive statement of intent from the codebase.
- The stock OpenEMR rendering paths that finding #11's unsanitized demographic fields eventually flow through (out of scope by design).

**Quarantined:** none — all 7 subtree agents completed and returned parseable, validated JSON.

No CRITICAL findings beyond #1 were surfaced by any subtree; no evidence of classic SQL injection, unsafe deserialization, or hardcoded application secrets was found anywhere in the module's own PHP source (the SQL-injection-shaped finding #22 and credential findings #23/#33 are in shell deploy scripts, not the application).
