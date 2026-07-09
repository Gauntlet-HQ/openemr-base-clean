#!/bin/sh
# Seed the synthetic endocrinology cohort (ENDO-001..ENDO-050) on a Railway
# deploy -- or any synthetic-only cloud target. Idempotent: re-running rewrites
# each ENDO-* patient's dependent rows and leaves everything else untouched
# (fixed RNG seed => identical cohort every run).
#
# SYNTHETIC DATA ONLY. This writes fabricated patients into core tables; never
# run it against a deployment carrying real PHI. Setting the opt-in below is
# your assertion that this box is synthetic-only (Railway has no HIPAA BAA).
#
# Usage (run INSIDE the deployed container, after the first successful boot has
# finished the OpenEMR install), e.g. via `railway ssh` into the service:
#   sh interface/modules/custom_modules/oe-module-clinical-copilot/ops/railway/seed.sh
#
# Also seeds the 4 hand-authored landmine patients (CCP-001..004) unless
# SEED_LANDMINES=0. Override OPENEMR_DIR / OPENEMR_WEB_USER if your image
# differs from the openemr/openemr:flex defaults.
set -eu

OPENEMR_DIR="${OPENEMR_DIR:-/var/www/localhost/htdocs/openemr}"
WEB_USER="${OPENEMR_WEB_USER:-apache}"
SEED_LANDMINES="${SEED_LANDMINES:-1}"

MODULE_DIR="${OPENEMR_DIR}/interface/modules/custom_modules/oe-module-clinical-copilot"
COHORT="${MODULE_DIR}/tests/Seed/SeedEndoCohort.php"
LANDMINES="${MODULE_DIR}/tests/Seed/SeedClinicalCopilot.php"

if [ ! -f "${COHORT}" ]; then
    echo "seed.sh: cannot find ${COHORT} -- set OPENEMR_DIR to your OpenEMR web root." >&2
    exit 1
fi

# The opt-in that authorizes the seeder outside the dev stack (see the guard in
# SeedEndoCohort.php). Exported so it survives the su into the web user.
export CLINICAL_COPILOT_SEED_ALLOW=1

# OpenEMR's CLI guard refuses to run as root (uid 0). If we are root, drop to
# the web user; otherwise run directly (we are already the web user).
run_php() {
    _script="$1"
    if [ "$(id -u)" = "0" ]; then
        su -s /bin/sh "${WEB_USER}" -c "CLINICAL_COPILOT_SEED_ALLOW=1 php '${_script}' --force"
    else
        php "${_script}" --force
    fi
}

if [ "${SEED_LANDMINES}" = "1" ] && [ -f "${LANDMINES}" ]; then
    echo "seed.sh: seeding landmine patients (CCP-001..004)..."
    run_php "${LANDMINES}"
fi

echo "seed.sh: seeding endo cohort (ENDO-001..050)..."
run_php "${COHORT}"

echo "seed.sh: done."
