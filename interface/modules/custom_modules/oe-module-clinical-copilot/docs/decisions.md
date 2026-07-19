# Clinical Co-Pilot — Decisions & Tradeoffs

A running record of the significant design decisions and the tradeoffs behind
them — the "why", and the roads not taken. Newest themes first within each
section. This is a companion to `build-notes.md` (shared build context),
`knowledge-base.md` (the RAG store), and `ingestion-failure-modes.md` (the
per-flow failure analysis); it does not repeat their detail, it records the
choices.

Last updated: 2026-07-15.

---

## Cross-cutting principles

These three shape almost every decision below, so they are stated once here.

### P1 — Additivity: the module only adds

**Decision.** All module code lives under
`interface/modules/custom_modules/oe-module-clinical-copilot/`. Existing OpenEMR
tables are read-only to us; only the `mod_copilot_*` tables (and one
`background_services` row) are module-owned and writable. A CI gate
(`ops/ci/check-additivity.sh`) fails the build if a diff escapes the module
directory, and a custom PHPStan rule (`ForbiddenWriteOutsideRepositoriesRule`)
makes a core-table write a static-analysis error everywhere except the single
sanctioned `ChartWriter`.

**Tradeoff.** We sometimes do more work to stay inside the boundary (e.g. reading
patient identity via `QueryUtils` instead of extending a core service). In
exchange, the module can be installed/removed without migrating or risking core
data, and a reviewer can audit "what can this thing write to a real chart" by
reading exactly one class.

### P2 — Degrade cleanly, never dead-end

**Decision.** Every external dependency has a defined fallback: no LLM → facts-only
synthesis and a facts-browser chat; no knowledge Postgres → the offline in-repo
corpus; no embeddings key → full-text search; a failed extraction → a blank form
to hand-fill. Nothing in the request path throws to the user because a
best-effort capability was unavailable.

**Tradeoff.** More branches and more "unavailable" states to reason about, versus
a hard-fail design that is simpler to write but turns any outage into a clinician
seeing a 500 mid-visit. For a clinical tool the graceful path is worth the code.

### P3 — PHI stays in the BAA boundary

**Decision.** Patient-identifying data lives only in OpenEMR's MySQL (the chart,
extractions, telemetry — all under the BAA). Anything that crosses to a
non-BAA service (the knowledge Postgres, third-party observability) is either
PHI-free by construction or PHI-scrubbed before it leaves the process.

**Tradeoff.** A physically separate knowledge store and a query scrubber are more
moving parts than one database and a `WHERE` clause. The separation is the point:
a bug can't join a guideline row to a chart row because they live on different
servers reachable by different connections.

---

## Week 2 — document ingestion (intake / lab)

### D1 — Lab identity guard: match the document to the chart, err toward flagging

**Context.** A lab PDF is uploaded *onto* a chosen patient's chart, but the file
itself names a patient. If those disagree, the wrong person's results are about
to be attached — a PHI-mixing incident.

**Decision.** Extract the document-header `patient_name`/`patient_dob`, compare to
the chart's `fname`/`lname`/`DOB` (`LabIdentityMatcher`), persist a verdict
(`match` / `mismatch` / `unknown`) on the extraction header, and show a persistent
banner on the review screen — red on mismatch ("do NOT lock"), amber on unknown,
quiet green on match. The matcher is deliberately conservative: any concrete
name or DOB conflict is a mismatch (even if the other field agrees); a name needs
both first and last as whole tokens (initials dropped); nothing on the document
to compare is `unknown`, never a silent pass.

**Tradeoff.** Conservatism produces occasional false alarms — a legal name vs. a
nickname, a hyphenated surname, a transposed DOB will flag as mismatch/unknown and
make the reviewer look. We accept more "please verify" prompts to make a genuine
wrong-chart upload nearly impossible to lock through inattention. The verdict is
best-effort (a chart-read failure is logged and swallowed) so the guard can never
block an upload the reviewer still has to verify by eye.

**Alternatives considered.** Hard-blocking a mismatched upload (rejected — a
transcription quirk shouldn't stop a legitimate lab from being reviewed);
auto-rerouting to the "correct" patient (rejected — inferring identity and then
acting on it is exactly the move that mixes PHI).

### D2 — Intake: deferred save (create the patient only at human "Save")

**Context.** The first intake implementation created the patient at upload time
and 500'd when extraction returned partial/blank fields.

**Decision.** Upload → extract → prefill a review screen (new-patient fields on the
left, the source PDF on the right) → the human edits and clicks Save → **only
then** is the patient created, the PDF stored, and reviewed allergies/medications
written to the chart lists. Nothing is persisted before the human confirms.

**Tradeoff.** The extracted values ride through the page in the browser rather than
being staged server-side, and a second human step is required before a patient
exists. In return the failure mode is "a blank form to fill by hand," never a
half-created patient or a crash — and the 500 class was eliminated by
construction. Post-create writes (PDF, lists) are best-effort and never re-report
total failure after the patient exists, so a hiccup there can't prompt a re-save
that duplicates the patient.

### D3 — Carry the intake PDF as base64, no server-side temp stash

**Context.** The review page needs to show the source PDF and carry it to the
Save step.

**Decision.** The PDF round-trips as base64 in a hidden field / data-URI iframe;
we deliberately did **not** add a server-side temp-file stash. Intake forms and
lab reports are a few pages — nowhere near the hundreds of pages Gemini can
ingest — so the round-trip is cheap, and the one oversize edge is handled by a
413 guard.

**Tradeoff.** Larger uploads inflate the page payload, and there is a size ceiling.
For this module's real documents that ceiling is irrelevant, and the simpler path
avoids temp-file lifecycle/cleanup and a second failure surface. Revisit only if
a genuinely large-document use case appears. (Rationale is also commented at the
call site so it isn't "optimized" away later.)

### D4 — No generated narrative for a brand-new patient

**Context.** The synthesis path can narrate a patient summary; should a
freshly-created intake patient get one?

**Decision.** No. A new patient gets the extracted facts, not an LLM narrative.

**Tradeoff.** Less apparent polish on the new-patient screen. But a narrative at
first contact pre-frames the encounter and steers the clinician before they have
done their own fact-finding — it degrades, rather than aids, the doctor's job at
exactly the moment independent judgment matters most. Facts only, here.

---

## The knowledge store (RAG)

### K1 — A separate Postgres for general medical knowledge

**Context.** The summarizer's RAG needs a corpus of guideline knowledge to cite.

**Decision.** Put that corpus in a **separate Postgres** (Railway in production),
distinct from OpenEMR's MySQL. It holds only PHI-free, paraphrased,
widely-published guideline summaries. The read path is SELECT-only; ingestion can
use a separate write role; retrieval queries are PHI-scrubbed before they leave
the process.

**Tradeoff.** Two databases and a boundary to maintain, versus one database. This
is P3 made physical: the knowledge store can live on non-BAA managed infra and be
reproduced from source control, while no query against it can reach a chart row.

### K2 — PHP for ingestion, not a separate Python service

**Context.** Chunk-and-embed pipelines are more mature in Python; a reusable Python
microservice was on the table.

**Decision.** Do the ingestion (extract → chunk → embed → write) in **PHP**, inside
the module. The source documents are standardized medical journals/guidelines, not
a wild variety, so the pipeline is simple; keeping it in PHP means one language,
one deploy, one codebase to maintain.

**Tradeoff.** We give up the richer Python ecosystem and the reusability of a
standalone service. Given a narrow, well-behaved document set and a small team,
the maintainability of "no extra service, no cross-language boundary, no second
thing to deploy and secure" wins. Revisit if ingestion needs outgrow simple
chunking (OCR-heavy scans, exotic layouts, high volume).

### K3 — Vector-first retrieval with a full-text fallback (hybrid)

**Context.** Early retrieval was vector-only; it hid corpus rows that hadn't been
embedded (the recall cliff).

**Decision.** Retrieval is vector-first (pgvector cosine over `embedding`) but
falls through to Postgres full-text (`websearch_to_tsquery` over a `tsvector`)
when there aren't enough vector hits or no embedding provider is configured —
merged by id. Vector search is an upgrade layered on full-text, never a hard
dependency.

**Tradeoff.** Two indexes (HNSW + GIN) and merge logic instead of one. In exchange,
a missing embeddings key or an un-embedded row degrades to still-useful keyword
search instead of silently returning nothing.

### K4 — Embedding model & dimension: `gemini-embedding-001` at 1536

**Context.** Default embeddings were `text-embedding-004` (768 dims). We wanted
higher-quality retrieval that still scales.

**Decision.** Default to **`gemini-embedding-001`** requested at **1536** dims. It
is a Matryoshka model, so the module asks for a 1536-wide slice of its native
3072 via `outputDimensionality`. 1536 is finer-grained than 768 yet stays under
pgvector's **2000-dim HNSW ceiling for the standard `vector` type**, so the fast
index still applies. No client-side re-normalization is needed because retrieval
ranks by cosine (magnitude-invariant) and truncation preserves direction.

**Tradeoff & roads not taken.**
- **768 (`text-embedding-004`)** — zero migration, free-tier, but coarser. Fine as
  a floor; we wanted more headroom.
- **3072 (native `gemini-embedding-001`)** — finest, but exceeds the standard
  `vector` index limit (2000). Indexing it needs `halfvec(3072)` (half-precision,
  indexable to 4000) and pgvector ≥ 0.7 — more moving parts and a from-source-0.6
  build would break. Not worth it for the marginal quality over 1536.
- **1536 (chosen)** — the sweet spot: better than 768, still indexed with the
  stock `vector` type, no halfvec/pgvector-0.7 dependency, writer/retriever SQL
  unchanged.

The cost: **changing the model or dimension is a re-embed, not a config flip** —
embeddings across widths/models aren't comparable and `ADD COLUMN IF NOT EXISTS`
won't resize a column. A brand-new store needs none of this (`schema.sql` creates
1536 directly); an existing store must drop/recreate the column and re-ingest.

### K5 — Chunk parameters chosen per-document at upload, not app-global

**Decision.** Chunk size (and overlap behavior) is set per upload, in the
Maintenance → Knowledge Base UI, not as a single global constant.

**Tradeoff.** A little more decision-making per upload. But dense tables want small
chunks and prose wants large ones; a single global size is wrong for one or the
other. Per-document control matches the chunking to the source.

### K6 — Knowledge upload failure = one generic, calm message

**Decision.** Unlike intake and lab (which fall back to a rich hand-entry screen),
a knowledge-upload failure surfaces a single generic notice ("unable to do that —
try again later or contact your administrator") and nothing else.

**Tradeoff.** Less actionable detail for the uploader. But knowledge upload is an
admin-gated maintenance action over a reproducible corpus, not a clinical task in
a patient's chart — there is nothing to salvage into a partial state, so the calm
generic message is the honest and correct UX.

---

## Local development & deployment

### L1 — The vector DB spins up locally in Docker, separate from MySQL

**Context.** We need to test the whole RAG flow before it touches Railway.

**Decision.** A compose overlay (`ops/local/compose.knowledge.yml`) stands up a
`pgvector/pgvector` container next to the app — a distinct database from MySQL,
exactly as in production — with the schema auto-applied and the module wired to
it. `ops/local/knowledge-up.sh` is the one-command bring-up;
`ops/knowledge/deploy_railway.sh` provisions the identical schema to Railway.

**Tradeoff.** A second local container and a small amount of compose plumbing,
versus testing directly against a cloud instance. The local parity means the full
upload→chunk→embed→store→retrieve path is exercised offline, and promotion to
Railway is one script with the same schema.

### L2 — Add `pdo_pgsql` to the app image via a local derived build

**Context.** OpenEMR core is MySQL-only, so its stock image ships without the
Postgres PDO driver the knowledge store needs on the app container.

**Decision.** For local dev, a tiny derived image (`ops/local/knowledge/Dockerfile`,
`FROM openemr/openemr:flex` + install `pdo_pgsql`) adds just the driver, verified
at build time so a wrong package name fails the build loudly rather than degrading
at runtime. Deployed images install the driver in their own build.

**Tradeoff.** A derived image for local dev instead of using the stock image
verbatim. It is a pure driver layer (nothing about how OpenEMR boots changes), and
without it the module would silently fall back to the offline corpus and never
exercise the store.

---

## Status of a few known, accepted limitations

- **Cosine on the offline corpus** is intentionally left as-is: the module only
  generates the narrative/relevant facts around it, not the retrieval math, so a
  more elaborate similarity model there buys nothing today.
- **Insurance fields at intake** follow the same shape as the core new-patient
  form (deferred), consistent with D4's "don't over-produce at first contact."
- **`gemini-embedding-001` batch behavior** (K4) is validated by unit test and
  local run; if the provider rejects batched embed calls, the client switches to
  per-item `embedContent` — a localized change, since everything downstream is
  width-aligned regardless of batch shape.
