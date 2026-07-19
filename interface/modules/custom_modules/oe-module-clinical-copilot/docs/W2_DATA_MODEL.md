# Clinical Co-Pilot — Week-2 data model: owner, lineage, access control, validation (W2)

This is the data-governance reference for the four Week-2 artifact types the
module creates or curates. For each artifact it answers four questions:

- **Owner** — which system/table is the authoritative source of truth.
- **Lineage** — where the data came from, down to the concrete provenance
  columns.
- **Access control** — who can read/write it, per the module's ACL model.
- **Validation** — the JSON schemas / validators that gate every write.

Storage locations, persistence guarantees, and RPO/RTO per artifact are in
[`W2_BACKUP_RECOVERY.md`](W2_BACKUP_RECOVERY.md) (§1 has the authoritative
storage-location table) — this doc covers authority and provenance, not backup.
Everything below is grounded in this repo; file:line citations point at the
mechanism, not a description of it.

## Summary

| # | Artifact | Owner (source of truth) | Lineage (provenance columns) | Access control | Validation gate |
|---|----------|-------------------------|------------------------------|----------------|-----------------|
| 1 | Extracted lab observations | Staging: `mod_copilot_extraction` + `mod_copilot_extracted_fact` while `draft`; after lock: core `procedure_order → procedure_order_code → procedure_report → procedure_result` | `source_document_id`, `correlation_id`, `model`, `prompt_version`, `collection_date`, `identity_status`; per field `vlm_value` vs `value`, `edited_by_user`, `page`/`bbox_json`/`quote`, `committed_core_table`/`committed_core_pk`; `procedure_result.document_id` back to the source PDF | Upload/review: `patients/med` + `clinical_copilot/copilot_access`; post-lock edit/unlock: admin (`admin/super` or `admin/users`) | `src/Ingest/schema/lab_pdf.schema.json` + `ExtractionSchema::validate()` (page+quote required per valued result; W1b integer-page rule) |
| 2 | Intake facts | Core `patient_data` (demographics) and `lists` (allergies/medications) once the reviewer clicks Save; nothing persisted at preview | `documents` row for the source PDF (`foreign_id`/`foreign_table` → extraction when one exists); optional per-field page/quote citations (W6); staged fields → `patient_data` via `committed_core_table`/`committed_core_pk` | Same two-ACL gate as labs; patient create validated by core `PatientService`/`PatientValidator` | `src/Ingest/schema/intake_form.schema.json` (closed `field_key` enum; citations optional) + `ExtractionSchema::validate()` |
| 3 | Guideline chunks | Knowledge Postgres `guideline_chunks` (pgvector); for seeded rows the repo corpus `src/Rag/corpus/endocrinology.json` is authoritative; runtime-ingested documents are DB-authoritative | `id` (upsert key), `source` (document/citation label + supersede key), `section`, `url`, `tags`; section+url flow into citations (W7) | Ingestion: admin-only (`admin/super`/`admin/users` + `copilot_access`) or CLI; request path is SELECT-only; **no PHI, ever** | `GuidelineChunk` constructor invariants; `KnowledgeTableName::assertValid()`; PHI-scrubbed queries in transit |
| 4 | Citation records | Per extraction field: citation columns on `mod_copilot_extracted_fact`; per synthesis: `mod_copilot_doc.doc` JSON; per chat turn: `mod_copilot_chat_turn` (`content` + `verification_verdict`) and `mod_copilot_qa` | `SourceCitation` shape {source_type, source_id, page_or_section, field_or_chunk_id, quote_or_value, bbox, url}; `correlation_id` on every ledger row | Rides the owning surface's ACL (chart surfaces: `patients/med` + `copilot_access`; trace/QA dashboards: admin) | `SourceCitation` constructor/`fromArray()` domain checks; `ExtractionSchema` citation rules; verifier V2 resolves every citation before render |

---

## 1. Extracted lab observations

**Owner.** Two-phase, one authority at a time. While an extraction is in
`draft`, the staging pair `mod_copilot_extraction` (header) /
`mod_copilot_extracted_fact` (one row per result) is the source of truth and
the only editable copy (`table.sql:251-313`). At lock — the "single moment
derived facts become chart records" (`src/Ingest/ExtractionReview.php:22-33`)
— authority transfers to the core chart: the
`procedure_order → procedure_order_code → procedure_report → procedure_result`
chain, written exclusively by `ChartWriter::commitLabResults()`
(`src/Ingest/ChartWriter.php:287-362`). `ChartWriter` is the one
`SANCTIONED_CORE_WRITERS` entry in the module-scoped PHPStan gate — a core
write anywhere else in `src/` is a rule violation
(`src/Ingest/ChartWriter.php:22-28`). Once committed, the Week-1 lab reader
re-grounds each value as a normal core-table `Citation`
(`src/Ingest/SourceCitation.php:26-30`), so downstream consumers read the
chart, never the staging copy. The staging rows are retained as the
verification/accuracy record, not as a competing value store.

**Lineage.** Every hop is a column:

- Header (`table.sql:251-278`): `source_document_id` → core `documents.id`
  (the stored source PDF); `correlation_id` "propagates through document
  store, extraction, and the chart commit (single reconstructable trace)";
  `model` + `prompt_version` pin which extractor produced it; `collection_date`
  is the printed specimen date parsed off the report header (W5, strict Y-m-d,
  `table.sql:268`), used as the order/report date fallback at commit
  (`src/Ingest/ExtractionReview.php:100-107`); `identity_status`/`identity_detail`
  record the PHI-mixing guard verdict (did the printed name/DOB match the
  chart, `table.sql:266-267`); `created_by`/`locked_by`/`locked_at` record who
  uploaded and who verified.
- Per field (`table.sql:291-313`): `vlm_value` (what the model read) vs
  `value` (what the human locked in), with `edited_by_user` as the accuracy
  signal; `page`/`bbox_json`/`quote` are the document-native citation; and
  `committed_core_table`/`committed_core_pk` are the write-back lineage —
  which core row this fact became — recorded by
  `ExtractionStore::setFieldLineage()` (`src/Ingest/ExtractionStore.php:113-123`)
  from the id map `commitLabResults()` returns.
- In core, `procedure_result.document_id` binds each committed value back to
  the stored source PDF (`src/Ingest/ChartWriter.php:337-356`), closing the
  loop: chart value → source document → staging fact → model + prompt version.

**Access control.** Upload (`public/lab_upload.php:35`) and review/lock
(`public/extraction_review.php:33`) require both the host chart ACL
(`patients`/`med`) and the module's own ACL section
(`clinical_copilot`/`copilot_access`, registered as
`Bootstrap::ACL_SECTION_NAME`, `src/Bootstrap.php:37`). The review endpoint
additionally binds the requested `extraction_id` to the session's patient
context (the post-audit IDOR fix — `docs/SECURITY.md`, finding #6,
Resolution status). Post-lock edits and unlock require an elevated
administrator — `admin/super` or `admin/users`
(`public/extraction_review.php:40-42`) — enforced a second time,
defence-in-depth, inside `ExtractionReview::editField()`/`unlock()` via the
`$elevated` flag (`src/Ingest/ExtractionReview.php:50-56,121-133`). Writes to
the staging tables themselves go only through `ExtractionStore`, whitelisted
in the module's `ForbiddenWriteOutsideRepositoriesRule`
(`src/Ingest/ExtractionStore.php:19-26`).

**Validation.** `src/Ingest/schema/lab_pdf.schema.json` is the strict contract
("raw VLM output that does not validate against this shape is rejected before
anything is persisted") and doubles as the provider's constrained-decoding
schema (`ExtractionSchema::responseSchema()`,
`src/Ingest/ExtractionSchema.php:38-52`). `ExtractionSchema::validate()`
(`src/Ingest/ExtractionSchema.php:63-138`) is the single gatekeeper — "there
is no path from model JSON to a stored fact that skips this class"
(`src/Ingest/ExtractionSchema.php:17-28`). For labs it requires a positive
integer `page` and non-empty `quote` on every valued result
(`ExtractionSchema.php:103-110`); the W1b rule extends the integer-page check
to any supplied page even where citations are optional, refusing `"3"`, `2.0`,
or `0` as uncheckable citations (`ExtractionSchema.php:111-120`).
`collection_date` is deliberately advisory: `normalizeCollectionDate()`
degrades a garbage date to null (reviewer overrides on screen) rather than
rejecting the extraction (`ExtractionSchema.php:221-240`).

## 2. Intake facts

**Owner.** The core chart — and *only after human confirmation*. The intake
flow is deferred-save by design: upload → extract → review renders with
**nothing persisted** ("Extract only — no patient, no draft, no writes",
`public/intake_upload.php:66-122`; the flow docblock at
`intake_upload.php:6-11`), and only the reviewer's Save creates the patient.
The authoritative rows are:

- `patient_data` — demographics, created via
  `ChartWriter::tryCreatePatient()` through the core `PatientService`
  (`src/Ingest/ChartWriter.php:106-128`) and corrected on later lock via
  `updatePatientDemographics()` (`ChartWriter.php:212-232`). The mapping of
  intake `field_key` → `patient_data` column is the `DEMOGRAPHIC_COLUMNS`
  table (`ChartWriter.php:55-88`).
- `lists` — reviewed allergy/medication lines, one active entry per line, via
  `addChartListLines()` (`ChartWriter.php:163-181`).
- The richer intake facts (chief concern, family history, and the free-text
  medication/allergy source text) are intentionally **not** auto-written to
  core `lists`/`history_data` structures — they stay in the verified staging
  record with a documented Phase-B path (`ChartWriter.php:39-43`). There is no
  second authority for them; staging *is* their system of record until
  Phase B.

**Lineage.** The source PDF is stored as a real core `documents` row by
`ChartWriter::storeSourceDocument()`, linked back to the staging extraction
via `foreign_id`/`foreign_table = 'mod_copilot_extraction'` when one exists
(the deferred-save intake path stores it unlinked, `ChartWriter.php:240-275`).
W6 added **optional** per-field source citations: the model may volunteer
page/quote per field, carried to the review screen as display-only provenance
(`public/intake_upload.php:82-84,202-238`); a citation-free intake extraction
is fully successful. On lock, every staged field records
`committed_core_table = 'patient_data'` + the pid as its lineage
(`src/Ingest/ExtractionReview.php:157-162`).

**Access control.** The same two-ACL gate as labs (`patients`/`med` +
`clinical_copilot`/`copilot_access`, `public/intake_upload.php:54`), plus CSRF
on every POST (`intake_upload.php:50-52`). The create itself is additionally
gated by core validation: `PatientValidator` (DATABASE_INSERT_CONTEXT)
validates fname/lname/sex/DOB/email, and a failed validation re-renders the
form with the errors instead of writing anything
(`ChartWriter.php:106-128`).

**Validation.** `src/Ingest/schema/intake_form.schema.json`: a **closed
`field_key` enum** (35 keys) — `ExtractionSchema::validate()` rejects any key
outside it (`ExtractionSchema.php:80-85`, enum loaded at
`ExtractionSchema.php:268-281`). Per the schema's own description, page/quote
citations are optional and must never be moved into `required` (that
over-constraint previously degraded intake to a blank form —
`intake_form.schema.json`, description). `value` must be present but may be
null ("blank/illegible", `ExtractionSchema.php:87-92`); a supplied page must
still be a positive integer (W1b, `ExtractionSchema.php:111-120`).

## 3. Guideline chunks

**Owner.** The `guideline_chunks` table in the **separate, PHI-free knowledge
Postgres** (pgvector; `ops/knowledge/schema.sql:32-51`) — a different physical
server from the chart MySQL, by design (`docs/knowledge-base.md`, "Two data
domains, two databases"). Authority is split by row origin:

- **Seeded rows:** the in-repo corpus `src/Rag/corpus/endocrinology.json` is
  the source of truth. `ops/knowledge/seed_from_corpus.php` applies the schema
  and upserts every chunk **by id** (`ON CONFLICT (id) DO UPDATE`,
  `seed_from_corpus.php:94-101`); it is idempotent, runs on every boot when
  the knowledge DB is configured, and re-running always converges the DB to
  the repo (`docs/W2_BACKUP_RECOVERY.md` §1C). Editing a seeded row in the DB
  is not durable — the next seed overwrites it; the corpus file is where
  seeded content changes.
- **Runtime-ingested rows** (Maintenance → Knowledge Base /
  `ops/knowledge/ingest_document.php`): the DB is authoritative — these rows
  exist nowhere in the repo, which is exactly why the `pg_dump` in
  `W2_BACKUP_RECOVERY.md` §2.3 is a real backup for them. Writes go through
  `KnowledgeChunkWriter`, which supersedes a re-uploaded document's previous
  chunks by `source` and upserts by `id` in one transaction
  (`src/Knowledge/KnowledgeChunkWriter.php:20-28,74-101`).

If the store is lost or unconfigured, retrieval degrades visibly to the
in-repo offline corpus via `GuidelineRetrieverFactory`
(`docs/knowledge-base.md`, "Where it plugs in") — the fallback reads the same
repo corpus, so seeded content never has two divergent authorities.

**Lineage.** Columns on `guideline_chunks` (`ops/knowledge/schema.sql:32-51`):
`id` (the upsert identity), `source` (the human-readable citation label and
the supersede key for re-uploads), `section`, `url`, `tags`
(analyte keywords), `updated_at`. The same shape is enforced in PHP by the
`GuidelineChunk` value object (`src/Rag/GuidelineChunk.php:26-48`). W7 made
`section` and `url` flow into the citation itself:
`EvidenceSnippet` builds a `SourceCitation` with
`page_or_section = chunk section` and `url = chunk url`, so "a consumer of
the citation alone" gets full guideline provenance — source, section, chunk
id, quote, url (`src/Rag/EvidenceSnippet.php:39-52`).

**Access control.** Ingestion is an administrative act: the upload page
requires `admin/super` or `admin/users` **plus** `copilot_access`
(`public/knowledge_upload.php:53-56`); the bulk CLI ingester is CLI-only
(`PHP_SAPI` guard, `docs/SECURITY.md` finding #18, fixed). The request path is
structurally read-only — retrieval goes through a single parameterized SELECT
(`KnowledgeQueryRunner::select()`), with no write method on that seam
(`docs/knowledge-base.md`, "How the segregation is enforced"). The hard rule
printed at the top of the schema applies to every writer: **the knowledge
database must never contain PHI** (`ops/knowledge/schema.sql:10-12`); chat
queries are reduced to non-PHI clinical keywords by `KnowledgeQueryScrubber`
(clinical-term allowlist, post-audit hardening — `docs/SECURITY.md` finding
#3) before they leave the process.

**Validation.** `GuidelineChunk`'s constructor rejects empty `id`, `text`, or
`source` (`src/Rag/GuidelineChunk.php:38-47`), so no anonymous or
unattributable chunk can be constructed, seeded, or retrieved. The seeder
validates the target table identifier via `KnowledgeTableName::assertValid()`
(`ops/knowledge/seed_from_corpus.php:63`); the chunk writer validates
identifiers the same way and binds everything else
(`src/Knowledge/KnowledgeChunkWriter.php:27-28`).

## 4. Citation records

**Owner.** Citations are never a free-standing store — each citation lives in
the ledger that owns the claim it supports:

- **Per extraction field:** the citation columns `page`, `bbox_json`, `quote`
  on `mod_copilot_extracted_fact` (`table.sql:301-303`), hydrated back into a
  `SourceCitation` on read (`src/Ingest/ExtractionStore.php:243-258`). The
  canonical shape is `SourceCitation` — {source_type, source_id,
  page_or_section, field_or_chunk_id, quote_or_value} plus the bbox that
  powers the click-to-source overlay and, after W6+W7, the optional guideline
  `url` (`src/Ingest/SourceCitation.php:17-49`). It is deliberately a separate
  value object from the Week-1 core-row `Fact\Citation`; once a locked lab
  commits, the Week-1 reader re-grounds the value in a core-table citation, so
  the two models never merge (`SourceCitation.php:24-30`).
- **Per synthesis document:** `mod_copilot_doc.doc` — "JSON: facts +
  citations + narrative" (`table.sql:38`) — append-only, content-addressed by
  `(pid, fact_digest)`.
- **Per chat turn:** `mod_copilot_chat_turn` is the verdict/citation ledger —
  `content` (JSON turn content, including the assistant answer's citations),
  `tool_calls`, and `verification_verdict` ("JSON, per-check V1-V6 verdicts")
  per row (`table.sql:108-123`). `mod_copilot_qa` holds the post-hoc advisory
  QA verdicts per doc/chat-turn target, one row per target ever
  (`table.sql:167-192`).

**Lineage.** Every ledger row carries `correlation_id` (I12 — "every
invocation leaves a trace", `table.sql:42,116`), tying a rendered citation
back through `mod_copilot_trace` to the extraction, tool call, or LLM call
that produced it. For document-sourced citations the `source_id` addresses the
extraction (`'extraction:<id>'`, `src/Ingest/ExtractionStore.php:250-252`),
which in turn addresses the stored PDF via `source_document_id`; for guideline
citations the chunk id/section/url address the knowledge row (§3).

**Access control.** A citation is readable exactly where its owning surface
is: chart-facing surfaces (doc view, chat, evidence overlay) gate on
`patients/med` + `copilot_access` (`public/doc.php:50`, `public/chat.php:35`,
`public/evidence.php:28`); the trace/QA ledgers surface only through the
admin-gated observability dashboard (`public/dashboard.php:45-51`; the tables
themselves note "ACL-gated read access happens at the dashboard layer",
`table.sql:200-201`). Writes are repository-confined: `ChatTurnStore` exposes
exactly `insert()` and read methods — "the method list IS the audit"
(`src/Chat/ChatTurnStore.php:20-26`).

**Validation.** `SourceCitation` enforces non-empty `source_id` and
`quote_or_value` at construction and type-checks every field in `fromArray()`
(`src/Ingest/SourceCitation.php:33-49,70-118`). Upstream,
`ExtractionSchema::validate()` guarantees a lab citation exists and is
addressable (integer page ≥ 1, non-empty quote — §1). Downstream, the V1–V6
verifier's V2 check resolves every citation in a chat answer against the
turn's accumulated fact set before it renders — enforced by default
(`docs/SECURITY.md` finding #1, resolved: `VerificationPolicy`
`GATE_ENFORCED_DEFAULT = true`).

---

## The invariant: one source of truth per data type, no silent overwrites

Each artifact above has exactly one authoritative owner at any moment, and
every mechanism that could create a second copy or mutate history is either
idempotent, versioned, or structurally impossible:

1. **Idempotent chart commit (labs).** A second lock of the same extraction
   commits nothing: `ExtractionReview::lock()` returns immediately when the
   header is already locked (`src/Ingest/ExtractionReview.php:89-91`);
   `ChartWriter::commitLabResults()` filters to fields that are
   `!isCommitted()` and no-ops when none qualify
   (`src/Ingest/ChartWriter.php:289-295`); `ExtractionStore::markLocked()`
   guards its UPDATE with `WHERE status = 'draft'`
   (`src/Ingest/ExtractionStore.php:131-142`); and
   `UNIQUE(extraction_id, field_key)` makes it "one field, one staging row,
   one core row" (`table.sql:289,311`). An elevated correction re-commits
   idempotently — it "appends / updates lineage rather than duplicating chart
   rows" (`ExtractionReview.php:26-29`).
2. **Deterministic knowledge upserts.** The corpus seeder upserts by `id`
   (`ON CONFLICT (id) DO UPDATE`, `ops/knowledge/seed_from_corpus.php:94-101`)
   — re-running converges to the repo corpus instead of duplicating or
   drifting. Runtime re-ingestion of a document *deliberately* supersedes its
   own previous chunks by `source`, in one transaction, precisely so a
   corrected guideline replaces the old version rather than coexisting with it
   (`src/Knowledge/KnowledgeChunkWriter.php:20-28,85-91`) — a visible,
   documented replacement, not a silent overwrite.
3. **Append-only ledgers.** The provenance record of what a physician was
   shown is never mutated: `mod_copilot_doc` — "new attempts are new rows,
   nothing here is ever mutated" (`table.sql:20,27-29`);
   `mod_copilot_chat_turn` — append-only ledger (`table.sql:105`), enforced in
   code by `ChatTurnStore`'s insert-only surface
   (`src/Chat/ChatTurnStore.php:21-26`); `mod_copilot_trace` /
   `mod_copilot_trace_payload` — append-only observability source of truth
   (`table.sql:127,195-202`); `mod_copilot_qa` — append-only with
   `UNIQUE(target_type, target_id)`: "one verdict per target, ever"
   (`table.sql:188`).
4. **The single sanctioned exemption is versioned.** `mod_copilot_cadence` is
   the one module table where UPDATE is permitted — config only
   (`table.sql:59-63`) — and semantic changes ship as a `version` bump, "never
   an in-place semantic change" (E5, `table.sql:71,374-379`), so even mutable
   config cannot silently change meaning under a cached digest.
5. **One writer per boundary.** Core chart: `ChartWriter` only
   (PHPStan `SANCTIONED_CORE_WRITERS`, `src/Ingest/ChartWriter.php:22-28`).
   Staging tables: `ExtractionStore` only
   (`ForbiddenWriteOutsideRepositoriesRule`,
   `src/Ingest/ExtractionStore.php:19-26`). Knowledge Postgres: the seeder and
   `KnowledgeChunkWriter` only — the request path has no write method at all
   (`docs/knowledge-base.md`, "Read-only surface").
