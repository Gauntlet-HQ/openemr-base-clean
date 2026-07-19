# Clinical Co-Pilot — external medical-knowledge store (RAG)

The summarizer grounds its guideline-evidence section with RAG. That evidence
comes from **general medical knowledge** (paraphrased, widely-published guideline
summaries), which is a fundamentally different data class from **PHI** (the
patient chart). This module keeps the two physically separate.

## Two data domains, two databases

| Domain | Store | Holds | Under BAA |
|---|---|---|---|
| **PHI** | OpenEMR's MySQL | Patient chart, extractions, telemetry, chat | Yes |
| **General medical knowledge** | A separate Postgres (e.g. on Railway) | `guideline_chunks` — PHI-free guideline text | Not required |

They are **distinct connections to distinct servers.** A query against the
knowledge Postgres can never reach a chart row, and the retriever that reads the
knowledge store never touches the PHI database. The separation is structural, not
a runtime `WHERE` clause that could be forgotten.

> **The knowledge database must never contain PHI.** Every row is reproducible
> from source control (the in-repo corpus under `src/Rag/corpus/`). If a value
> could identify a patient, it does not belong there.

## How the segregation is enforced (defense in depth)

1. **Separate physical store.** `KnowledgeBaseConnection` is its own PDO to its
   own server, configured by its own env vars. It has no handle on OpenEMR's DB.
2. **Read-only surface.** The request path talks to the store only through
   `KnowledgeQueryRunner::select()` — a single parameterized SELECT. There is no
   write method; the store is populated exclusively by the offline seed script.
3. **PHI-scrubbed queries in transit.** A retrieval query can be a raw chat
   question ("why is Jane's A1c 9.4 on 3/2?"). `KnowledgeQueryScrubber` reduces
   it to non-PHI keywords *before it leaves the process*: it drops names,
   numbers, dates, and emails, and keeps only clinical terms — including analyte
   codes like `a1c`/`b12`/`sglt2` — plus the structured analyte tags (which are
   non-PHI by construction). Nothing patient-identifying reaches the non-BAA store.

## Where it plugs in

Everything rides the existing `RetrieverInterface` seam, so no consumer changed:

```
Supervisor / PatientEvidenceService
  └─ GuidelineRetrieverFactory::createDefault()
       ├─ knowledge Postgres CONFIGURED  → PostgresGuidelineRetriever  (this store)
       └─ NOT configured                 → HybridRetriever  (offline in-repo corpus)
```

The choice is made once, from env. With nothing configured the module behaves
exactly as before — fully offline. Configured-but-unreachable surfaces an honest
"degraded" (empty evidence, visible in the trace and on the dashboard), rather
than silently masking a misconfiguration.

## Setup (one command)

Provision a Postgres, then point the module at it and seed it from the corpus:

```bash
export CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL="postgresql://user:pass@host:5432/knowledge?sslmode=require"

# Applies schema.sql (idempotent) and upserts every chunk from src/Rag/corpus/*.json.
php ops/knowledge/seed_from_corpus.php
```

`ops/knowledge/schema.sql` creates `guideline_chunks` with a pgvector
`embedding vector(1536)` column (HNSW cosine index), a Postgres full-text
`search_vector` (GIN-indexed), and a GIN index over `tags`.

**Retrieval is vector-first.** Each chunk is embedded on write (the Gemini
embeddings API, same key as generation); the query is embedded too (after PHI
scrubbing), and retrieval ranks by pgvector cosine similarity
(`embedding <=> :query`) plus a fixed boost when a chunk's `tags` overlap the
requested analytes. When no embedding key is configured — or a query embed fails,
or no embedded rows match — it falls back to full-text (`ts_rank` over a
`websearch_to_tsquery`, keywords OR-combined for recall). So vector search is an
upgrade layered on top of full-text, never a hard dependency.

Requirements for the live path:
- **pgvector** on the knowledge Postgres (`CREATE EXTENSION vector` — managed
  providers incl. Railway ship it; `schema.sql` runs it).
- **`pdo_pgsql`** on the app container.
- A **Gemini API key** for embeddings (`CLINICAL_COPILOT_GEMINI_API_KEY`); without
  it the store runs on full-text alone.

Without config the module falls back to the offline in-repo corpus.

**Embedding model & dimension.** The default is `gemini-embedding-001` requested
at **1536** dims — chosen for scale: it is finer-grained than the older 768-wide
models yet stays under pgvector's **2000-dim HNSW ceiling** for the standard
`vector` type, so retrieval keeps the fast index. `gemini-embedding-001` is a
Matryoshka model, so the module simply asks for a 1536-wide slice of its native
3072 (`outputDimensionality`); no re-normalization is needed because retrieval
ranks by cosine, which ignores magnitude.

> **Changing the model or dimension is a re-embed, not a config flip.** Embeddings
> from different models/widths are not comparable, and `ADD COLUMN IF NOT EXISTS`
> will not resize an existing column. On a store that already holds vectors, drop
> and recreate, then re-ingest:
> ```sql
> ALTER TABLE guideline_chunks DROP COLUMN embedding;   -- old width
> ```
> then re-run `schema.sql` (recreates `embedding vector(1536)` + the index) and
> re-ingest your documents through the UI/CLI so they embed at the new width. A
> brand-new store needs none of this — `schema.sql` already creates 1536.

> Note: `seed_from_corpus.php` loads the in-repo corpus for full-text search but
> does not embed (it runs without the OpenEMR/Gemini runtime). To vector-index the
> corpus, ingest it through the CLI/UI instead, which embeds on write.

> Note: `seed_from_corpus.php` loads the in-repo corpus for full-text search but
> does not embed (it runs without the OpenEMR/Gemini runtime). To vector-index the
> corpus, ingest it through the CLI/UI instead, which embeds on write.

## Testing locally in Docker (no Railway needed)

The knowledge store is a **separate database** from OpenEMR's MySQL — in
production it lives at Railway, but for local testing it spins up as its own
pgvector container next to the app, identical schema, zero cloud dependency:

```bash
# From anywhere in the repo. Builds an openemr image variant with pdo_pgsql,
# starts a pgvector "knowledge_db", applies schema.sql, and wires the module to it.
interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/knowledge-up.sh

# Export a Gemini key first (in this shell or ops/local/gemini.local.env) so
# chunks embed for vector search; without it the store runs on full-text alone.
export CLINICAL_COPILOT_GEMINI_API_KEY=your_ai_studio_key
```

That gives you `knowledge_db` reachable as host `knowledge_db:5432` from the app
container (`copilot`/`copilot`, db `knowledge`) and `localhost:55432` from your
host for `psql`. Then drive the real flow at **Maintenance → Knowledge Base
(RAG)**: upload a PDF, pick a chunk size, preview, and store — the chunks land in
the local vector DB and retrieval reads from it on the next synthesis.

Two things the stock OpenEMR image lacks are handled for you: the overlay
(`ops/local/compose.knowledge.yml`) rebuilds the app image from
`ops/local/knowledge/Dockerfile` to add the **`pdo_pgsql`** driver, and pgvector's
image ships the **`vector`** extension `schema.sql` enables. Tear down with
`knowledge-up.sh --down` (the data volume is kept).

### Deploying to Railway (or any Postgres)

The knowledge store is **self-seeding on deploy**. One command both **ensures
pgvector** (`schema.sql` runs `CREATE EXTENSION IF NOT EXISTS vector` + creates
the table/indexes) and **loads the in-repo corpus**, so a fresh Postgres is
query-ready with no manual steps:

```bash
php interface/modules/custom_modules/oe-module-clinical-copilot/ops/knowledge/seed_from_corpus.php
#   with CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL set (or the discrete DB_* vars)
```

**Wire it as your Railway deploy step.** Set it as the OpenEMR service's
**pre-deploy / release command** so every deploy guarantees the extension +
corpus. It runs *inside the app container*, which already has `pdo_pgsql`, and is
idempotent (extension `IF NOT EXISTS`, corpus upserted by id) — safe to run on
every deploy. It ensures pgvector, applies the schema, and seeds `endocrinology.json`.

From a laptop instead (no in-container php), use the wrapper, which applies the
schema via host `psql` or a throwaway `pgvector/pgvector:pg16` container and then
seeds if `php`+`pdo_pgsql` are present:

```bash
# PUBLIC URL from Railway's Postgres "Connect" tab.
interface/modules/custom_modules/oe-module-clinical-copilot/ops/knowledge/deploy_railway.sh \
    "postgresql://user:pass@host.proxy.rlwy.net:5432/railway?sslmode=require"
#   --schema-only   ensures pgvector + tables but skips the corpus seed
```

> **pgvector must exist in the Postgres image.** `CREATE EXTENSION vector`
> only succeeds if the server ships the pgvector binaries. Railway's current
> Postgres image does; if `CREATE EXTENSION` errors with
> `could not open extension control file`, deploy Railway's **pgvector** database
> template (same `pgvector/pgvector` image used locally) and point the env at it.
> Also ensure the OpenEMR image carries `pdo_pgsql` (see `ops/local/knowledge/Dockerfile`).

The corpus seeds **full-text** rows (embeddings are added when documents are
ingested through the UI/CLI); retrieval is vector-first with full-text fallback,
so a seeded-but-unembedded corpus is still searched.

## Adding a document (chunk-and-store, no code change)

When a new guideline or reference comes out, push it straight in — no deploy:

**Maintenance → Knowledge Base (RAG)** (top-nav, admin-gated). Upload a
Text / Markdown / HTML / PDF / image, or paste text; set a **source** label
(the citation, and the key that a re-upload replaces), optional title / section /
tags, and the **chunk size** for *this* document (dense tables want small chunks,
prose wants large — it is chosen per upload, not a global constant). Preview the
proposed chunks, then commit.

Or in bulk from the container:

```bash
# one document
openemr-cmd e 'php .../ops/knowledge/ingest_document.php \
    --file=/path/ada-2027.pdf --source="ADA 2027 Addendum" --tags=a1c,lipids --chunk-size=1200'

# a whole folder (source defaults to each file name)
openemr-cmd e 'php .../ops/knowledge/ingest_document.php --dir=/path/guidelines'
```

How it works: **DocumentTextExtractor** gets plain text out (text/markdown/HTML
used directly; PDF/image transcribed via the reused Gemini vision seam — no new
PDF dependency), **DocumentChunker** splits it on heading/paragraph boundaries at
the chosen size with overlap and auto-detects analyte tags, and
**KnowledgeChunkWriter** upserts the chunks in one transaction — replacing the
document's previous chunks by `source` so a corrected version supersedes the old
one. Writes go through a dedicated write path/role; the retrieval path stays
SELECT-only.

## Growing the knowledge base (other paths)

You can also extend the in-repo `src/Rag/corpus/*.json` (keeping it PHI-free and
reproducible) and re-run `seed_from_corpus.php`, or load your own rows into
`guideline_chunks` following the same column shape. Re-running the seed is safe:
rows upsert by `id`.

## Verifying

The observability dashboard shows a **Knowledge store** row:
`connected — N guideline chunks` when the Postgres is reachable, `offline corpus`
when no external DB is configured, or `unreachable` when configured but down.
