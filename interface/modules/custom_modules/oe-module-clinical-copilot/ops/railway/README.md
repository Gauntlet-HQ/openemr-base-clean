# Seeding synthetic patients on Railway

The cohort seeder (`tests/Seed/SeedEndoCohort.php`, 50 patients ENDO-001..050)
and the landmine seeder (`tests/Seed/SeedClinicalCopilot.php`, CCP-001..004) run
unchanged on a Railway deploy — the only thing the cloud path needs beyond the
local dev path is the **synthetic-only opt-in**, because the dev-stack marker
(`docker/development-easy/`) the seeders normally gate on isn't the right signal
in production-shaped containers.

> ⚠️ **Synthetic data only.** These scripts write fabricated patients directly
> into core tables. Never run them against a deployment carrying real PHI.
> Railway has no HIPAA BAA (T24 / OPEN-3) — setting the opt-in is your assertion
> that this environment is synthetic-only.

## The opt-in

Each seeder refuses to run outside the dev stack unless
`CLINICAL_COPILOT_SEED_ALLOW=1` is set in the environment, and always requires
`--force`. Add the variable to the Railway service (Variables tab, or
`railway variables --set CLINICAL_COPILOT_SEED_ALLOW=1`) — or let `seed.sh`
export it for you for a one-off run.

## One command

`ops/railway/seed.sh` wraps both seeders: it exports the opt-in, drops from root
to the web user if needed (OpenEMR's CLI guard forbids uid 0), and runs the
landmine + cohort seeders idempotently.

Run it **inside the deployed container, after the first boot has finished the
OpenEMR install** (the seeders need a configured DB + schema). The Railway way
to get a shell in the running service is `railway ssh`:

```bash
railway ssh --service <your-openemr-service>
# then, in the container shell:
sh interface/modules/custom_modules/oe-module-clinical-copilot/ops/railway/seed.sh
```

Environment overrides (defaults match the `openemr/openemr:flex` image this
repo's `Dockerfile.railway` builds on):

| Variable | Default | Purpose |
|---|---|---|
| `OPENEMR_DIR` | `/var/www/localhost/htdocs/openemr` | OpenEMR web root |
| `OPENEMR_WEB_USER` | `apache` | web user to run the CLI as (non-root) |
| `SEED_LANDMINES` | `1` | also seed CCP-001..004; set `0` for cohort only |

Re-running is safe — both seeders are idempotent (fixed RNG seed → identical
cohort; each patient's dependent rows are rewritten, others left untouched).

## Running it straight from the seeder (no wrapper)

```bash
export CLINICAL_COPILOT_SEED_ALLOW=1
php interface/modules/custom_modules/oe-module-clinical-copilot/tests/Seed/SeedEndoCohort.php --force
# (as the web user — e.g. `su -s /bin/sh apache -c '...'` if you are root)
```

## Optional: seed automatically on deploy

Keep it operator-triggered by default (a stray auto-seed on every deploy is
rarely what you want). If you do want a fresh deploy to self-seed once its
install is up, add a guarded call near the end of `railway-entrypoint.sh`
*before* it `exec`s `openemr.sh`, backgrounded so it waits for the install then
seeds without blocking Apache — for example:

```sh
if [ "${CLINICAL_COPILOT_SEED_ON_BOOT:-0}" = "1" ]; then
    ( # wait for the install to create core tables, then seed once
      until php -r '$_GET["site"]="default";$ignoreAuth=true;require "/var/www/localhost/htdocs/openemr/interface/globals.php";exit(\OpenEMR\Common\Database\QueryUtils::fetchSingleValue("SELECT 1 FROM patient_data LIMIT 1","1")!==null?0:1);' 2>/dev/null; do sleep 5; done
      sh /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-clinical-copilot/ops/railway/seed.sh
    ) &
fi
```

Then set both `CLINICAL_COPILOT_SEED_ALLOW=1` and `CLINICAL_COPILOT_SEED_ON_BOOT=1`
on the service. (Left as a documented snippet, not wired in, so the deploy path
stays under your control.)
