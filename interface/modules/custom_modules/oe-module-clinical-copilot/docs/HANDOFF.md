# Clinical Co-Pilot — Handoff / Continue-Here Memory

Working state snapshot for picking the module back up on another device.
Last updated after the Week-2 security-audit fixes landed on `main`.

## Branch & git state

- **Work branch:** `claude/clinical-copilot-week2-prd-n3ohhh`
- **`main`** is fast-forwarded to the same tip — pull `main` to get everything.
- Latest commits (newest first):
  - `docs(architecture): reconcile LLM-platform section with the API-key-only read path`
  - `Merge origin/main into clinical-copilot-week2-prd (kind-hypatia + Vertex removal + deploy fixes)`
  - `fix(clinical-copilot): close IDOR on extraction review and PHI-scrubber name leak`
- Deploy fetches **branch `main`** (`Dockerfile.railway` → `railway-install-copilot.sh`), so anything to deploy must be on `main`.
- **Currency note:** later Week-2 work (verify-gate default-on, critic stage, medication-list flow, eval gate) landed on `FINAL_REVIEW` and subsequent working branches — the deployed app reflects that work only once `main` is fast-forwarded to it, so verify against `main`'s actual tip before claiming the live URL is Week-2-current.

## What just shipped (security-audit follow-ups)

1. **IDOR closed** — `extraction_review.php` now binds `extraction_id` to the
   session patient context via `IngestController::extractionPatientId()`;
   mismatch → 403 + logged warning.
2. **PHI scrubber hardened** — `KnowledgeQueryScrubber` switched from a
   capitalization blacklist to a **clinical-term allowlist** (`CLINICAL_TERMS`
   + analyte-code regex). Lowercase names no longer leak to the non-BAA
   knowledge Postgres / embedding API. Regression test added.
3. **ARCHITECTURE.md §T18 reconciled** — split into Vertex/ADC *target* vs.
   Gemini-API-key *as-built (v1)*; the Vertex code branch was removed from
   `LlmClientFactory` / `ChatLlmClientFactory`.

**Update (FINAL_REVIEW):** the verification gate is now ENFORCED BY DEFAULT
(`VerificationPolicy::GATE_ENFORCED_DEFAULT = true`) — the earlier
"off for QA retuning" deferral no longer applies for the Week-2 submission
(SECURITY.md finding #1, resolved). A critic stage (`CriticWorker`) also
hard-gates the Supervisor multi-agent path. For QA only, relax with
`CLINICAL_COPILOT_VERIFY_ENFORCE=0`; never in an environment serving real
traffic.

## Deployment (Railway via GitLab runner)

- `Dockerfile.railway` — thin `FROM openemr/openemr:flex`; bakes in
  `pdo_pgsql`; fetches this repo's `main`. Webroot in container:
  `/var/www/localhost/htdocs/openemr`.
- `railway-entrypoint.sh` → `railway-install-copilot.sh` (per-boot, background):
  installs the module, seeds the knowledge corpus when
  `CLINICAL_COPILOT_KNOWLEDGE_*` env is set, and writes LLM env vars to
  `ops/local/gemini.local.env` so the **web** process (not just CLI) sees them.
- Additivity gate is NOT in the GitLab deploy path (it's a CI check we run
  manually: `ops/ci/check-additivity.sh origin/main`).
- If maintenance menus are missing after a fresh DB, the module isn't enabled —
  run: `su -s /bin/sh apache -c "php <webroot>/interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/install-module.php"`

## Knowledge base (pgvector) — the separate Postgres

- Embeddings: **`gemini-embedding-001` @ 1536 dims** (Matryoshka slice of native
  3072; `outputDimensionality=1536` sent on each request). Cosine distance is
  magnitude-invariant → no re-normalization needed.
- Schema: `ops/knowledge/schema.sql` — `vector(1536)`, HNSW `vector_cosine_ops`,
  `CREATE EXTENSION IF NOT EXISTS vector`.
- **Local (docker):** `ops/local/knowledge-up.sh` (seeds by default; `--no-seed`,
  `--down`). Separate stack from the MySQL dev stack.
- **Railway provision:** `ops/knowledge/deploy_railway.sh` (seeds by default,
  `--schema-only` to skip). Env template: `ops/knowledge/railway.env.example`.
- **Health check:** `php ops/knowledge/check.php` — PASS/FAIL/WARN per link
  (pdo_pgsql → config → connect → pgvector ext → table+dim → seeded →
  embeddings). Exit 0 iff links 1–6 pass.

## Env vars (paste into Railway)

- `CLINICAL_COPILOT_GEMINI_API_KEY` (required for LLM/vision + embeddings)
- `CLINICAL_COPILOT_GEMINI_API_KEY_BACKUP` (optional failover key)
- `CLINICAL_COPILOT_KNOWLEDGE_*` (host/port/db/user/password for the pgvector
  Postgres — see `ops/knowledge/railway.env.example`)
- `CLINICAL_COPILOT_VERIFY_ENFORCE` — optional; the verify gate is now ON by
  default. Set `=0` only for QA retuning (`=1` force-enables, redundantly)

## Known open items / next candidates (from docs/SECURITY.md)

Closed in the handoff-review-completion pass (see `docs/SECURITY.md` →
"Resolution status"):

- ✅ **#5** — `{{ span.error_detail }}` now renders through `|text`
  (was already fixed in `9214a3c`; the handoff entry was stale).
- ✅ **#18** — `ops/knowledge/ingest_document.php` now has the
  `PHP_SAPI !== 'cli'` guard, matching its sibling ops scripts.
- ✅ **#4** — chart-wide ACL documented as accepted (matches stock OpenEMR),
  with the controller layer named as the extension point for stricter
  scoping. ARCHITECTURE.md §4.
- ✅ **#20** — SELECT-only MySQL user reconciled to as-built: it's a planned
  defense-in-depth *target*, not wired; the enforced read-only control is the
  module-scoped PHPStan write-forbidding rule. A real split needs two DB roles
  (`ChartWriter`/`mod_copilot_*` legitimately write). ARCHITECTURE.md §4.

Still open by decision / bigger than a doc-close:

- ~~**Verify gate is off (finding #1)**~~ — CLOSED on FINAL_REVIEW: enforced
  by default in code (`VerificationPolicy`), including a critic stage on the
  supervisor path. QA-only relax: `CLINICAL_COPILOT_VERIFY_ENFORCE=0`.
- If this fork ever wants stricter-than-stock authz, implement the care-team
  check (#4) and the two-DB-role split (#20) — deliberately deferred, not
  today-sized.
- Full audit + residuals: `docs/SECURITY.md`, `docs/REPORT.md`; deferred
  backlog with product decisions in `docs/known-issues.md` (BL-1…BL-12).

## Known runtime note

- "Automated extraction is unavailable" on intake = the multimodal/vision call
  is failing (`vision_used=false`), NOT a web-env key problem (key works for
  everything else). The real reason is now logged in `AttachAndExtract::tryExtract`
  (LlmUnavailableException vs SchemaValidationException) and surfaced as a
  banner on the intake review screen. Check `openemr-cmd php-log` for the cause.
- **Knowledge/pgvector never connects on Railway even with all env set** —
  fixed. Root cause: the web (Apache/mod_php) process does not inherit the
  container env the shell sees, so `CLINICAL_COPILOT_KNOWLEDGE_*` were invisible
  to it → `KnowledgeBaseConfig::isConfigured()` false → silent degrade to the
  offline corpus. `railway-install-copilot.sh` now materializes the knowledge-DB
  + embedding vars into `ops/local/gemini.local.env` (the same fallback file
  already used for the Gemini key), which `LlmEnv` loads per request. **Redeploy
  once** so the boot script rewrites that file. Diagnostic trap: `php
  ops/knowledge/check.php` runs on the **CLI**, which *does* see the env, so it
  reports PASS while the web path fails — verify from an actual chat request /
  the dashboard, not just check.php.
- **"No knowledge database is configured" on the web even though env is set** —
  the RAG status now distinguishes causes. `KnowledgeBaseStatus::snapshot()`
  returns a distinct `driver_missing` state: env is present but `pdo_pgsql` is
  not loaded in the Apache/web SAPI (classically it's enabled only for the CLI,
  so `check.php` and deploy-time seeding pass while chat/RAG requests fail).
  The knowledge-upload page and dashboard now say which it is — "set
  DATABASE_URL" (env missing) vs "enable pdo_pgsql for the web SAPI" (driver
  missing) — instead of collapsing both into the env message. `Dockerfile.railway`
  installs `pdo_pgsql` and verifies it with `php -m`, which only proves the CLI;
  if the dashboard shows `driver missing`, the extension isn't enabled for
  Apache/mod_php.

## How to run checks

```bash
# from repo root, in the openemr container (openemr-cmd) or host:
php -l <file>                                   # syntax
ops/ci/check-additivity.sh origin/main          # additivity gate
openemr-cmd phpunit-isolated                     # isolated unit tests (alias: pit)
openemr-cmd phpstan                              # static analysis (alias: pst)
```

## Key docs

- `ARCHITECTURE.md` (root) — full design; Part 2 (§7–§12) = ingestion + knowledge.
- `docs/decisions.md` — decisions & tradeoffs log.
- `docs/knowledge-base.md`, `docs/configuration.md`, `docs/ingestion-failure-modes.md`
- `docs/SECURITY.md`, `docs/REPORT.md` — code-review + audit output.
- `docs/W2_BACKUP_RECOVERY.md` — backup & recovery plan (RPO/RTO per artifact).
- `docs/W2_DATA_MODEL.md` — data model: owner/lineage/ACL/validation per Week-2 artifact.
