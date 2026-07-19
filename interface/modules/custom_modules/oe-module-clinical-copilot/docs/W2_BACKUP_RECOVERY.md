# Clinical Co-Pilot — backup & recovery plan (W2)

This is the operational backup/recovery reference for the Clinical Co-Pilot
module as actually deployed: OpenEMR (this fork) on Railway via
`Dockerfile.railway` + `railway.toml`, a Railway-managed MySQL for the chart,
and a separate Railway-managed Postgres (pgvector) for the PHI-free knowledge
store. Everything below is grounded in this repo; where something is a
recommendation rather than current reality it is marked **Recommended, not yet
configured**.

> **Deployment context (be honest about it).** The current Railway deploy is a
> demo/testing environment: `railway-install-copilot.sh` seeds synthetic demo
> patients on every boot by default (`CLINICAL_COPILOT_SEED_DEMO=1`), and its
> own comments state "a Railway redeploy rebuilds the whole environment." The
> RPO/RTO targets below are therefore split into "demo deploy as-is" and "what
> must change before any instance carries real charts."

## 1. What must be backed up, and where it lives

Four artifact classes, four very different persistence stories:

| # | Artifact | Store | Persistent today? | Reproducible from repo? |
|---|----------|-------|-------------------|-------------------------|
| A | Extracted source documents (uploaded lab PDFs / intake scans) | OpenEMR `documents` table (MySQL) **+ file bytes on the app container disk** | DB rows: yes (managed MySQL). File bytes: **no — ephemeral container filesystem** | No |
| B | Derived clinical records (demographics, lab result chain, `mod_copilot_*`) | OpenEMR MySQL | Yes (managed MySQL) | No (except schema + config seed) |
| C | RAG knowledge base (`guideline_chunks`) | Separate knowledge Postgres (pgvector) | Yes (managed Postgres) | **Yes** — reseeded from the repo, automatically, on every boot |
| D | Config / secrets (env vars, GCP SA key) | Railway service variables; materialized per-boot to `ops/local/gemini.local.env` | Railway variables: yes. The env file: no (rewritten every boot, never committed) | Names yes (`docs/configuration.md`); values no |

### A. Extracted source documents

Every uploaded lab report / intake form is stored through the single
sanctioned write seam, `ChartWriter::storeSourceDocument()`
(`src/Ingest/ChartWriter.php`), which calls the core
`\Document::createDocument()`. With OpenEMR's default
`document_storage_method = '0'` ("Hard Disk", `library/globals.inc.php`
Documents tab), that means:

- a metadata row in the core **`documents`** table (MySQL), and
- the raw bytes on disk under **`sites/default/documents/`**
  (`sites/default/config.php`: `$GLOBALS['oer_config']['documents']['repopath']
  = $GLOBALS['OE_SITE_DIR'] . "/documents/"`).

`procedure_result.document_id` binds each committed lab value back to this
stored source PDF (`src/Ingest/ChartWriter.php` docblock), and
`mod_copilot_extraction.document_id` links the staging record the same way —
so losing the file bytes breaks the "click through to the source page"
provenance story even though every extracted value survives in MySQL.

> **The on-disk half of this artifact is ephemeral on Railway.** `railway.toml`
> declares no volume, and `railway-flex-bootstrap.sh` re-clones the source tree
> on a cold boot; the container filesystem (including
> `sites/default/documents/`) is discarded on every redeploy. After a redeploy,
> `documents` rows can point at files that no longer exist.
>
> **Recommended, not yet configured:** attach a Railway volume mounted at
> `/var/www/localhost/htdocs/openemr/sites/default/documents` before this
> instance ingests any document worth keeping. Until then, the file-sync
> procedure in §2.3 is the only way to preserve uploaded documents across
> deploys. Acceptable for the current demo (documents are synthetic and
> re-uploadable); not acceptable for real charts.

### B. Derived clinical records (OpenEMR MySQL)

All in the Railway-managed MySQL, written exclusively by `ChartWriter`
(enforced by the module-scoped PHPStan `SANCTIONED_CORE_WRITERS` rule):

- **Core rows:** `patient_data` (intake demographics), and the lab chain
  `procedure_order → procedure_order_code → procedure_report →
  procedure_result` (mirroring the core HL7 inbound writer).
- **Module-owned tables** — the ten `mod_copilot_*` tables created by
  `sql/install.sql` (identical to `table.sql`), enumerable via its
  `#IfNotTable` guards:
  `mod_copilot_doc`, `mod_copilot_cadence`, `mod_copilot_chat_session`,
  `mod_copilot_chat_turn`, `mod_copilot_trace`, `mod_copilot_qa`,
  `mod_copilot_trace_payload`, `mod_copilot_ui_event`,
  `mod_copilot_extraction`, `mod_copilot_extracted_fact`.
- **One `background_services` row** (`clinical_copilot_worker`), registered by
  `sql/install.sql` and managed by `ModuleManagerListener`.

Value classes within these tables differ:

- `mod_copilot_doc`, `mod_copilot_chat_turn`, `mod_copilot_trace`,
  `mod_copilot_qa` are **append-only provenance ledgers** — the record of every
  narrative a physician ever saw. Irreplaceable once lost (README
  "Install/enable/disable/uninstall", item 4).
- `mod_copilot_extraction` / `mod_copilot_extracted_fact` hold verified staging
  facts plus lineage (core-row ids per field). Irreplaceable.
- `mod_copilot_cadence` holds seeded config — recreated by `sql/install.sql`,
  but **operator edits to it since install are not** in the repo.
- `mod_copilot_trace_payload`, `mod_copilot_ui_event` are telemetry, pruned
  anyway by retention (`CLINICAL_COPILOT_TELEMETRY_RETENTION_DAYS`, default 3
  days) — lowest value.

### C. RAG knowledge base (separate Postgres)

The `guideline_chunks` table in the PHI-free knowledge Postgres
(`ops/knowledge/schema.sql`: `vector(1536)`, HNSW, `CREATE EXTENSION vector`).
Per `docs/knowledge-base.md`: "Every row is reproducible from source control
(the in-repo corpus under `src/Rag/corpus/`)."

- `ops/knowledge/seed_from_corpus.php` is "the ONLY writer to the knowledge
  database" on the request path's side — it applies `schema.sql` (idempotent)
  and upserts every chunk from `src/Rag/corpus/*.json` by id. Re-running is
  safe.
- `railway-install-copilot.sh` runs this seeder **on every boot** whenever
  `CLINICAL_COPILOT_KNOWLEDGE_*` is configured, so a fresh empty Postgres is
  repopulated with the in-repo corpus without operator action.
- If the store is lost entirely, the module degrades — visibly, not silently —
  to the in-repo offline corpus (`GuidelineRetrieverFactory`, per
  `docs/knowledge-base.md`).

**One caveat:** documents ingested at runtime through Maintenance → Knowledge
Base (`public/knowledge_upload.php` / `ops/knowledge/ingest_document.php`) are
**not** in the repo. If that path has been used, the knowledge DB carries
operator-contributed rows whose only other copy is the original source file —
for those, the `pg_dump` in §2.3 is a real backup, not just belt-and-braces.
Embeddings themselves are recomputable (Gemini `gemini-embedding-001` @ 1536,
costs API calls); full-text retrieval works even with null embeddings
(`ops/knowledge/schema.sql` comments).

### D. Config / secrets

- **Env vars** — the complete list is `docs/configuration.md` (LLM provider
  selection, knowledge-DB connection, caps, retention). Set as Railway service
  variables; `railway-install-copilot.sh` materializes them per-boot into
  `ops/local/gemini.local.env` for the web SAPI. That file is ephemeral and
  must never be treated as the source of truth (or committed).
- **Secrets to escrow:** `CLINICAL_COPILOT_GEMINI_API_KEY` (+ `_BACKUP`),
  knowledge-DB credentials, and — for the Vertex production path — the GCP
  service-account JSON pointed to by `GOOGLE_APPLICATION_CREDENTIALS`.
- **Recommended, not yet configured:** keep a copy of the Railway variable set
  in a proper secret manager (or at minimum a filled-in, access-controlled copy
  of `ops/knowledge/railway.env.example`). Today the Railway dashboard is the
  only place the live values exist; losing the Railway project loses them.

## 2. Backup procedures

### 2.1 What the platform provides automatically

- **Railway managed MySQL / Postgres:** Railway offers backups for database
  services (plan-dependent; point-in-time/daily snapshots are a Railway
  dashboard feature, not anything this repo configures). **Nothing in this
  repo enables, schedules, or verifies them — verify in the Railway dashboard
  that backups are actually on for both the MySQL and the knowledge Postgres
  services before relying on any RPO below.**
- **App container:** no volume (`railway.toml` has no volume stanza), therefore
  nothing to back up automatically — and nothing that *is* backed up. Source
  is re-fetched from GitHub on boot (`Dockerfile.railway` `FLEX_REPOSITORY`),
  so the app itself needs no backup; only `sites/default/documents/` (§1A) is
  at risk.
- **No cron/backup automation exists in `ops/`.** All of §2.3 is manual.
  **Recommended, not yet configured:** a scheduled job (Railway cron or
  external) running the §2.3 dumps daily.

### 2.2 Backup cadence (assumed for the RPO numbers)

- Managed-DB snapshots: assumed **daily** (Railway default when enabled).
- Manual dumps (§2.3): **before every deploy that touches `sql/` or
  `ChartWriter`**, and before any Module Manager uninstall (uninstall drops the
  `mod_copilot_*` ledgers destructively — README item 4 / OPEN-2).

### 2.3 Manual backup commands

Run inside the deployed container (`railway ssh --service <openemr-service>`)
or from any host that can reach the two databases. `$MYSQL_*` are the vars
`railway-entrypoint.sh` resolves; the webroot is
`/var/www/localhost/htdocs/openemr`.

**Full OpenEMR MySQL (preferred).** Derived rows reference core rows by id
(`pid`, `procedure_order_id`, `documents.id`), so a partial restore risks
dangling lineage — dump the whole database:

```bash
mysqldump --single-transaction --quick \
    -h "$MYSQL_HOST" -P "${MYSQL_PORT:-3306}" \
    -u "$MYSQL_USER" -p"$MYSQL_PASS" \
    "$MYSQL_DATABASE" > openemr-$(date +%F).sql
```

**Module tables only** (for a pre-uninstall ledger export — OPEN-2's manual
form):

```bash
mysqldump --single-transaction \
    -h "$MYSQL_HOST" -P "${MYSQL_PORT:-3306}" \
    -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DATABASE" \
    mod_copilot_doc mod_copilot_cadence mod_copilot_chat_session \
    mod_copilot_chat_turn mod_copilot_trace mod_copilot_qa \
    mod_copilot_trace_payload mod_copilot_ui_event \
    mod_copilot_extraction mod_copilot_extracted_fact \
    > copilot-tables-$(date +%F).sql
```

**Knowledge Postgres** (only strictly needed if runtime ingestion has been
used — §1C):

```bash
pg_dump "$CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL" \
    --table guideline_chunks --no-owner > guideline_chunks-$(date +%F).sql
```

**Document store files** (the ephemeral half of §1A):

```bash
tar czf documents-$(date +%F).tgz \
    -C /var/www/localhost/htdocs/openemr/sites/default documents
# then copy off-box, e.g. from your workstation:
#   railway ssh --service <svc> -- cat /tmp/documents-<date>.tgz > documents.tgz
```

## 3. Recovery procedures

### 3.1 What happens automatically

A Railway redeploy self-heals everything except data: `Dockerfile.railway`
rebuilds from `openemr/openemr:flex`, `railway-flex-bootstrap.sh` re-fetches
this repo, `railway-preinstall-db.sh` ensures the DB/user exist, the flex
`openemr.sh` runs the base install **only if the DB is unconfigured** (on a
restart with a persisted DB the checks pass immediately —
`railway-install-copilot.sh`), and `railway-install-copilot.sh` then re-runs
the module installer and the knowledge seeder idempotently. So:

- **App container lost / crashed:** redeploy. No data involved. Minutes.
- **Knowledge Postgres lost (repo-corpus-only):** provision a replacement,
  point `CLINICAL_COPILOT_KNOWLEDGE_*` at it, redeploy (or run
  `ops/knowledge/deploy_railway.sh` / `php ops/knowledge/seed_from_corpus.php`
  by hand). While it's down, retrieval degrades honestly to the offline corpus.
- **Module schema drift after restoring an older MySQL dump:** the next boot's
  installer run (or Modules → Manage Modules → Install) re-applies
  `sql/install.sql`, whose `#IfNotTable` / `#IfNotRow` / `#IfMissingColumn`
  guards create anything missing and upgrade pre-guard databases (e.g. the
  `identity_status` / `identity_detail` / `collection_date` columns) without
  touching existing rows.

### 3.2 Full manual restore — order matters

1. **Restore MySQL first** (it is the root of every id reference):
   `mariadb -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASS"
   "$MYSQL_DATABASE" < openemr-<date>.sql`
2. **Redeploy the app.** The base install detects the configured DB and skips;
   `railway-install-copilot.sh` re-runs `ops/local/install-module.php`
   (registers/activates the `modules` row, applies `sql/install.sql`,
   reactivates the `clinical_copilot_worker` background service). If the
   module still isn't enabled, run it directly:
   `su -s /bin/sh apache -c "php <webroot>/interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/install-module.php"`
   (see `docs/HANDOFF.md`, Deployment).
3. **Restore document files** into
   `<webroot>/sites/default/documents/` from the §2.3 tarball, preserving the
   directory layout (paths are recorded in `documents.url`). Ensure ownership
   by the web user (`chown -R apache: .../sites/default/documents`).
4. **Knowledge Postgres:** let the boot seeder repopulate from the repo corpus;
   additionally `psql "$CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL" -f
   guideline_chunks-<date>.sql` if operator-ingested rows existed. (Seeder
   upserts by id, so restoring the dump before or after the seed both converge.)
5. **Re-enter env vars** on the Railway service from the escrowed copy (§1D).
   On a real-PHI instance, set `CLINICAL_COPILOT_SEED_DEMO=0` **before** the
   first post-restore boot, or the demo seeder will write synthetic patients
   into the restored chart.

### 3.3 Verifying recovery

- **Liveness:** `GET <base>/interface/modules/custom_modules/oe-module-clinical-copilot/public/health.php`
  — unauthenticated, checks no dependencies; proves Apache + PHP are up.
- **Readiness:** `GET .../public/ready.php` — genuine dependency checks with
  redacted enum output: `db`, `tables_writable`, `llm`, `worker_heartbeat`,
  `breaker`, `document_store` (the §1A documents directory resolvable and
  writable), `knowledge` (the §1C knowledge Postgres; `offline-corpus` when
  none is configured), `reranker` (static `in-process`).
  Expect HTTP 200 with `"status":"ok"`; 503 means the DB restore or
  module install is incomplete; `worker_heartbeat` recovers within one cron
  interval (`src/Observability/ReadyCheck.php`).
- **Knowledge store:** `php ops/knowledge/check.php` — PASS/FAIL per link
  (pdo_pgsql → config → connect → pgvector → table+dim → seeded → embeddings);
  exit 0 iff links 1–6 pass.
- **Eval gate:** `php ops/eval/run-evals.php` — deterministic, no DB or model
  needed (§5), so a pass here proves the *code* path is intact independent of
  the data restore; exit 0 required.
- **Smoke test:** log in, open a seeded/restored patient's Co-Pilot doc view
  (`public/doc.php`), confirm facts render and a previously committed lab
  value still links to its source document (the §1A provenance chain); open
  `public/dashboard.php` and confirm traces resume accumulating.

## 4. RPO / RTO targets

"Demo as-is" assumes Railway DB backups are enabled at daily cadence (§2.1 —
**verify**, this repo does not configure them) and no volume is attached.

| Artifact | RPO (demo as-is) | RTO (demo as-is) | RPO/RTO (with §2 recommendations) | Justification |
|---|---|---|---|---|
| A. Source document **files** | **Unbounded** — lost at every redeploy, recoverable only to the last manual tarball (if any) | Minutes–hours (untar §3.2 step 3), or never if no tarball | RPO ≈ 0 (volume) / RTO minutes | No volume in `railway.toml`; container FS discarded by redeploy. Demo docs are synthetic and re-uploadable, hence tolerated today. |
| A. Source document **rows** + B. Derived clinical records (MySQL) | ≤ 24 h (daily snapshot) | ~1 h (restore dump + redeploy + §3.3 checks) | ≤ 24 h / ~1 h; tighter only with more frequent dumps (§2.1 recommendation) | Managed MySQL persists across redeploys; only DB loss/corruption invokes restore. Append-only ledgers make the loss window purely additive — no lost updates, just lost tail. |
| B. Module schema + seeded config (`mod_copilot_cadence` defaults, worker row) | **0** | Minutes | 0 / minutes | Fully reproducible: `sql/install.sql` is idempotent and re-run every boot by `railway-install-copilot.sh`. Post-install operator edits to `mod_copilot_cadence` fall under the MySQL row above instead. |
| C. RAG knowledge base (repo corpus) | **0** | Minutes (automatic reseed on boot; degraded-to-offline-corpus in the meantime, so user-facing outage ≈ 0) | 0 / minutes | Every row reproducible from `src/Rag/corpus/*.json` via the idempotent seeder; retrieval falls back to the same corpus offline while down. |
| C. RAG knowledge base (runtime-ingested rows) | ≤ 24 h (managed-Postgres snapshot) or last manual `pg_dump` | Minutes–1 h (`psql < dump`) | same | Not in the repo (§1C caveat); embeddings recomputable at API cost. |
| D. Config / secrets | 0 while the Railway project exists; **unbounded** if it is lost and no escrow copy exists | Minutes (re-paste variables) | 0 / minutes with secret-manager escrow (§1D recommendation) | Variable *names* and defaults are fully documented in `docs/configuration.md`; *values* live only in Railway today. |
| Eval golden set + baseline | **0** | Minutes | 0 / minutes | In-repo (§5). |

**Aggregate for the demo deploy as-is:** RPO ≤ 24 h for everything except
document file bytes (unbounded — accepted for synthetic data only); RTO ≈ 1 h
for a full DB restore, minutes for everything else. **Before real charts:**
attach the documents volume, confirm Railway DB backups, escrow secrets, set
`CLINICAL_COPILOT_SEED_DEMO=0` — that brings every artifact to RPO ≤ 24 h /
RTO ≤ 1 h with no unbounded cells.

## 5. Eval golden set — reproducible from the repo alone

The Week-2 eval gate needs **no backup at all**: it is fully reproducible from
source control, by design.

- The complete golden set (`ops/eval/cases.json`, 54 cases), the recorded
  pass-rate baseline (`ops/eval/baseline.json`), the CLI runner
  (`ops/eval/run-evals.php`) and the shared engine (`ops/eval/EvalGate.php`)
  are all committed files inside the module.
- Verified from the headers of `ops/eval/run-evals.php` and
  `ops/eval/EvalGate.php`: the run "stays DETERMINISTIC and needs NO live
  model and NO database — every case supplies the model's output verbatim and
  is fed through the SAME production code paths (ExtractionSchema,
  ExtractionClient with a stub, the RAG retriever)."
- Therefore: on any machine with PHP and a checkout of this branch,
  `php ops/eval/run-evals.php` reproduces the gate bit-for-bit — RPO 0, RTO =
  the seconds it takes to run. `--update-baseline` regenerates
  `baseline.json` itself if it were ever lost (review the diff before
  committing, as with any recorded baseline).
