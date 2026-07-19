#!/bin/sh
# Railway entrypoint: env mapping, bootstrap, DB prep, then flex openemr.sh (starts Apache).
set -eu

trap 'echo "Railway entrypoint failed (line ${LINENO})" >&2' ERR

echo "Railway entrypoint: starting"

if [ -z "${MYSQL_HOST:-}" ] && [ -n "${MYSQLHOST:-}" ]; then
    export MYSQL_HOST="${MYSQLHOST}"
fi

if [ -z "${MYSQL_PORT:-}" ] && [ -n "${MYSQLPORT:-}" ]; then
    export MYSQL_PORT="${MYSQLPORT}"
fi

if [ -z "${MYSQL_DATABASE:-}" ] && [ -n "${MYSQLDATABASE:-}" ]; then
    export MYSQL_DATABASE="${MYSQLDATABASE}"
fi

if [ -z "${MYSQL_ROOT_USER:-}" ] && [ -n "${MYSQLUSER:-}" ]; then
    export MYSQL_ROOT_USER="${MYSQLUSER}"
fi

if [ -z "${MYSQL_ROOT_PASS:-}" ]; then
    if [ -n "${MYSQL_ROOT_PASSWORD:-}" ]; then
        export MYSQL_ROOT_PASS="${MYSQL_ROOT_PASSWORD}"
    elif [ -n "${MYSQLPASSWORD:-}" ]; then
        export MYSQL_ROOT_PASS="${MYSQLPASSWORD}"
    fi
fi

if [ -z "${MYSQL_USER:-}" ] && [ -n "${OPENEMR_MYSQL_USER:-}" ]; then
    export MYSQL_USER="${OPENEMR_MYSQL_USER}"
fi

if [ -z "${MYSQL_PASS:-}" ] && [ -n "${OPENEMR_MYSQL_PASS:-}" ]; then
    export MYSQL_PASS="${OPENEMR_MYSQL_PASS}"
fi

export MYSQL_USER="${MYSQL_USER:-openemr}"
export MYSQL_PASS="${MYSQL_PASS:-pass}"
export MYSQL_DATABASE="${MYSQL_DATABASE:-railway}"

if [ -z "${MYSQL_HOST:-}" ] || [ -z "${MYSQL_ROOT_PASS:-}" ]; then
    echo "Railway OpenEMR: missing database connection variables." >&2
    exit 1
fi

/usr/local/bin/railway-configure-apache.sh

rm -rf /openemr /openemr-base-clean

echo "Railway entrypoint: flex bootstrap"
/usr/local/bin/railway-flex-bootstrap.sh

echo "Railway entrypoint: database prep"
/usr/local/bin/railway-preinstall-db.sh

# Install + enable the Clinical Co-Pilot module once the base install finishes.
# openemr.sh (below) runs the OpenEMR base install and then Apache in the
# foreground and never returns, so this must run in the background: it polls for
# the base install to complete, then runs the module's idempotent installer.
echo "Railway entrypoint: scheduling Clinical Co-Pilot module install (runs in background after base install)"
/usr/local/bin/railway-install-copilot.sh &

# Optional full-cohort self-seed (ENDO-001..050 + landmines) on boot, so a
# redeploy against a non-persistent MySQL never leaves a near-empty chart.
# Opt-in via CLINICAL_COPILOT_SEED_ON_BOOT=1 (synthetic-only assertion, same
# stance as CLINICAL_COPILOT_SEED_ALLOW); both seeders are idempotent, so
# re-running on every deploy is safe. Backgrounded: waits for the base install
# to create core tables, then seeds without blocking Apache.
if [ "${CLINICAL_COPILOT_SEED_ON_BOOT:-0}" = "1" ]; then
    echo "Railway entrypoint: scheduling full-cohort seed (CLINICAL_COPILOT_SEED_ON_BOOT=1; runs in background after base install)"
    (
        until php -r '$_GET["site"]="default";$ignoreAuth=true;require "/var/www/localhost/htdocs/openemr/interface/globals.php";exit(\OpenEMR\Common\Database\QueryUtils::fetchSingleValue("SELECT 1 FROM patient_data LIMIT 1","1")!==null?0:1);' 2>/dev/null; do
            sleep 5
        done
        sh /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-clinical-copilot/ops/railway/seed.sh
    ) &
fi

echo "Railway entrypoint: launching openemr.sh (composer/npm/setup, then Apache on PORT=${PORT:-80})"
cd /var/www/localhost/htdocs
exec ./openemr.sh
