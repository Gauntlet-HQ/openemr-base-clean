#!/usr/bin/env bash
# ============================================================================
# Railway entrypoint for the OpenEMR fork image (see ../Dockerfile / ../DEPLOY.md)
# ----------------------------------------------------------------------------
# Runs on every container start:
#   1. Seeds sites/ from the baked skeleton when a fresh (empty) Railway volume
#      is mounted over it.
#   2. Generates the self-signed cert the :443 vhost needs so httpd can start
#      (Railway terminates TLS at its edge and proxies plain HTTP to port 80).
#   3. Waits for the MySQL service, then runs OpenEMR's one-time
#      auto-configuration on first boot only (skips if already configured).
#   4. Execs Apache in the foreground (PHP served in-process via mod_php).
#
# Required env: MYSQL_HOST, MYSQL_ROOT_PASS
# Optional env: MYSQL_PORT (3306), MYSQL_ROOT_USER (root), MYSQL_USER (openemr),
#               MYSQL_PASS (openemr), MYSQL_DATABASE (openemr),
#               OE_USER (admin), OE_PASS (pass), plus any OPENEMR_SETTING_*.
# ============================================================================
set -euo pipefail

OE_ROOT=/var/www/localhost/htdocs/openemr
SQLCONF="${OE_ROOT}/sites/default/sqlconf.php"

# Reuse the base image's helper library: defines prepareVariables (which builds
# the CONFIGURATION string for auto_configure.php from the MYSQL_*/OE_* env)
# and setGlobalSettings (applies OPENEMR_SETTING_* env vars to the DB).
# shellcheck source=/dev/null
. /root/devtoolsLibrary.source

# ---------------------------------------------------------------------------
# Environment defaults + required checks
# ---------------------------------------------------------------------------
: "${MYSQL_HOST:?MYSQL_HOST is required (Railway MySQL private hostname)}"
: "${MYSQL_ROOT_PASS:?MYSQL_ROOT_PASS is required (Railway MySQL root password)}"
export MYSQL_PORT="${MYSQL_PORT:-3306}"
export MYSQL_ROOT_USER="${MYSQL_ROOT_USER:-root}"

# ---------------------------------------------------------------------------
# 1) Seed sites/ if an empty persistent volume is shadowing the baked dir.
# ---------------------------------------------------------------------------
if [ ! -e "${SQLCONF}" ]; then
    echo "[entrypoint] Seeding sites/ from image skeleton..."
    mkdir -p "${OE_ROOT}/sites"
    rsync -a /opt/openemr-sites-skel/ "${OE_ROOT}/sites/"
    chown -R apache:apache "${OE_ROOT}/sites"
    chmod 700 "${OE_ROOT}/sites/default" 2>/dev/null || true
    chmod 666 "${SQLCONF}" 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# 2) Self-signed cert for the :443 vhost (so Apache can load the SSL vhost).
# ---------------------------------------------------------------------------
bash /var/www/localhost/htdocs/ssl.sh || echo "[entrypoint] ssl.sh warning (continuing)"

# ---------------------------------------------------------------------------
# 3) Build the DB CONFIGURATION string and wait for MySQL.
# ---------------------------------------------------------------------------
prepareVariables

echo "[entrypoint] Waiting for MySQL at ${MYSQL_HOST}:${MYSQL_PORT}..."
tries=0
until mysqladmin ping --skip-ssl \
        --host="${MYSQL_HOST}" --port="${MYSQL_PORT}" \
        --user="${MYSQL_ROOT_USER}" --password="${MYSQL_ROOT_PASS}" \
        --silent >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "${tries}" -ge 60 ]; then
        echo "[entrypoint] ERROR: MySQL unreachable at ${MYSQL_HOST}:${MYSQL_PORT} after ~2m" >&2
        exit 1
    fi
    sleep 2
done
echo "[entrypoint] MySQL is ready."

# ---------------------------------------------------------------------------
# 4) One-time auto-configuration (only when not already configured).
# ---------------------------------------------------------------------------
CONFIG="$(php -r "if (is_file('${SQLCONF}')) { require '${SQLCONF}'; echo (isset(\$config) && \$config) ? 1 : 0; } else { echo 0; }" 2>/dev/null || echo 0)"

if [ "${CONFIG}" != "1" ]; then
    echo "[entrypoint] Configuring OpenEMR (first boot)..."
    chmod 666 "${SQLCONF}" 2>/dev/null || true
    chmod 0644 /var/www/localhost/htdocs/auto_configure.php
    cd /var/www/localhost/htdocs
    # Installer refuses to run as root (RootCliGuard); drop to apache via su-exec.
    su-exec apache php auto_configure.php -f "${CONFIGURATION}"

    echo "[entrypoint] Applying OPENEMR_SETTING_* globals (if any)..."
    setGlobalSettings || true

    # Re-harden the freshly written config; keep upload dirs writable.
    chmod 400 "${SQLCONF}" 2>/dev/null || true
    if [ -d "${OE_ROOT}/sites/default/documents" ]; then
        find "${OE_ROOT}/sites/default/documents" -type d -exec chmod 700 {} + 2>/dev/null || true
    fi
    chown -R apache:apache "${OE_ROOT}/sites" 2>/dev/null || true
    echo "[entrypoint] OpenEMR configured."
else
    echo "[entrypoint] OpenEMR already configured; skipping setup."
fi

# auto_configure.php is only needed for first-boot setup; remove it afterwards.
rm -f /var/www/localhost/htdocs/auto_configure.php 2>/dev/null || true

echo "[entrypoint] Starting Apache..."
exec /usr/sbin/httpd -D FOREGROUND
