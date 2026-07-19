# Clinical Co-Pilot (`oe-module-clinical-copilot`)

An **additive** OpenEMR custom module: a pre-visit clinical synthesis
co-pilot for one outpatient endocrinologist. Two surfaces over one
deterministic fact layer — (1) a pre-warmed, cited pre-visit synthesis per
scheduled patient, and (2) a multi-turn chat agent pinned to a patient.
**The LLM narrates and navigates; it never extracts.** Every clinical fact
is pulled, parsed, and cited by deterministic PHP; the model's only job is
to narrate over facts it is handed and answer follow-up questions with
tool calls the module executes and pins to the patient server-side.

## The four spec documents (read in this order)

1. **[`USERS.md`](../../../../USERS.md)** — target user (one outpatient
   endocrinologist), her actual pain, and UC1–UC6. The source of truth
   every capability traces back to.
2. **[`ARCHITECTURE.md`](../../../../ARCHITECTURE.md)** — the agent layer:
   chat (§1), verification (§2), observability (§3), authorization/PHI/trust
   boundaries (§4), evaluation summary (§5), and the failure model (§6).
   Also holds the case-study compliance map and the still-owed artifacts
   list this build unit closes out.
3. **[`ARCHITECTURE_COMPLETE.md`](../../../../ARCHITECTURE_COMPLETE.md)** —
   the fact-object schema, the lab contract (C1–C4), the module-owned
   tables, and the build-unit table (U1–U13) this README's own table below
   mirrors.
4. **[`docs/clinical-copilot-tradeoffs.md`](../../../../docs/clinical-copilot-tradeoffs.md)**
   — the decision record (T1–T22): every non-obvious call (LLM platform
   choice, vertical-first scaling, append-only ledgers, QA-driven rerun,
   etc.) and what was rejected instead.

This module's own [`docs/build-notes.md`](docs/build-notes.md) is the
shared engineering context every build unit read before its own brief —
useful if you want the condensed, PHP-signature-level version of the specs
above rather than the full prose.

[`docs/decisions.md`](docs/decisions.md) is the running decisions & tradeoffs
log — the *why* (and the roads not taken) behind the ongoing engineering
choices: the lab identity guard, deferred-save intake, the separate knowledge
Postgres, the `gemini-embedding-001 @ 1536` embedding choice, local pgvector
bring-up, and more. New calls append here as they are made.

[`docs/W2_BACKUP_RECOVERY.md`](docs/W2_BACKUP_RECOVERY.md) is the backup & recovery plan — what to back up and where it lives, backup/restore procedures, and per-artifact RPO/RTO targets.

[`docs/W2_DATA_MODEL.md`](docs/W2_DATA_MODEL.md) is the data model / lineage / authority reference — owner (source of truth), provenance columns, access control, and validation gates for each Week-2 artifact type, plus the no-silent-overwrites invariant and its enforcing mechanisms.

## LLM credential / config surface

[`docs/configuration.md`](docs/configuration.md) is the complete list of
environment variables that control which LLM provider (if any) this module
calls — Vertex AI (production, HIPAA-eligible under a BAA, T18) or a Gemini
API-key dev/test fast-path (synthetic data only, no BAA, T23) — plus a loud
warning on the API-key path's synthetic-data-only scope and a
copy-pasteable example for each. With nothing configured (the default in
this environment), both factories degrade cleanly to their `Unavailable*`
implementation.

## What ships in this directory

```
oe-module-clinical-copilot/
├── composer.json, info.txt, version.php                     -- module metadata
├── openemr.bootstrap.php, src/Bootstrap.php                 -- event subscription, menu, ACL gate
├── ModuleManagerListener.php                                 -- install/enable/disable/uninstall hooks
├── table.sql, sql/install.sql, sql/uninstall.sql             -- the 10 mod_copilot_* tables + background_services row
├── src/                                                       -- PSR-4 OpenEMR\Modules\ClinicalCopilot\
│   ├── Fact/, Lab/, Capability/                              -- U3-U5: fact model, lab contract, capabilities
│   ├── DocStore.php, ReadPath/, Controller/DocController.php -- U6, U8: append-only doc store + read path
│   ├── Reduce/                                                -- U7: Vertex LLM client, prompt assembly, redaction
│   ├── Verify/                                                -- U10: verifier V1-V6
│   ├── Chat/, Controller/ChatController.php                  -- U11: chat agent, tool executor, session store
│   ├── Worker.php, worker_entry.php                          -- U9: background warm/QA/rerun/alert worker
│   └── Observability/                                         -- U12: trace, metrics, dashboard, health/ready, alerts
├── public/                                                    -- the five real endpoints (see below)
├── templates/oe-module-clinical-copilot/                     -- Twig: doc, chat panel, dashboard
├── tests/                                                     -- Isolated/ (no DB), Db/ (DB-backed), Seed/, PHPStan/
└── ops/                                                       -- this build unit (U13): Bruno, load/baseline, cost model, CI gates
```

## Install / enable / disable / uninstall

Standard OpenEMR Module Manager flow — no manual SQL required:

1. **Install:** Modules → Manage Modules → find "Clinical Co-Pilot" →
   Install. Runs `sql/install.sql` (idempotent `#IfNotTable`/`#IfNotRow`
   guards — the 10 `mod_copilot_*` tables plus the `clinical_copilot_worker`
   `background_services` row), then `ModuleManagerListener` registers the
   module's own ACL section (`clinical_copilot` / `copilot_access`, via
   `AclExtended`).
2. **Enable:** activates the menu item (a "Clinical Co-Pilot" entry under
   Reports, `src/Bootstrap.php`) and flips the worker's
   `background_services.active` flag on.
3. **Disable:** deactivates the menu item and the worker row (`active = 0`)
   without dropping any data — re-enabling resumes cleanly.
4. **Uninstall:** runs `sql/uninstall.sql` — drops exactly the 10
   `mod_copilot_*` tables and deletes the `clinical_copilot_worker`
   `background_services` row. **This is destructive and irreversible for
   the append-only ledgers** (`mod_copilot_doc`, `mod_copilot_chat_turn`,
   `mod_copilot_trace`, `mod_copilot_qa` hold the full provenance record of
   every narrative a physician ever saw, T7). Export-before-drop tooling is
   tracked as OPEN-2 in `ARCHITECTURE_COMPLETE.md` and does **not** exist
   yet — manually export those tables first if retention is required.

No install/uninstall step touches any table outside the `mod_copilot_*`
prefix, and nothing in this module writes to a core OpenEMR table, ever
(additivity, I9 — see the CI gate below).

## Real endpoint URLs

`ARCHITECTURE.md`'s `/copilot/*` names are shorthand; the actual routes are
module pages under `public/`, all session-authenticated (no separate REST
auth model — ARCHITECTURE.md §1.3):

| Shorthand | Real URL | Method | Auth |
|---|---|---|---|
| `/copilot/doc/:pid` | `public/doc.php?pid=<pid>` | GET (view), POST (`action=regenerate`, CSRF) | session + ACL |
| `/copilot/chat` | `public/chat.php` | POST only (`action=start\|turn\|reseed`, CSRF) | session + ACL |
| `/copilot/status` | `public/status.php?cid=<correlation_id>` | GET | session + ACL, no CSRF (read-only) |
| `/copilot/health` | `public/health.php` | GET | **unauthenticated** (liveness only, no dependency checks — ARCHITECTURE.md §3.4) |
| `/copilot/ready` | `public/ready.php` | GET | **unauthenticated but redacted** (status enums only, per-IP rate-limited) |
| *(dashboard, not in the case-study's four)* | `public/dashboard.php` | GET (view), POST (breaker actions, CSRF) | session + ACL, **admin-gated** |
| *(UI telemetry ping)* | `public/event.php` | POST only, CSRF | session + ACL |
| *(Week 2 multi-agent run)* | `public/agent.php` | POST only, CSRF (spec: `ops/api/openapi.yaml`) | session + ACL |

Every authenticated page bootstraps `interface/globals.php` at the correct
relative depth (`__DIR__ . '/../../../../globals.php'` from `public/`), then
checks CSRF → ACL → session identity in that order (verified during this
build unit's consistency sweep — see below).

## ACL sections

Two independent gates, both required on every authenticated surface:

1. **Host chart-access gate:** `AclMain::aclCheckCore('patients', 'med')` —
   the same section that gates chart access generally.
2. **Module's own gate:** `AclMain::aclCheckCore('clinical_copilot',
   'copilot_access')` — registered by `ModuleManagerListener` via
   `AclExtended::addObjectSectionAcl()`/`addObjectAcl()` on install, so a
   site can grant or deny the copilot independently of chart access (a
   nurse or supervised resident with chart access is still cleanly denied
   the copilot itself, per ARCHITECTURE.md §4).

`public/dashboard.php` additionally requires
`AclMain::aclCheckCore('admin', 'super')` **or**
`AclMain::aclCheckCore('admin', 'users')` — the observability dashboard and
circuit-breaker admin actions are admin-only, on top of the
`clinical_copilot` gate.

## The worker cron — hard deployment requirement

The background warm/QA/rerun/alert worker only runs when the host
framework's tick is invoked (logged-in AJAX ticks, or cron). **A cron entry
is required for this module to function correctly in any real
deployment:**

```cron
*/5 * * * * curl -s https://<your-openemr-host>/library/ajax/execute_background_services.php >/dev/null 2>&1
```

Without it: the pre-clinic warm (T22: every appointment ready by T-30min)
never runs, alert evaluation sleeps whenever nobody is logged in, and
`/copilot/ready`'s worker-heartbeat check goes stale with nothing able to
self-recover (a dead worker cannot alert on its own death). An external
uptime probe hitting `/copilot/ready` is the recommended dead-man switch on
top of the cron entry itself. Full detail: `ops/README.md`.

The tick also runs **telemetry retention** as a housekeeping step: it prunes
observability rows older than a retention horizon (`mod_copilot_trace`, its
payload sidecar, UI-event pings, and QA verdicts — never chart, config, chat,
or ingestion data), so those tables stay bounded on disk. The horizon defaults
to **3 days** and is set with `CLINICAL_COPILOT_TELEMETRY_RETENTION_DAYS` (see
`docs/configuration.md`). This is the module's "cron SQL job" — a date-ranged,
index-backed `DELETE` riding the cron the module already requires, so no
separate crontab entry or MySQL event scheduler is needed. Implemented by
`src/Observability/TelemetryRetention.php` (a whitelisted core-write
repository); a prune failure degrades nothing on the serving path.

## Deploy-time recommendation: a SELECT-only MySQL user

Defense-in-depth layer 2 of 3 for read-only enforcement (ARCHITECTURE.md
§4): point the module's clinical-table reads at a dedicated MySQL user
granted `SELECT` only on the core tables it reads (`procedure_order`,
`procedure_report`, `procedure_result`, `prescriptions`, `lists`,
`form_vitals`, `openemr_postcalendar_events`, etc.), with full
`SELECT, INSERT, UPDATE` on `mod_copilot_*` only. This is a deploy-time DBA
step, not something install SQL can enforce — layer 1 is the module-scoped
PHPStan rule below; layer 3 is LLM egress redaction. Full detail:
`ops/README.md`.

## Running the test suites

| Suite | Command | Needs |
|---|---|---|
| Isolated (pure logic, no DB) | `composer phpunit-isolated` (host) or `openemr-cmd phpunit-isolated` / `pit` (container) | PHP + Composer + `vendor/` on host, or just Docker via `openemr-cmd` |
| DB-backed (this module's own suite) | `openemr-cmd worktree exec <branch> e 'cd interface/modules/custom_modules/oe-module-clinical-copilot && vendor/bin/phpunit'` (or `openemr-cmd e '...'` outside a worktree) — driven by this module's own `phpunit.xml` (`tests/Db/`, bootstrap `tests/bootstrap.php`) | the dev stack running (`openemr-cmd worktree up` / `up`) |
| Full repo suites (unit/api/e2e/services) | `openemr-cmd unit-test` / `at` / `et` / `st`, or `cst` for all of them | the dev stack running |
| Code quality (PHPStan L10, PSR-12, Rector, codespell, etc.) | `openemr-cmd code-quality` / `cq` | the dev stack running |

See the repo-root `CLAUDE.md` and `CONTRIBUTING.md` for the full
`openemr-cmd` reference (aliases, worktree workflow, Selenium debugging).

## The two CI gates

Both live under `ops/ci/` and `phpstan.neon` and are meant to run as steps
in whatever CI workflow builds/tests a Clinical Co-Pilot branch (neither is
wired into a `.github/workflows/*.yml` file by this module — editing core
workflow files would itself violate the additivity invariant they enforce):

```bash
# Gate 1 -- additivity (I9): fails if the diff against base-ref touches
# anything outside this module's directory (plus the three whitelisted
# spec docs at the repo root).
interface/modules/custom_modules/oe-module-clinical-copilot/ops/ci/check-additivity.sh [base-ref]

# Gate 2 -- module-scoped PHPStan forbidden-write rule (read-only
# enforcement layer 1): fails if any write API is called from outside the
# whitelisted mod_copilot_* repository classes.
vendor/bin/phpstan analyse -c interface/modules/custom_modules/oe-module-clinical-copilot/phpstan.neon
```

Full detail on both, including the exact base-ref resolution order and a
sample GitHub Actions step: `ops/README.md`.

The **54-case eval gate** (`ops/ci/run-eval-gate.sh`, deterministic, no live
model/DB) is the third gate. Wire it into CI the same way, and/or install it as
a **PR-blocking local git hook**:

```bash
# writes a pre-push hook that runs the eval gate (+ additivity) and blocks the
# push on any rubric regression; skips gracefully if php isn't on PATH.
interface/modules/custom_modules/oe-module-clinical-copilot/ops/ci/install-git-hooks.sh
```

## Validation performed for this build

**Honest scope note:** this build unit's own verification was `php -l`
across every `.php` file in the module (284 files, all clean) plus manual
code review, source-cross-reference (endpoint URLs, table names, function
names, CSRF field names — see the consistency-sweep findings below), and
running the additivity gate against `origin/main` (passed). **The full
PHPUnit (isolated + DB-backed), PHPStan level-10, and PSR-12 suites require
the docker dev stack and were not run in this cloud build environment** —
run them via `openemr-cmd cst` / `openemr-cmd code-quality` before merging,
per the table above.

## Build-unit map (U1–U13)

Mirrors `ARCHITECTURE_COMPLETE.md`'s own build-unit table; use this to
navigate a diff or a review by unit rather than by file.

| Unit | Scope | Owned files | Acceptance (abridged — full wording in ARCHITECTURE_COMPLETE.md) |
|---|---|---|---|
| U1 Module skeleton | composer.json, info.txt, bootstrap, table.sql, ModuleManagerListener | module root, `src/Bootstrap.php` | installs/enables/disables/uninstalls cleanly |
| U2 Seed + fixtures | 3-4 synthetic diabetes patients with landmines | `tests/Seed/`, fixture JSON | idempotent seed; every contract eval has a known-answer row |
| U3 Fact model + digest | Typed facts, canonical serializer, digest fn | `src/Fact/` | determinism eval E6; serializer unit tests |
| U4 LabSlice reader | Full lab contract (C1-C4), exclusion accounting | `src/Lab/` | comparator censoring, supersession, unit conversion, visible-exclusion evals |
| U5 Capabilities | ControlProxy, OverdueTests, PendingResults, MedResponse, VitalsTrend | `src/Capability/` | per-capability known-answer fixtures; I14 conservation |
| U6 DocStore | Append-only repository | `src/DocStore.php` | E7 append-only (no UPDATE/DELETE paths) |
| U7 Reduce | Vertex LLM client, prompt assembly, egress redaction, degradation | `src/Reduce/` | degradation test; prompt-assembly test; redaction round-trip |
| U8 Read path + page | Controller + Twig, facts-first rendering, history | `src/Controller/DocController.php`, `templates/` | digest evals E1-E5 end-to-end; audit-log on view |
| U9 Worker + additivity CI | `background_services` function, CI gates 1-2 | `src/Worker.php`, `src/worker_entry.php`, CI config | worker-dead ⇒ reads still correct (I7); gates green |
| U10 Verifier | Claim-schema contract, V1-V6, fail-closed retry | `src/Verify/` | seeded wrong-number/wrong-patient/uncited/causation outputs blocked |
| U11 Chat agent | Session store, tool executor, chat controller, SSE | `src/Chat/`, `src/Controller/ChatController.php`, `templates/chat*` | multi-turn anaphora; chaining known-answer; adversarial refusals |
| U12 Observability | Trace writer, dashboard, alerts, rate limits, health/ready | `src/Observability/`, `templates/dashboard*` | reconstructable traces; cache-hit/degraded spans; `/ready` fails independent of `/health` |
| **U13 Ops artifacts (this build)** | Bruno collection, baseline+load harness, cost analysis, module README, final integration sweep | `ops/`, this file | collection runs green without reading source; baseline/load numbers committed; consistency sweep findings below |

## Consistency-sweep findings (U13)

A final cross-file check across the whole module turned up and fixed three
small integration bugs, all in the module's own Twig templates (not core
OpenEMR code):

- **`templates/oe-module-clinical-copilot/chat_panel.html.twig`** used
  `csrfToken()` (which renders a *whole* `<input>` tag as a string) to fill
  another input's `value` attribute, instead of `csrfTokenRaw()` (which
  returns the bare token). Every chat request (`start`/`turn`/`reseed`)
  would have POSTed the literal `<input ...>` markup back as the CSRF
  token and 403'd. **Fixed** — now uses `csrfTokenRaw()`.
- **`templates/oe-module-clinical-copilot/doc.html.twig`**'s Regenerate
  form and **`dashboard.html.twig`**'s circuit-breaker form both called
  `csrfToken()` with no arguments, which defaults to field name `_token`.
  Both `doc.php` and `dashboard.php` check
  `CsrfUtils::checkCsrfInput(INPUT_POST, ...)` with ITS default key,
  `csrf_token_form` — the convention every other legacy form in this
  codebase uses (and the same key the chat panel's own JS already sends
  explicitly). The mismatch meant the Regenerate button and the breaker
  force-open/reset actions would 403 on every real click. **Fixed** — both
  now call `csrfToken('default', 'csrf_token_form')` to pin the field name
  to what the PHP side actually reads.

Everything else checked out clean:

- All 284 `.php` files pass `php -l`.
- `background_services.function`/`.require_once` (`clinicalCopilotWorkerRun`
  / `src/worker_entry.php`) matches the actual function defined there, in
  both `table.sql` and `sql/install.sql`, and matches
  `ModuleManagerListener`'s own constant.
- The menu URL in `src/Bootstrap.php` points at the real, existing
  `public/doc.php`.
- `table.sql`'s `#IfNotTable mod_copilot_*` blocks, `sql/uninstall.sql`'s
  `DROP TABLE` statements, and `ModuleManagerListener::OWNED_TABLES` all
  list the exact same tables — 8 at the time of this U13 sweep
  (`mod_copilot_doc`, `mod_copilot_cadence`, `mod_copilot_chat_session`,
  `mod_copilot_chat_turn`, `mod_copilot_trace`, `mod_copilot_qa`,
  `mod_copilot_trace_payload`, `mod_copilot_ui_event`); now 10, after Week 2
  added `mod_copilot_extraction` + `mod_copilot_extracted_fact` to all four
  schema mirrors.
- `version.php` (0.1.0) and `info.txt` ("Clinical Co-Pilot v0.1.0") agree.
- `moduleConfig.php` was removed: OpenEMR's Module Manager iframes that file
  directly as the "Configure" button's content when it exists
  (`interface/modules/zend_modules/module/Installer/view/installer/installer/configure.phtml`),
  but this module's version never bootstrapped `globals.php` or rendered
  anything — clicking "Configure" showed a blank iframe. Its `install`/
  `uninstall`/`tables` keys were never read by core; `sql/install.sql` and
  `ModuleManagerListener` are the real, live install/uninstall paths.
  Removing the file makes core correctly omit the "Configure" button instead
  of showing a broken one; a real settings UI is a separate future addition.
- `sql/uninstall.sql` is likewise **not executed by core** — uninstall goes
  through `ModuleManagerListener::reset_module()`'s own `DROP TABLE` logic.
  The file is kept only as a human-readable mirror of `OWNED_TABLES`; if you
  add a table, update both `ModuleManagerListener::OWNED_TABLES` (the one
  that matters) and this file together.
- Every `public/*.php` bootstraps `globals.php` at the correct relative
  depth (`__DIR__ . '/../../../../globals.php'`) and performs CSRF → ACL →
  session identity in that order.
- `ops/ci/check-additivity.sh origin/main` passes clean.

## Honest gaps — what's still owed for a truly production build

- **In-process baseline + load numbers are CAPTURED** (`ops/load/RESULTS.md`
  Part A, via `ops/load/bench/`): real CPU/memory/latency/throughput of the
  module's compute at concurrency 1/10/50, plus a live dashboard + alert-firing
  demonstration (`ops/load/bench/dashboard-demo.php`) and an end-to-end demo
  (`ops/demo/run-demo.php`). What is **still owed** is the **full-stack HTTP**
  capture (`ops/load/RESULTS.md` Part B — `baseline/capture-baseline.sh` +
  `k6/*.js`): it needs a reachable, seeded Apache+PHP-FPM+MySQL+LLM stack, which
  does not exist in this build environment. R8/R9 are empirically satisfied at
  the module-compute layer; the end-to-end web-stack layer remains a dev-stack
  runbook step.
- **`ops/cost-analysis.md`'s prompt-size inputs are now MEASURED**
  (`ops/load/bench/measure-tokens.php` — real prompt assembly over the fixtures;
  see "Measured token counts" there). The **usage levers** (warm hit-rate,
  sessions/turns per patient) and **output** token counts remain estimates —
  no production chat/synthesis traffic exists yet (synthetic patients only,
  OPEN-1). Re-run once `mod_copilot_doc`/`mod_copilot_chat_turn`'s `tokens_in`/
  `tokens_out` columns have real data.
- **Vertex context-cache storage pricing is an assumption** in the cost
  model (flagged inline there), not confirmed against current Vertex
  documentation.
- **The QA sweep's Batch-pricing opportunity (noted in the cost analysis)
  is not implemented** — U12 ships it as synchronous Flash calls, not a
  submitted Batch job.
- **Full PHPUnit/PHPStan/PSR-12 suites have not been run** in this build
  environment (see "Validation performed," above) — run them in the docker
  dev stack before treating this build as merge-ready.
- **OPEN-1 (real-PHI redaction/BAA review) and OPEN-2 (export-before-drop
  tooling)** remain open, as recorded in `ARCHITECTURE_COMPLETE.md`'s OPEN
  section — both are hard gates before any real-PHI or production-uninstall
  scenario, not something this build unit resolves.
- **`AUDIT.md`, a demo video, a deployed URL, and a social post** are
  case-study submission artifacts tracked in `ARCHITECTURE.md`'s "still
  owed" list, outside this module's own scope entirely.
