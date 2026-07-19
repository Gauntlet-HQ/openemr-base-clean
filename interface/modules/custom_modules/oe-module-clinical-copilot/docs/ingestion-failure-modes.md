# Clinical Co-Pilot — PDF-ingestion failure-mode report

Audit of the three document-ingestion entry points, the classes/code each runs
through, the expected behavior and control loops, the potential points of
failure, and the gap between today's behavior and the **desired backup** for
each path. Line citations are relative to the module root
(`interface/modules/custom_modules/oe-module-clinical-copilot`).

## Executive summary

| Path | Entry | Health | Desired backup | Met? |
|---|---|---|---|---|
| **New Patient** (intake PDF → create patient) | `public/intake_upload.php` | **Broken on failure** | Show the source PDF beside the reused core "Add Patient" form for manual entry | **No — no backup exists; degraded paths 500** |
| **Lab Upload** (lab PDF → existing patient) | `public/lab_upload.php` | Degrades cleanly | Show the extracted rows + the source PDF in a scrollable viewer; **alert on a patient name/DOB mismatch (PHI-mixing guard, L7)** | **Yes — two-pane rows + PDF, plus a persistent identity-match banner** |
| **Knowledge** (doc → RAG store) | `public/knowledge_upload.php` | Degrades for caught paths | On failure just say "unable — try again later / contact admin," nothing special | **Partly — failure copy too specific; several uncaught paths 500** |

**The single most important finding:** the New-Patient path calls
`ChartWriter::createPatientFromIntake()` **unconditionally**
(`src/Ingest/AttachAndExtract.php:70`), before the draft or the PDF are saved.
On every degraded path (no LLM, LLM failure, schema reject) it feeds empty
demographics to `PatientService::insert`, the required-field validator rejects
it, and the resulting `RuntimeException` is **uncaught → HTTP 500** — no draft,
no stored PDF, no review page. The module's own docblock and UI promise a
graceful manual-entry fallback; it does not exist.

---

## 1. New Patient (intake PDF)

### Call chain
`public/intake_upload.php` (CSRF → ACL `patients/med` + `clinical_copilot/copilot_access` → `UploadedDocument::fromFilesEntry`)
→ `Controller/IngestController::ingestIntake`
→ `Ingest/AttachAndExtract::ingestIntake` → `tryExtract` → `Ingest/ExtractionClient::extract`
→ LLM client (`ReadPath/LlmClientFactory::create` → Vertex / Gemini-API / `UnavailableLlmClient`)
→ `Ingest/ExtractionSchema::validate|parse|blankExtraction`
→ **`Ingest/ChartWriter::createPatientFromIntake` → `Services/PatientService::insert` → `Validators/PatientValidator`**
→ `Ingest/ExtractionStore` (draft) → `ChartWriter::storeSourceDocument`
→ redirect `public/extraction_review.php` (human verify → lock → `ExtractionReview::commitIntake` writes edited demographics back to `patient_data`).
Menu: `src/Bootstrap.php:104,112-116` (Reports + Patient top menu). Blank/sample PDF: `Ingest/IntakeFormTemplate`, `public/intake_form_pdf.php`.

### Expected behavior
Scanned intake → Gemini extracts demographics + clinical fields with page/quote/bbox citations → schema-validated → patient created from best-guess demographics → draft + fields persisted, PDF stored/linked → review page for verify/correct → lock writes demographic corrections to `patient_data`; non-demographic facts (chief concern, meds, allergies, family hx) stay in staging (Phase-B).

### Architecture loop
Branch on extraction outcome (ok / `LlmUnavailableException` / `SchemaValidationException`). **The patient is committed at UPLOAD from unverified model output (`:70`), not at lock.** Human review re-enters only at the redirect. Ordering is patient-create (`:70`) *before* draft-persist (`:72`) and source-store (`:73`), so nothing is recoverable if the create throws.

### Potential points of failure
| # | Trigger | Current behavior | Impact | Severity |
|---|---|---|---|---|
| I1 | No LLM configured (the documented default) | blank draft → empty insert → validator rejects → uncaught throw | **HTTP 500** | **High** |
| I2 | LLM call fails/times out | same blank-draft path | **HTTP 500** | **High** |
| I3 | Model output fails schema | same blank-draft path | **HTTP 500** | **High** |
| I4 | Legible form but a required field missing/malformed (`fname`/`lname`/`sex`≥4ch/`DOB`=`Y-m-d`) | `insert` invalid → throw | **HTTP 500**; real incomplete form dead-ends | **High** |
| I5 | Extraction OK w/ required fields but others hallucinated | patient created from **unverified** output before any human check | wrong demographics until corrected at lock | **Med** |
| I6 | `insert` OK, then `persistDraft`/`storeSource` throws | patient exists, no draft/PDF (no transaction) | **orphan patient**, no review | **Med** |
| I7 | `storeSource`→`createDocument` returns error | returns null, silently skipped (`ChartWriter.php:170`) | review shows **no PDF**; staff verify blind | **Med** |
| I8 | Any of the above | no try/catch in the intake POST chain (`intake_upload.php:55-59`) | raw 500 / white page | **Med** |

### Current vs desired backup — **the core gap**
Desired: on extraction failure, do **not** create a junk patient; show the source PDF beside the reused core "Add Patient" UI for manual entry.
Today there is **no backup UI**, and the unconditional create both (a) crashes the graceful-degradation cases and (b) commits unverified patients on partial success.

**Recommended fix (shape):**
1. Reorder so the draft + source PDF are persisted **first** (so the upload is always recoverable).
2. Guard the patient create: only call `createPatientFromIntake` when vision succeeded **and** the required demographics are present and valid; otherwise skip it.
3. On the skip path, route to a page that renders the stored PDF next to the standard OpenEMR Add-Patient form (reuse the core new-patient partial), pre-filling whatever fields *were* extracted, so staff finish manually.
4. Wrap the intake POST chain in `try/catch (\Throwable)` → friendly notice + `SystemLogger`, never a raw 500.

---

## 2. Lab Upload (lab PDF → existing patient)

### Call chain
`public/lab_upload.php` (numeric `pid>0` → CSRF → ACL → `UploadedDocument::fromFilesEntry`)
→ `IngestController::ingestLab` → `AttachAndExtract::ingestLab` → `tryExtract` → `ExtractionClient::extract` (lab schema; open `field_key`)
→ `ExtractionStore` (draft) → `ChartWriter::storeSourceDocument`
→ `AttachAndExtract::recordLabIdentity` (**PHI-mixing guard**: `LabIdentityMatcher::compare` of the document-header `patient_name`/`patient_dob` against the chart's `fname`/`lname`/`DOB` → `ExtractionStore::setIdentity`)
→ `public/extraction_review.php` → lock (`ExtractionReview::lock` → `commitLabs` → `ChartWriter::commitLabResults`: `procedure_order`→`_code`→`_report`→one `procedure_result` per field, each bound to the source `document_id`).
Manual path: `AttachAndExtract::startManualLab` (empty draft, add rows in review).

### Expected behavior & loop
Upload → extract each discrete result (test/value/unit/range/flag + citation) → draft persisted, PDF stored → review shows rows + citations + bbox map → verify/edit/add → lock writes down the procedure chain. **No chart write at ingest; the only commit is at lock** — so degradation is safe. `commitLabResults` is idempotent per-field.

### Potential points of failure
| # | Trigger | Current behavior | Impact | Severity |
|---|---|---|---|---|
| L1 | `storeSource`→`createDocument` fails | null, silently skipped | review shows **no PDF**; `procedure_result.document_id` null → provenance lost | **Med** |
| L2 | Extraction degraded (no/failed LLM) | empty draft, **zero rows** | staff get a blank table; no explicit "extraction unavailable" framing | **Med** |
| L3 | Lock DB failure mid-chain | order/report inserted, then a result insert throws (not transactional) | partial orphan rows; caught → `&err=1` banner; re-lock makes a **second** order | **Med** |
| L4 | Model misreads a value but row is schema-valid | verified value defaults to model value; if unedited, wrong result committed | incorrect lab in chart | **Med** |
| L5 | `collection_date` not printed on the report (or unparseable) | parsed date now flows end-to-end (W5): `ExtractionSchema::parse` reads the printed date (strict Y-m-d, garbage degrades to null), it persists on the extraction header, prefills the review draw-date field, and backs the lock/commit; only when absent AND the reviewer leaves the field untouched does `ChartWriter` fall back to today | result dated today only in the no-printed-date, no-override case | **guarded** |
| L6 | `provider_id` = actor for all commits | verifying user recorded as ordering provider | attribution imprecise | **Low** |
| L7 | Report uploaded onto the **wrong chart** (name/DOB differ) | `recordLabIdentity` records `mismatch`; review shows a red "identity does not match" banner and the reviewer must confirm before locking | **PHI mix-up caught before it reaches the chart** | **guarded** |

**Identity guard (L7).** Because a lab PDF is uploaded *onto* a chart yet the file names its own patient, `AttachAndExtract::ingestLab` compares the document-header name/DOB to the target chart via `LabIdentityMatcher` and persists a verdict (`match` / `mismatch` / `unknown`) on the extraction header. `extraction_review.html.twig` turns it into a persistent banner: **red** on `mismatch` ("may belong to a different patient — do NOT lock"), **amber** on `unknown` ("could not confirm"), and a quiet **green** confirmation on `match`. The check is conservative (any concrete name/DOB conflict is a mismatch; nothing on the document to compare is `unknown`, never a silent pass) and best-effort (a failure reading the chart is logged and swallowed, never blocking the upload the reviewer must still verify by eye).

### Current vs desired backup
Desired: show the rows that would upload **and** the source PDF in a scrollable viewer.
- **Rows:** fully rendered as an editable table with unit/range/flag, model-proposed value, per-field citation, and an interactive bbox source-map (`extraction_review.html.twig:66-206`). **Met.**
- **PDF:** rendered in an `<iframe>` (`:59-64`) whose native viewer *is* scrollable — but it sits inside a **collapsed `<details>`** (no `open`), a **fixed 420px** box, and appears **only if `source_document_id` is set** (so it disappears under L1).

**Recommended fix (shape):** open the PDF disclosure by default (or make it a prominent side-by-side pane), give it a larger/resizable height, add an explicit "extraction unavailable — verify manually against the document" banner on the zero-row degraded state, and show a clear "source document unavailable" placeholder when L1 occurred rather than silently omitting the frame.

---

## 3. Knowledge Upload (document → RAG store)

### Call chain
`src/Bootstrap.php:125-195` (Maintenance top-nav, admin-gated) → `public/knowledge_upload.php` (CSRF → ACL admin + copilot_access)
→ `Knowledge/KnowledgeDocumentIngestor::createDefault` (**runs on every request incl. GET**)
→ preview: `DocumentTextExtractor::extract` (text/md/HTML in PHP; PDF/image → `DocumentTranscriber` → LLM) → `DocumentChunker::chunk` (+ `ChunkOptions`)
→ review (chunks round-trip through hidden `chunks_json` via `GuidelineChunk::toArray/fromArray`)
→ commit: `decodeChunks` → `KnowledgeChunkWriter::write` (transaction: replace-by-source DELETE + per-chunk upsert) → `KnowledgeWriteConnection` (write role) → audit log → result.
Read/status: `KnowledgeBaseStatus`, `KnowledgeBaseConnection` (SELECT-only). CLI: `ops/knowledge/ingest_document.php`.

### Expected behavior & loop
Two-step preview→commit (no write on preview; no re-transcription on commit — chunks are client-round-tripped). Replace-by-source supersedes a corrected doc. Read vs write DB seams are distinct interfaces; both a separate Postgres from the PHI MySQL.

### Potential points of failure
| # | Trigger | Current behavior | Impact | Severity |
|---|---|---|---|---|
| K1 | Model configured but call fails (timeout/HTTP/**429 quota**/safety/`MAX_TOKENS`) | all → one `LlmUnavailableException` catch → **"needs the model, not configured"** | misleading; hides a retryable/oversized cause | **Med (High for quota)** |
| K2 | Large PDF exceeds `maxOutputTokens=32768` | `finishReason=MAX_TOKENS` → providerError → K1's wrong message; no split/retry | large PDFs can't be ingested via UI | **Med** |
| K3 | Invalid `CLINICAL_COPILOT_KNOWLEDGE_TABLE` env | `createDefault()` at `:63` constructs the writer whose ctor throws `\DomainException` **before any try/catch, even on GET** | **whole page 500s** on every load | **High** |
| K4 | Oversize file (accepted type >12MB) | throws `UnsupportedDocumentException` → caught by the *type* branch | shown as "file type not supported" (wrong reason) | **Low** |
| K5 | Upload beyond PHP `post_max_size` | `$_POST` discarded → CSRF token missing → `dieOnFail` | OpenEMR hard CSRF die, not "file too large" | **Med** |
| K6 | Admin uploads a doc that actually contains PHI | **write path does no scrubbing** (scrubber guards only reads); bytes go to the API-key model (non-BAA) and into the store verbatim | **PHI egress + PHI at rest**; "PHI-free" is convention, not enforced | **High (compliance)** |
| K7 | Crafted/edited `chunks_json` on commit | rebuilt straight from POST via `fromArray`; no re-derivation, no cap; bad rows silently dropped | content integrity is client-trusted; silent partial commits | **Med** |
| K8 | `EventAuditLogger::newEvent` fails after a successful write (`:121-127`, outside try) | uncaught → 500 after commit | success page not shown; operator may re-submit | **Low/Med** |
| K9 | Twig render throws (`renderForm/Review/Result`, not wrapped) | uncaught → 500 | raw error | **Low** |
| K10 | Status banner (read role) vs commit (write role) | banner can be `ok` while write role fails | green then failure | **Low** |

*(Transaction rollback in `write()` was checked and is correct — no defect.)*

### Current vs desired backup
Desired (deliberately minimal): on failure, only "unable to complete — please try again later or contact your administrator"; no internal detail; **every** failure resolves to that notice (no raw 500).

Failure copy today is **too specific**: line 88 ("needs the model… paste text instead"), line 92 ("see server log"), line 117 ("check that it is configured and reachable") all leak cause/infra and none matches the generic notice. And several paths bypass the try/catch entirely and 500: **K3** (`createDefault()` at `:63`, the worst — fires on GET), **K5** (CSRF-on-oversize), **K8** (post-commit audit), **K9** (twig).

**Recommended fix (shape):**
1. Collapse the three failure messages (88/92/117) to one generic "Unable to complete this right now — please try again later or contact your administrator," with specifics sent to `SystemLogger` only.
2. Move `createDefault()` inside a try/catch (and/or make the writer ctor defer table validation) so a bad table env renders the notice, not a fatal.
3. Bring CSRF-on-oversize, the post-commit audit call, and twig render under the generic-notice umbrella.
4. (Separate, compliance) Decide the PHI stance for K6 — at minimum an explicit "no PHI — public/reference material only" attestation gate, ideally an ingestion-side scrub/scan before the model call and the write.

---

## Cross-cutting

- **No PHI/internal-detail leak on the two clinical ingest paths at extraction time** — `tryExtract` swallows `LlmUnavailableException`/`SchemaValidationException` and substitutes a blank draft without surfacing `detail()`/`getMessage()`. (The reduce/chat degrade banners do call `detail()`, which carries a `TODO(pre-prod)` about provider-body leakage — out of scope here.)
- **Transactions:** neither intake (patient-create + draft + source) nor lab-lock (order→report→results) is wrapped in a single transaction, so a mid-sequence failure leaves partial state (I6, L3).
- **Source-document storage failure is silent** in both clinical paths (I7/L1) — the reviewer loses the PDF with no indication.

## Suggested remediation order
1. **I1–I4 (intake 500 + missing manual-entry backup)** — highest user impact; the promised fallback is absent.
2. **K3 (knowledge page fatal on bad table env)** + collapse K-failure copy to the generic notice.
3. **K6 (knowledge PHI stance)** — compliance decision.
4. **Lab backup polish** (open/resize the PDF pane, degraded-state banner, missing-PDF placeholder).
5. Transaction wrapping (I6, L3) and silent source-store failures (I7, L1).
