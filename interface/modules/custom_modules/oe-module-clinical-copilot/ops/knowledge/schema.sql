-- Clinical Co-Pilot -- external medical-knowledge store (Postgres) schema.
--
-- This database is SEPARATE from OpenEMR's MySQL by design. OpenEMR's DB holds
-- PHI (the patient chart, extractions, telemetry) under the BAA; THIS database
-- holds only general medical knowledge -- paraphrased, widely-published
-- guideline summaries with no patient-identifying data. Keeping them on distinct
-- servers makes the segregation physical: a query here can never reach a chart
-- row, and the RAG retriever that reads here never touches the PHI database.
--
-- >>> DO NOT INSERT PHI INTO THIS DATABASE. <<<
-- Every row is reproducible from source control (the in-repo corpus under
-- src/Rag/corpus/). If a value could identify a patient, it does not belong here.
--
-- Apply once against the Railway Postgres:
--   psql "$CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL" -f schema.sql
-- Then load the corpus:
--   php ops/knowledge/seed_from_corpus.php
--
-- Retrieval is VECTOR-FIRST (pgvector cosine similarity over `embedding`) with
-- Postgres full-text (`search_vector`) as the fallback when no embedding
-- provider is configured. The vector column width (1536) MUST match
-- CLINICAL_COPILOT_KNOWLEDGE_EMBED_DIM / the embedding model (default
-- gemini-embedding-001 truncated to 1536). 1536 stays under pgvector's 2000-dim
-- HNSW ceiling for the standard `vector` type, so the index below just works.
-- Changing the dimension requires recreating the column AND re-embedding every
-- row (embeddings from different models/widths are not comparable).

-- pgvector: the "vector db" extension. Managed Postgres (incl. Railway) ships it.
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS guideline_chunks (
    id        text PRIMARY KEY,
    title     text NOT NULL DEFAULT '',
    source    text NOT NULL,
    section   text NOT NULL DEFAULT '',
    body      text NOT NULL,
    tags      text[] NOT NULL DEFAULT '{}',
    url       text,
    -- Dense embedding of the chunk (null until embedded; retrieval falls back to
    -- full-text for null-embedding rows).
    embedding vector(1536),
    -- Full-text index column, maintained by Postgres from the human-readable
    -- fields. title/section are weighted above body so a topic match ranks first.
    search_vector tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector('english', coalesce(title, '')), 'A')
        || setweight(to_tsvector('english', coalesce(section, '')), 'B')
        || setweight(to_tsvector('english', coalesce(body, '')), 'C')
    ) STORED,
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- Upgrade path for a store created before the vector column existed.
ALTER TABLE guideline_chunks ADD COLUMN IF NOT EXISTS embedding vector(1536);

-- HNSW over the embedding powers the `embedding <=> :query` cosine search.
CREATE INDEX IF NOT EXISTS guideline_chunks_embedding_idx
    ON guideline_chunks USING hnsw (embedding vector_cosine_ops);

-- GIN over the tsvector powers the `@@ websearch_to_tsquery(...)` fallback.
CREATE INDEX IF NOT EXISTS guideline_chunks_search_idx
    ON guideline_chunks USING GIN (search_vector);

-- GIN over tags powers the `tags && ARRAY[...]` analyte-overlap boost.
CREATE INDEX IF NOT EXISTS guideline_chunks_tags_idx
    ON guideline_chunks USING GIN (tags);

COMMENT ON TABLE guideline_chunks IS
    'General medical-knowledge corpus for RAG. PHI-FREE by policy -- never insert patient data.';
