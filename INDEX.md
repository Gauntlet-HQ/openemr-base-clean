# INDEX.md — OpenEMR repo map (build-loop-facing)

> Rendered from `repomap.json` (source of truth). Do not hand-edit; regenerate via the brownfield-index run.
> 12/12 subtrees indexed, **0 quarantined**.

## Inventory

| Subtree | Roots | Purpose | Langs |
|---|---|---|---|
| src | `src/` | Modern PSR-4 layer: ~100 domain services (BaseService), REST/FHIR controllers, Symfony kernel/events, `Common/` infrastructure | PHP |
| library | `library/` | Legacy procedural helpers (SQL wrappers, auth, escaping, translation, options.inc.php layout renderer); thin shim over src/ | PHP, JS |
| interface-core | `interface/` excl. `modules/` | ~590 server-rendered clinician UI pages, all bootstrapping via `interface/globals.php` | PHP, JS, SCSS |
| interface-modules | `interface/modules/` | Laminas zend_modules MVC app + event-driven custom_modules (oe-module-*), DB-managed by Installer | PHP, JS, Twig, SQL |
| portal | `portal/` | Patient portal: own session cookie, `verify_session.php` gate, pid-scoped pages, Phreeze JSON mini-API | PHP, JS |
| templates | `templates/` | Twig 3 (majority, **autoescape OFF**), legacy Smarty `general_*.html`, plain-PHP CDR views | Twig, Smarty, PHP |
| tests | `tests/`, `phpunit*.xml`, `jest.config.js` | DB-backed + isolated + integration PHPUnit, Panther E2E, Jest, bats, custom PHPStan/Rector rules | PHP, JS, bash |
| ccdaservice | `ccdaservice/` | Node.js TCP daemon (127.0.0.1:6661) generating C-CDA via vendored blue-button forks; CQM 6660, schematron 6662 | JS (Node), PHP |
| sql-db | `sql/`, `db/` | `database.sql` + comment-directive upgrade SQL (live); Doctrine Migrations scaffolding (NOT yet wired, #10708) | SQL, PHP |
| frontend-build | `public/`, `webpack/`, root JS configs | Webpack 5 theme pipeline + napa/postinstall vendor-asset copier; output gitignored | JS, SCSS |
| dev-infra | `docker/`, `ci/`, `bin/`, `tools/`, `scripts/`, `cli`, `.github/workflows/` | Dev stack (openemr-cmd), CI matrix (`ci/<ws>_<php>_<db>` dir names ARE config), release tooling | YAML, bash, PHP |
| root-remainder | root entry PHP, `apis/`, `oauth2/`, `controllers/`, `gacl/`, `sites/`, `config/`, `custom/`, `contrib/`, `ccr/`, `sphere/`, `meta/`, `swagger/` | Front door: entry points, API dispatch, OAuth2, legacy C_* controllers, version/upgrade gating | PHP |

## Entry points (request topology)

- Browser: `index.php` → site resolution via `sites/<site>/sqlconf.php` → `interface/login/login.php` → `interface/main/tabs/main.php` (tabbed shell). Every interface page starts with `require_once .../globals.php`.
- REST/FHIR API: `apis/dispatch.php` → `HttpRestRequest` → `OpenEMR\RestControllers\ApiApplication` with route maps in `apis/routes/_rest_routes_{standard,fhir_r4_us_core_3_1_0,portal}.inc.php`.
- OAuth2/OIDC: `oauth2/authorize.php` (same ApiApplication dispatch pattern).
- Patient portal: `portal/index.php` (login) → authenticated pages gate via `portal/verify_session.php` (session pid + `$ignoreAuth_onsite_portal=true` → `interface/globals.php`).
- Legacy MVC: `controller.php?controller=...` → `controllers/C_*.class.php` → Smarty views `templates/*/general_*.html`.
- CLI: `bin/console` (Symfony Console, canonical), `bin/command-runner` (legacy), `./cli` (experimental Doctrine container).
- Installer/upgraders: `setup.php`, `sql_upgrade.php` (`--from=X_X_X` CLI-capable), `sql_patch.php`, `acl_upgrade.php`.
- Node side-service: `ccdaservice/serveccda.js` on 127.0.0.1:6661 (spawned on demand by the Carecoordination module; ports 6661/6660/6662 are a fixed contract).
- Health probes: `meta/health/index.php` (/livez, /readyz).

## Canonical commands (red/green gates)

```bash
# build
composer install
npm run build                     # webpack themes + CSS sync

# test (in container via openemr-cmd; worktrees: openemr-cmd worktree exec <branch> <cmd>)
openemr-cmd clean-sweep-tests     # cst — everything
openemr-cmd unit-test             # ut
openemr-cmd api-test              # at
openemr-cmd e2e-test              # et
openemr-cmd services-test         # st
openemr-cmd phpunit-isolated      # pit — no DB; host: composer phpunit-isolated
npm run test:js                   # Jest

# lint / quality
openemr-cmd code-quality          # cq = codespell + php -l + phpcbf + phpcs + phpstan(level 10) + rector-check + require-checker
npm run lint:js && npm run stylelint
./run-semgrep.sh                  # security lint, knows attr()/text()/xlt() sanitizers

# fixture regeneration (after intentional renderer/template changes; review diff before commit)
openemr-cmd update-twig-fixtures          # utf
openemr-cmd update-layout-field-fixtures  # ulff
```

PHPStan must always run over the **full codebase**; never a file subset.

## Conventions digest (deduped across subtrees)

**Placement & style**
- New PHP goes in `src/` (PSR-4 `OpenEMR\`), `declare(strict_types=1)`, PER-CS, 4-space indent, file-header docblock (`@package OpenEMR` … GPL-3; preserve existing authors). Legacy patterns in `library/`/`interface/` are historical, **not** the standard.
- Services extend `Services\BaseService` (`TABLE_NAME` const, `parent::__construct(self::TABLE_NAME)`), return `Validators\ProcessingResult`.
- DB via `Common\Database\QueryUtils` (parameterized). Legacy pages use `sqlStatement`/`sqlQuery` with `?` binds — that family now delegates to QueryUtils.
- Globals via `OEGlobalsBag::getInstance()` typed getters (not `$GLOBALS`); sessions via `SessionWrapperFactory`/`SessionUtil` (not raw `$_SESSION`). Custom PHPStan rules enforce these.
- Events: classes extending Symfony `Event` with `const EVENT_HANDLE`; modules extend core by subscribing, never by patching.

**Escaping & security (load-bearing)**
- Twig autoescape is **globally disabled** (`src/Common/Twig/TwigContainer.php`). ALL output escaping is explicit: `|text`, `|attr`, `|xlt`, `|xla`, `|xlj`, `|attr_js`, `|js_escape`, `|attr_url`, `|safe_href`. `|raw` only for PHP-side pre-escaped HTML. Same helpers as functions in PHP pages: `text()`, `attr()`, `xl*()`, `js_escape()`.
- CSRF: `CsrfUtils::collectCsrfToken()` hidden input + `CsrfUtils::checkCsrfInput(...)` in handlers, on every POST.
- ACL: `AclMain::aclCheckCore('section','perm')` at top of page; `AccessDeniedHelper` on failure.
- SQL fragments that can't be bound go through whitelist escapers (`add_escape_custom`, `escape_limit`, `escape_sort_order`, `escape_table_name`, `escape_sql_column_name`).

**Page bootstrap contract**
- Interface pages: set flags (`$sessionAllowWrite`, `$ignoreAuth`, …) **before** `require_once globals.php`; `Header::setupHeader([...])` for assets; `top.restoreSession()` in JS before navigation.
- Portal pages: `require_once verify_session.php` (session pid is authoritative; never trust request pid).

**Schema changes**
- Ship **twice**: edit `sql/database.sql` (fresh installs) AND append idempotent `#If…/#EndIf` blocks to the current `sql/<from>-to-<to>_upgrade.sql`; bump `$v_database` in `version.php` + the `-- v_database:` header. CI enforces sync. Do NOT use `db/Migrations` yet (#10708). Historical upgrade files are append-only — never edit the past.

**Modules**
- New custom module: `interface/modules/custom_modules/oe-module-<slug>/` with composer.json (own `OpenEMR\Modules\<Name>` namespace), `info.txt`, `openemr.bootstrap.php` registering namespace + subscribing `src/Bootstrap.php` to core events, optional `ModuleManagerListener`, `table.sql`/`cleanup.sql`.

**Tests**
- Data providers carry the exact annotation `@codeCoverageIgnore Data providers run before coverage instrumentation starts.`
- E2E files are alphabetically prefix-ordered (AaLoginTest…) to enforce execution order; shared logic in `E2e/Base` traits.
- Fixtures are never hand-edited — regenerate via `utf`/`ulff`.

**Commits**
- Conventional Commits (CI-validated); `Assisted-by:` trailer for AI-assisted commits.

## Dependency graph & blast radius

Direction: X → Y = X depends on Y. **Reverse edges are the review surface for a change.**

Core coupling: `src ⇄ library` (library delegates down to src; src still requires library `.inc.php` files) and everything bootstraps through `interface/globals.php`.

| Change in… | Blast radius (dependents to review) |
|---|---|
| src | everything (library, interface-core, interface-modules, portal, templates, tests, ccdaservice gateway, sql upgrade runner, dev-infra CLIs, entry points) |
| library | src, interface-core, portal, templates (filter backings), tests, sql-db runner, root entry points |
| interface-core (`globals.php` especially) | interface-modules, portal, tests (DB-backed bootstrap), ccdaservice gateway, root entry points, src (few requires), CI installs |
| templates | src renderers, interface-core pages, portal; render-fixture tests |
| interface-modules | leaf-ish (loaded at bootstrap by interface-core); Carecoordination is the ccdaservice client |
| portal | leaf — nothing depends on portal |
| ccdaservice | src (`CqmServiceManager`), interface-modules (Carecoordination); fixed ports 6661/6660/6662 |
| sql-db | src (`SQLUpgradeService`), module installer, upgrade runners; CI `database-version.yml` gate |
| frontend-build | every interface page at runtime (assets/themes); SCSS sources live in `interface/themes/` |
| dev-infra | tests (suites run inside its stacks); `ci/<dir>` names are parsed as config |
| root-remainder | runtime front door for everything; `custom/code_types.inc.php` and `version.php` are read by src and library |

Unresolved-as-third-party (no internal edge): Symfony, Laminas, Twig/Smarty, Doctrine DBAL (behind ADODB surface), PHPUnit/Jest/Mocha, firehed/container, zircote/swagger-php, vendored forks (blue-button, Phreeze/Savant3, phpGACL, FPDF, PostCalendar).

## Do-not-touch list (generated / vendored / append-only / runtime)

- `src/FHIR/R4/` (895 generated files), `src/Gacl/`, `src/Cqm/Qdm/`
- `library/classes/{fpdf,smtp}/`, `library/classes/{php-barcode.php,TreeMenu.php,PDF_Label.php}`, `library/fonts/`, `library/MedEx/`, `library/edihistory/`
- `interface/main/calendar/modules/PostCalendar/` + `interface/main/calendar/includes/`, `interface/billing/edih_*.php`, `interface/pic/`
- `interface/modules/custom_modules/oe-module-comlink-telehealth/public/assets/`, `Carecoordination/autoload_classmap.php`, per-module `table.sql`/`cleanup.sql` (version-gated)
- `portal/patient/fwk/libs/{verysimple,savant,util}/`, `portal/sign/assets/signature_pad.umd.js`, `portal/patient/scripts/libs/LAB.min.js`
- Twig render fixtures & layout-field fixtures (regen-only), `tests/Tests/Fixtures/*.json`, ECQM data, Rector rule fixtures, `tests/eventdispatcher/*` (samples)
- `ccdaservice/oe-blue-button-{generate,meta,util}/`, `ccdaservice/package-lock.json`, ports 6661/6660/6662
- All historical `sql/*_upgrade.sql` (append-only; only the newest file takes changes), released `db/Migrations`, `-- v_database:` header
- `public/assets/` & `public/themes/` (generated; exception: `public/assets/modified/` is committed patched vendor — still treat as vendor), `.webpack-cache/`
- `docker-version`, `ci/<dir>` directory names, development-easy `WT_*` compose vars, `GITHUB_COMPOSER_TOKEN*` (deliberate public token — not a leak to fix), byte-identical docker script sets
- `gacl/`, `sites/` (live per-site data incl. DB creds), `swagger/`, `contrib/`, `Documentation/`

## Suspected bugs index

Flagged during sampling (suspicion, not proof — verify before fixing):

1. [ccdaservice/buganalysis.md](ccdaservice/buganalysis.md) — 5 findings. Headline: module-global `conn`/`all` state reassigned per TCP connection with `await`s in between → concurrent requests can deliver one patient's C-CDA to another's socket; plus hardcoded `enableDebug = true` writes PHI XML to `documents/temp/`.
2. [buganalysis.md](buganalysis.md) (repo root) — 1 finding: `apis/dispatch.php` returns raw `$e->getMessage()` in JSON error responses (information disclosure; violates the repo's own error-handling standard).
3. [tests/buganalysis.md](tests/buganalysis.md) — 1 finding: dead `getenv() ?? default` fallbacks in `tests/Tests/E2e/Base/BaseTrait.php` (`getenv()` returns `false`, never `null`).

## Quarantined subtrees

None — all 12 subtree agents completed.
