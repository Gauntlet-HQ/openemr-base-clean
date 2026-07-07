# ONBOARDING.md — reading OpenEMR for the first time

> Rendered from `repomap.json` (12/12 subtrees indexed, none quarantined). Companion: `INDEX.md` for the terse build-facing map.

## What this system is

OpenEMR is an open-source electronic medical record and practice-management web application: patient charts, encounters and clinical forms, scheduling, billing/claims (X12), e-prescribing, clinical decision rules, a patient portal, and standards-based interoperability (REST + FHIR R4 APIs, OAuth2/SMART-on-FHIR, C-CDA documents for care coordination). It is a PHP 8.2+ monolith backed by MySQL/MariaDB, with one Node.js side-service for C-CDA generation.

The defining architectural fact: **the codebase is three generations deep, in a deliberate, ongoing migration.** You will constantly see the same job done three ways:

1. **Legacy procedural** — `library/*.inc.php` helpers and ~590 standalone PHP pages under `interface/` that mix PHP and HTML.
2. **Legacy OO** — `controller.php` + `controllers/C_*.class.php` + Smarty views; the Laminas (Zend) module app under `interface/modules/zend_modules`.
3. **Modern PSR-4** — `src/` (`OpenEMR\` namespace): typed services, REST/FHIR controllers, Symfony kernel/event-dispatcher, Twig templates.

New code belongs in generation 3. The legacy layers are load-bearing but explicitly *not* the standard to imitate (see CLAUDE.md). Much of `library/` is now a thin shim delegating to `src/` classes.

## How a request flows

**Clinician UI.** `index.php` resolves the site (multitenancy: `sites/<site>/sqlconf.php` holds DB creds), then redirects to `interface/login/login.php`. After login you land in `interface/main/tabs/main.php`, a tabbed frame shell. Every page under `interface/` starts with `require_once .../globals.php` — the single most important file to read in the whole repo. `interface/globals.php` wires: Composer autoload → error handler → session (read-only unless the page set `$sessionAllowWrite` first) → site resolution → the `globals` DB table into an `OEGlobalsBag` singleton → DB connection (`library/sql.inc.php`) → authentication (`library/auth.inc.php`, unless `$ignoreAuth`) → module system boot. Pages then emit HTML directly, escaping with `text()`/`attr()`/`xl*()` helpers, or (newer pages, ~28 of them) render Twig via `TwigContainer`.

**APIs.** `apis/dispatch.php` and `oauth2/authorize.php` both funnel into `OpenEMR\RestControllers\ApiApplication`, with route maps (plain PHP arrays keyed `"METHOD /path"`) in `apis/routes/`. FHIR R4 controllers live in `src/RestControllers/FHIR/`, backed by `src/Services/FHIR/` mapping services.

**Patient portal.** `portal/` is a parallel app with its own session cookie. Every authenticated portal page includes `portal/verify_session.php`, which validates the patient session and then includes `interface/globals.php` with clinician auth disabled (`$ignoreAuth_onsite_portal`). All queries are pinned to the session's patient id — request-supplied pids are rejected. Inside `portal/patient/` sits a vendored Phreeze mini-MVC app serving the patient JSON API.

**C-CDA documents.** The Carecoordination (Laminas) module talks over a raw TCP socket to `ccdaservice/serveccda.js`, a Node daemon on 127.0.0.1:6661 (protocol: XML delimited by the 0x1C file-separator byte). Sibling services: CQM calculation on 6660, schematron validation on 6662. The ports are a fixed PHP↔Node contract. The `oe-blue-button-*` directories are vendored forks — treat as vendor code.

## Where the important code lives

- `src/Services/` — ~100 domain services extending `BaseService` (`PatientService`, `EncounterService`, …); the canonical business-logic home. They return `ProcessingResult` envelopes.
- `src/Common/` — cross-cutting: `Database\QueryUtils` (the DB access API), `Acl\AclMain`, `Csrf\CsrfUtils`, `Session\*`, `Twig\TwigContainer`, `Crypto`, `Logging`.
- `src/Events/` + Symfony EventDispatcher — the extension surface. Modules integrate by subscribing to events, never by patching core.
- `library/options.inc.php` — the layout/LBF field-rendering engine (one branch per `data_type`); snapshot-tested, fixtures regenerated with `openemr-cmd ulff`.
- `interface/forms/<name>/` — per-encounter clinical forms, each a directory with `new/save/view/report.php` + `info.txt` + `table.sql`.
- `templates/` — Twig 3 for most modern UI. **Twig autoescape is globally OFF**; escaping is explicit via `|text`, `|attr`, `|xlt`-family filters. This is the sharpest knife in the drawer: `|raw` on user data is an XSS.
- `interface/modules/custom_modules/oe-module-*` — the modern plugin pattern (composer.json + `openemr.bootstrap.php` + event subscribers), managed by the Module Manager UI.

## How to build, run, and test

Dev environment is Docker-first; you need no host PHP/Node:

```bash
cd docker/development-easy && docker compose up --detach --wait
# app: http://localhost:8300 (admin/pass), phpMyAdmin :8310, selenium, mailpit
```

Everything routes through `openemr-cmd` (see CLAUDE.md and CONTRIBUTING.md; worktree users: `openemr-cmd worktree exec <branch> <cmd>`):

- Tests: `openemr-cmd clean-sweep-tests` (all) or `ut`/`at`/`et`/`st`/`pit` per suite. The suite split matters: `phpunit.xml` suites need the full DB-backed stack; `phpunit-isolated.xml` runs with no DB (fast — Twig compile/render tests live here); E2E drives real Chrome via Selenium + symfony/panther.
- Quality: `openemr-cmd code-quality` = codespell + `php -l` + phpcs/phpcbf + **PHPStan level 10** (with ~18 custom project rules in `tests/PHPStan/Rules/` that forbid `$GLOBALS` access, raw session writes, catching `\Exception` instead of `\Throwable`, etc.) + rector + require-checker.
- Frontend: `npm run build` compiles ~40 SCSS theme variants (webpack) into gitignored `public/themes/` and postinstall copies vendored browser libs into gitignored `public/assets/`.

CI (GitHub Actions) fans a test matrix across `ci/<webserver>_<php>_<db>` compose directories — **the directory name is the configuration** (e.g. `apache_85_118` = Apache + PHP 8.5 + MariaDB 11.8). PRs run PHP 8.2 configs only unless the commit carries a `Test-Mode: full` trailer.

## Database changes — the one workflow you must not improvise

Schema changes ship **twice**: once in `sql/database.sql` (fresh installs) and once as idempotent statements in the *current* `sql/<from>-to-<to>_upgrade.sql`, wrapped in the project's comment-directive language (`#IfNotTable`, `#IfMissingColumn`, … `#EndIf`) that `sql_upgrade.php` interprets. Bump `$v_database` in `version.php` and the `-- v_database:` header in `database.sql`; CI fails if they drift. Historical upgrade files are **append-only** — production sites already ran them; retroactive edits silently diverge schemas. The Doctrine Migrations scaffolding under `db/` is explicitly not live yet (blocked on upstream issue #10708) — don't ship changes through it.

## Sharp edges

- **Escaping is manual everywhere.** No Twig autoescape; PHP pages emit HTML directly. Every output goes through `text()`/`attr()`/`xl*()` (or the Twig filter equivalents). Semgrep config knows these sanitizers; so should you.
- **Order matters in page bootstraps.** Flags like `$sessionAllowWrite` and `$ignoreAuth` must be set *before* including `globals.php`. Sessions open read-only by default.
- **Generated/vendored code is everywhere and looks first-party.** `src/FHIR/R4/` (895 generated FHIR classes), `src/Gacl/` and root `gacl/` (vendored phpGACL), PostCalendar inside `interface/main/calendar/`, Phreeze inside `portal/`, FPDF inside `library/classes/`, blue-button forks inside `ccdaservice/`. Check `INDEX.md`'s do-not-touch list before editing anything that smells foreign.
- **`sites/` is runtime data**, including live DB credentials (`sites/default/sqlconf.php`). Never commit changes there.
- **Fixtures are commands, not files.** Twig render fixtures and layout-field snapshots are regenerated (`utf` / `ulff`), never hand-edited.
- **Suspected bugs were flagged during indexing** (unverified — investigate before acting): a concurrency race in `ccdaservice/serveccda.js` that could cross-deliver patient documents plus a PHI-writing debug flag (see `ccdaservice/buganalysis.md`), raw exception messages leaking to API clients from `apis/dispatch.php` (root `buganalysis.md`), and dead env-var fallbacks in the E2E base trait (`tests/buganalysis.md`).

## Quarantined subtrees

None. All 12 subtree agents completed and their partials are in `.index/partials/`.

## Suggested first hour

1. Read `interface/globals.php` top to bottom — it explains half the repo.
2. Read one modern service (`src/Services/PatientService.php`) and one legacy page (`interface/patient_file/summary/demographics.php`) side by side to internalize the two idioms.
3. Skim `src/Common/Twig/TwigExtension.php` for the filter vocabulary, then any `templates/**/*.html.twig`.
4. Run `openemr-cmd phpunit-isolated` for a fast green signal, then browse http://localhost:8300 (admin/pass).
