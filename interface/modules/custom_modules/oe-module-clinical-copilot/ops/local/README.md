# Clinical Co-Pilot — local container bring-up

Run the module locally (OpenEMR + MySQL in Docker) before cloud deployment.
Reuses the maintained dev stack (`docker/development-easy`) — the
`openemr/openemr:flex` image auto-installs OpenEMR + composer + npm on first
boot, so you don't fight a host `composer install`.

## One command

From anywhere in the repo:

```bash
interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/setup.sh
```

That will: start the stack → wait for OpenEMR's first-boot install → install +
enable the module → seed synthetic diabetes patients → run the smoke check →
print the demo-patient doc URLs. **Idempotent** — safe to re-run.

Then open **https://localhost:9300** (login `admin` / `pass`) and follow the
printed doc-page links, e.g.
`…/oe-module-clinical-copilot/public/doc.php?pid=<pid>`.

## Enabling the narrated LLM experience (optional)

With no key, synthesis renders **facts-only** and chat is a **facts browser** —
fully usable. To turn on narration, export a key **before** running `setup.sh`
(dev/test, **synthetic data only** — see [`../../docs/configuration.md`](../../docs/configuration.md)):

```bash
export CLINICAL_COPILOT_GEMINI_API_KEY=your_ai_studio_key   # dev/test fast-path
# ...or the Vertex production path:
export CLINICAL_COPILOT_GCP_PROJECT_ID=your-gcp-project
export CLINICAL_COPILOT_GCP_LOCATION=us-central1
```

Already running? Re-run `setup.sh` (or `docker compose … up -d`) after exporting
so the container picks up the new env.

## What's in this directory

| File | Role |
|---|---|
| `setup.sh` | one-command orchestrator (start → wait → install → seed → smoke) |
| `install-module.php` | headless register + install (`table.sql`) + enable, run in-container |
| `print-patients.php` | prints seeded demo patients + their doc URLs |
| `compose.gemini.yml` | compose overlay that passes the LLM env vars into the container |
| `compose.knowledge.yml` | compose overlay adding the pgvector **knowledge DB** + the `pdo_pgsql` driver |
| `knowledge/Dockerfile` | local openemr image = flex + `pdo_pgsql` (the knowledge store needs it) |
| `knowledge-up.sh` | one-command bring-up of the local vector DB (build → start → schema → wait) |

## Knowledge store (RAG vector DB) — local pgvector

The knowledge base is a **separate Postgres** from OpenEMR's MySQL (PHI never
lands there). To exercise the upload → chunk → embed → store → retrieve flow
locally, without a Railway instance:

```bash
interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/knowledge-up.sh
#   --seed  also loads the in-repo corpus   |   --down  tears the stack down (keeps data)
```

It stacks `compose.knowledge.yml` onto the dev stack, so it composes with
`setup.sh`'s stack. Provision the **same** schema to Railway with
[`../knowledge/deploy_railway.sh`](../knowledge/deploy_railway.sh). Full write-up:
[`../../docs/knowledge-base.md`](../../docs/knowledge-base.md) → "Testing locally
in Docker".

## Manual path (if you prefer step-by-step, or the auto-install needs a nudge)

```bash
# 1. bring up the stack (with LLM env passthrough)
docker compose \
  -f docker/development-easy/docker-compose.yml \
  -f interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/compose.gemini.yml \
  up -d
# 2. wait until https://localhost:9300 serves the login page, then:
DC="docker compose -f docker/development-easy/docker-compose.yml -f interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/compose.gemini.yml"
M=/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-clinical-copilot
$DC exec -T openemr php $M/ops/local/install-module.php
$DC exec -T openemr php $M/tests/Seed/SeedClinicalCopilot.php --force
$DC exec -T openemr php $M/tests/smoke/deterministic-core-smoke.php
```

**UI fallback for enabling** (if `install-module.php` doesn't make the module
load): in the app, **Admin → Modules → Manage Modules**, then Register →
Install → Enable **Clinical Co-Pilot**. That performs the same steps
(`table.sql` + `modules` row + `background_services`) through the UI.

## Running the full test suites + static analysis

The smoke check is a fast subset. For the full green gate (inside the container,
where vendor/ + DB exist):

```bash
openemr-cmd phpunit-isolated   # pure-logic PHPUnit (no DB)
openemr-cmd unit-test          # DB-backed suites
openemr-cmd phpstan            # level 10 (run over the whole codebase, filter to the module)
docker compose … exec -T openemr sh -lc 'cd '"$M"' && vendor/bin/phpunit'   # module's own DB suite
```

## Background warm / QA worker

The `clinical_copilot_worker` `background_services` row is registered active
(every 5 min). OpenEMR runs background services from **logged-in users' browser
AJAX ticks** — so warming/QA runs while a clinician has the app open. For
**headless** warming (a demo with nobody logged in), add a cron hitting
`library/ajax/execute_background_services.php` for the site — see the
deployment note in [`../README.md`](../README.md). This is a **hard requirement
for cloud**, optional for a hands-on local demo.

## Tear down

```bash
docker compose -f docker/development-easy/docker-compose.yml \
  -f interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/compose.gemini.yml down
#   add -v to also drop the MySQL volume (fresh install next time)
```

## Notes / honest caveats

- The **first** `setup.sh` run is slow (image pull + OpenEMR install + composer +
  npm build). Subsequent runs are fast.
- These scripts were validated by `php -l`, `bash -n`, and `docker compose
  config` (the overlay merges cleanly), but **not** executed end-to-end in the
  build environment (no Docker there). The `install-module.php` UI fallback
  above exists precisely for that reason.
- Synthetic patients only (OPEN-1). Do **not** point the dev/test Gemini key at
  real PHI.
- `knowledge/Dockerfile` installs `pdo_pgsql` onto the flex image at **build**
  time and verifies it (`php -m | grep pdo_pgsql`), so a wrong Alpine package
  name fails `docker compose build` loudly rather than degrading at runtime. The
  three knowledge files were validated by `bash -n` and `docker compose config`
  (clean three-way merge), but the image build itself was not run here (no
  Docker) — watch that one build step on your first `knowledge-up.sh`.
