# Clinical Co-Pilot -- Ops

This directory holds operational artifacts for deploying and gating the
`oe-module-clinical-copilot` module. U13 ("Ops artifacts") expands this file
with the Bruno API collection, baseline profile capture, and load-test
results; U9 ("Worker + additivity CI") adds the two pieces below.

## Hard deployment requirement: the worker cron

The background warm/QA/rerun/alert worker (`src/Worker.php`, registered as
the `clinical_copilot_worker` `background_services` row) only runs when the
host framework's tick is invoked. Per ARCHITECTURE_COMPLETE.md's "WORKER"
block and ARCHITECTURE.md §3.4/§3.5:

> Background services execute only from logged-in users' AJAX ticks or system
> cron. A cron entry is therefore a HARD deployment requirement; without it
> the pre-clinic warm never runs and alert evaluation sleeps whenever nobody
> is logged in.

Every deployment MUST add a cron entry hitting
`library/ajax/execute_background_services.php` every 5 minutes (matching
the `execute_interval` on the `clinical_copilot_worker` row), e.g.:

```cron
*/5 * * * * curl -s https://<your-openemr-host>/library/ajax/execute_background_services.php >/dev/null 2>&1
```

Without this cron entry, `GET /copilot/ready`'s worker-heartbeat check
(ARCHITECTURE.md §3.5's "Worker heartbeat stale" alert) will go stale and
never recover on its own -- a dead worker cannot alert on its own death, so
an external uptime probe hitting `/copilot/ready` is the recommended
dead-man switch on top of the cron entry itself.

## Deploy-time recommendation: a SELECT-only MySQL user

ARCHITECTURE.md §4 "Read-only is enforced, not asserted -- three layers"
layer 2: at deploy time, point every Clinical Co-Pilot capability/read-path
database connection at a dedicated MySQL user granted `SELECT` only on the
core clinical tables the module reads (`procedure_order`, `procedure_report`,
`procedure_result`, `prescriptions`, `lists`, `form_vitals`,
`openemr_postcalendar_events`, etc.), with full `SELECT, INSERT, UPDATE` on
`mod_copilot_*` only. This is a defense-in-depth layer underneath layer 1
(the PHPStan rule below): even a defect that slips past the static check
still cannot write a core table, because the database user itself has no
grant to do so. This is a deploy-time / DBA configuration step, not
something the module's own code or install SQL can enforce -- it is
recorded here as the operational recommendation to carry out before a
production deployment.

## CI gate 1: additivity (repo-diff test 1, I9)

```bash
interface/modules/custom_modules/oe-module-clinical-copilot/ops/ci/check-additivity.sh [base-ref]
```

Fails if the diff against `base-ref` (default resolution: `$1`, then
`$ADDITIVITY_BASE_REF`, then `origin/$GITHUB_BASE_REF`, then `origin/master`,
then `master`) touches anything outside
`interface/modules/custom_modules/oe-module-clinical-copilot/` other than the
three whitelisted spec docs (`ARCHITECTURE.md`, `USERS.md`,
`docs/clinical-copilot-tradeoffs.md`). See the script's own header comment
for the exact resolution order and a sample GitHub Actions step. This script
is not wired into any `.github/workflows/*.yml` file by this module (editing
core workflow files would itself violate the invariant it enforces) -- CI
wiring is a host-side, documented addition.

## CI gate 2: module-scoped PHPStan forbidden-write rule (read-only-enforcement layer 1)

```bash
vendor/bin/phpstan analyse -c interface/modules/custom_modules/oe-module-clinical-copilot/phpstan.neon
```

Run from the repo root using the HOST's own installed PHPStan (no separate
module `vendor/` needed). Registers
`ForbiddenWriteOutsideRepositoriesRule` (see
`../tests/PHPStan/Rules/ForbiddenWriteOutsideRepositoriesRule.php` and that
file's own docblock) over this module's `src/` only, and fails if any write
API (`QueryUtils::sqlInsert`/`sqlStatementThrowException`, or any object's
`insert`/`update`/`save`/`delete` method) is called from outside the
whitelisted `mod_copilot_*` repository classes. This is a separate,
additional gate from the host's own full-codebase `openemr-cmd phpstan` run
(which already analyses this module's files at level 10); this config's
`level` is deliberately low since the point of running it separately is
exactly this one custom rule, not a second full level-10 pass.

Both gates are intended to run as steps in whatever CI workflow builds/tests
a Clinical Co-Pilot branch; U13 documents the full CI/ops picture (this
file, expanded) alongside the Bruno collection and load-test baselines.

## Bruno API collection (R5) — `ops/bruno/`

Covers the real endpoints behind ARCHITECTURE.md §1.3's `/copilot/*`
shorthand: `public/{chat,doc,status,health,ready}.php`. Runnable with the
[Bruno CLI](https://www.usebruno.com/) (`bru run ops/bruno --env "Local Dev"`)
or the Bruno desktop app pointed at this directory — no source reading
required, per R5.

Folder order (run top to bottom; each depends on the ones before it):

1. **`00 - Auth Bootstrap`** — the documented login + CSRF-token bootstrap
   pair required by R5: (1) GET the host login page (establishes the
   session + CSRF private key), (2) POST the seeded dev credentials to the
   same handler a browser uses, (3) GET the module's own `doc.php` for a
   seeded patient and scrape the CSRF token it embeds
   (`CsrfUtils::collectCsrfToken()` under the hood) into the runtime
   variable `csrf_token`. Every state-changing request below sends it back
   as `csrf_token_form`.
2. **`01 - Synthesis Doc`** — `GET doc.php?pid=<pid>` (view) and the
   Regenerate `POST` (T22).
3. **`02 - Chat`** — `POST chat.php` start session / submit one turn /
   reseed (U11).
4. **`03 - Status`** — `GET status.php?cid=<correlation_id>` polling
   fallback (§1.3), reading the same trace spans the chat turn wrote.
5. **`04 - Health and Ready`** — `GET health.php` (unauthenticated
   liveness) and `GET ready.php` (unauthenticated, redacted dependency
   probe) — no bootstrap needed, runnable standalone.

Environment `Local Dev` (`ops/bruno/environments/`) holds `base_url` /
`username` / `password` / `pid` for the CLAUDE.md dev stack
(`https://localhost:9300`, `admin`/`pass`); `pid` defaults to `1`
(`CCP-001` in a freshly run U2 seed — see
`tests/Seed/fixtures/expected/landmines.json` for the full seeded-patient
map). Point a different environment file at a real deployment for a
non-dev run.

## Baseline + load harness (R8, R9) — `ops/load/`

- `ops/load/baseline/capture-baseline.sh` — single-request curl+docker-stats
  capture of synthesis read (warm/cold) and chat turns with 0/1/3 tool
  calls, unloaded. Run this once, first, against an idle stack.
- `ops/load/k6/` — `doc-read-load.js` and `chat-turn-load.js`, run at
  **10 and 50 concurrent VUs** per R8/R9 (`k6 run --vus 10|50 ...`);
  records p50/p95/p99 + error rate via k6's own summary, alongside
  DB-connection / PHP-FPM saturation captured by hand from `docker stats`
  / the FPM status page during the run.
- `ops/load/RESULTS.md` — the committed template both of the above write
  their numbers into, plus the **vertical-first scaling stance (T20)**:
  this app scales by resizing the server (PHP-FPM worker count / RAM),
  never by adding app replicas — the load numbers exist to size a machine,
  not to justify horizontal scale-out.

k6 is not installed in this build environment, so these scripts have not
been executed here — they are reviewed for correctness (matching the real
endpoint contracts in `public/*.php`) and are runnable as-is in the docker
dev stack (`k6` binary or `grafana/k6` container pointed at the compose
network).

## AI cost analysis — `ops/cost-analysis.md`

Parameterized cost model (Vertex Gemini 2.5 Pro/Flash) at 100/1K/10K/100K
clinician-users: synthesis reduce (cache-miss only), chat turns (Pro, with
Vertex context caching so turns 2+ pay only incremental tokens), and the
Flash post-mortem QA sweep (U12). Every rate (warm hit-rate, sessions/turns
per patient, token counts) is a named, adjustable assumption; see that
file's own "Honest gaps" section for what is estimated vs. measured.
