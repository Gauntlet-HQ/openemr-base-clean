#!/bin/sh
# Railway: install + enable the Clinical Co-Pilot module once OpenEMR's base
# auto-install has finished.
#
# Launched in the BACKGROUND by railway-entrypoint.sh, right before it `exec`s
# ./openemr.sh (which runs the OpenEMR base install and then Apache in the
# foreground). openemr.sh does NOT know about custom modules, so without this
# the mod_copilot_* tables, the `modules` row, and the warm worker are never
# created on a Railway deploy. This polls for the base install to finish, then
# runs the module's own idempotent installer -- the same one ops/local/setup.sh
# runs locally. Safe to run on every boot/restart.
#
# What ops/local/install-module.php does:
#   - registers + activates the `modules` row for the copilot
#   - runs table.sql (creates mod_copilot_* tables + seeds the cadence /
#     threshold / rate-limit config; directive-aware #IfNotTable, idempotent)
#   - registers + activates the clinical_copilot_worker background service
#
# Demo seeding is ON by default: this is a demo/testing deployment and a Railway
# redeploy rebuilds the whole environment, so the synthetic diabetes patients are
# re-created every deploy. To DISABLE it -- e.g. if this instance ever carries
# real charts -- set CLINICAL_COPILOT_SEED_DEMO=0 on the service.
set -u

OPENEMR_ROOT=/var/www/localhost/htdocs/openemr
MODULE_REL=interface/modules/custom_modules/oe-module-clinical-copilot
SQLCONF="${OPENEMR_ROOT}/sites/default/sqlconf.php"
AUTOLOAD="${OPENEMR_ROOT}/vendor/autoload.php"
INSTALLER="${OPENEMR_ROOT}/${MODULE_REL}/ops/local/install-module.php"
SEEDER="${OPENEMR_ROOT}/${MODULE_REL}/tests/Seed/SeedClinicalCopilot.php"

log() { echo "Railway copilot install: $*"; }

log "waiting for the OpenEMR base install to finish (sqlconf config=1 + vendor + module present)..."

# A cold flex boot runs composer + npm + the SQL install; allow up to ~30 min.
# On a restart with a persisted DB the checks pass immediately.
tries=360
while [ "${tries}" -gt 0 ]; do
    if [ -f "${SQLCONF}" ] \
        && grep -qE '\$config[[:space:]]*=[[:space:]]*1' "${SQLCONF}" 2>/dev/null \
        && [ -f "${AUTOLOAD}" ] \
        && [ -f "${INSTALLER}" ]; then
        break
    fi
    tries=$((tries - 1))
    sleep 5
done

if [ "${tries}" -eq 0 ]; then
    log "TIMED OUT waiting for the base install; module NOT installed." >&2
    log "Recover by redeploying, or install via Admin -> Modules -> Manage Modules." >&2
    exit 1
fi

log "OpenEMR base install detected."

# The base install hammers MySQL (hundreds of CREATE TABLEs + reference-data
# inserts). On a small Railway MySQL that pushes it to the edge -- "MySQL server
# has gone away", lock-wait timeouts, even a restart. Do NOT pile the module
# install and seed on top the instant the base install finishes: let MySQL
# quiesce first, then run each step gently and retry transient disconnects
# rather than failing the whole deploy on one blip. Tunable via
# CLINICAL_COPILOT_SETTLE_SECONDS (default 30).
SETTLE="${CLINICAL_COPILOT_SETTLE_SECONDS:-30}"
log "letting MySQL settle for ${SETTLE}s before touching it (avoids piling onto the base install)."
sleep "${SETTLE}"

# OpenEMR CLI scripts refuse UID 0 (RootCliGuard); run as the web user, exactly
# as ops/local/setup.sh does inside the dev container.
run_as_apache() {
    su -s /bin/sh apache -c "$1"
}

# Retry an idempotent step across transient MySQL disconnects (error 2006,
# "MySQL server has gone away") with a widening backoff, so a stressed DB gets
# time to recover instead of the deploy giving up on the first drop.
run_as_apache_retry() {
    _label="$1"
    _cmd="$2"
    _attempt=1
    _max="${CLINICAL_COPILOT_MAX_ATTEMPTS:-5}"
    while :; do
        if run_as_apache "${_cmd}"; then
            return 0
        fi
        if [ "${_attempt}" -ge "${_max}" ]; then
            return 1
        fi
        _wait=$((_attempt * 15))
        log "${_label}: attempt ${_attempt}/${_max} failed (likely a transient DB disconnect); waiting ${_wait}s for MySQL to recover, then retrying." >&2
        sleep "${_wait}"
        _attempt=$((_attempt + 1))
    done
}

if run_as_apache_retry "module install" "php '${INSTALLER}'"; then
    log "module installed + enabled."
else
    log "module install FAILED after retries (see output above)." >&2
    exit 1
fi

if [ "${CLINICAL_COPILOT_SEED_DEMO:-1}" = "1" ]; then
    if [ -f "${SEEDER}" ]; then
        # Breather between the install and the seed so we are not back-to-back
        # against MySQL.
        sleep 10
        log "seeding synthetic demo patients (on by default; set CLINICAL_COPILOT_SEED_DEMO=0 to disable)."
        # Pass the opt-in through explicitly: su may strip the environment, and
        # the seeder honors CLINICAL_COPILOT_SEED_DEMO=1 as its authorization.
        if run_as_apache_retry "demo seeding" "CLINICAL_COPILOT_SEED_DEMO=1 php '${SEEDER}' --force"; then
            log "demo patients seeded."
        else
            log "demo seeding FAILED after retries (non-fatal -- the module itself is installed). See the seeder output above for the reason." >&2
        fi
    else
        log "CLINICAL_COPILOT_SEED_DEMO=1 but the seeder was not found at ${SEEDER}; skipping demo seed." >&2
    fi
else
    # Explicitly opted out; log it so an empty chart is self-explanatory.
    log "CLINICAL_COPILOT_SEED_DEMO=0 -- demo seeding disabled by the operator. The module is installed but no synthetic patients were added."
fi

log "done."
