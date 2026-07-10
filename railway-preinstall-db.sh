#!/bin/sh
# Railway MySQL plugin pre-creates the database; OpenEMR installer uses CREATE DATABASE
# without IF NOT EXISTS. Prepare DB + app user so quick_install skips creation.
set -eu

: "${MYSQL_HOST:?MYSQL_HOST required}"
: "${MYSQL_ROOT_PASS:?MYSQL_ROOT_PASS required}"
: "${MYSQL_DATABASE:?MYSQL_DATABASE required}"

MYSQL_ROOT_USER="${MYSQL_ROOT_USER:-root}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-openemr}"
MYSQL_PASS="${MYSQL_PASS:-pass}"

echo "Railway DB prep: waiting for MySQL at ${MYSQL_HOST}:${MYSQL_PORT}..."

tries=60
while [ "${tries}" -gt 0 ]; do
    if mariadb --skip-ssl \
        -h "${MYSQL_HOST}" -P "${MYSQL_PORT}" \
        -u "${MYSQL_ROOT_USER}" -p"${MYSQL_ROOT_PASS}" \
        -e "SELECT 1" >/dev/null 2>&1; then
        break
    fi
    tries=$((tries - 1))
    sleep 2
done

if [ "${tries}" -eq 0 ]; then
    echo "Railway DB prep: timed out waiting for MySQL" >&2
    exit 1
fi

echo "Railway DB prep: ensuring database and application user exist"

mariadb --skip-ssl \
    -h "${MYSQL_HOST}" -P "${MYSQL_PORT}" \
    -u "${MYSQL_ROOT_USER}" -p"${MYSQL_ROOT_PASS}" \
    -e "CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

mariadb --skip-ssl \
    -h "${MYSQL_HOST}" -P "${MYSQL_PORT}" \
    -u "${MYSQL_ROOT_USER}" -p"${MYSQL_ROOT_PASS}" \
    -e "CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'%' IDENTIFIED BY '${MYSQL_PASS}'; \
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_USER}'@'%'; \
FLUSH PRIVILEGES;"

# Harden server settings before the heavy base install to remove the
# timeout/packet-size flavours of "MySQL server has gone away" (error 2006):
#   - larger max_allowed_packet so a big install INSERT is not rejected/dropped
#   - long wait/interactive timeouts so an idle-between-statements connection is
#     not reaped mid-install
#   - generous net read/write timeouts so a slow statement on a busy server is
#     not treated as a dead connection
#   - a longer innodb_lock_wait_timeout so background stats/histogram locks do
#     not abort install statements (the log showed these lock-wait warnings)
# These are GLOBALs (apply to the base install's fresh connections). They do NOT
# fix out-of-memory: if MySQL restarts under memory pressure these reset and the
# only real fix is a larger DB instance. Non-fatal -- a managed MySQL may withhold
# SET GLOBAL, and that must not abort the deploy.
echo "Railway DB prep: raising server timeouts / packet size (non-fatal if not permitted)"
mariadb --skip-ssl \
    -h "${MYSQL_HOST}" -P "${MYSQL_PORT}" \
    -u "${MYSQL_ROOT_USER}" -p"${MYSQL_ROOT_PASS}" \
    -e "SET GLOBAL max_allowed_packet = 67108864; \
SET GLOBAL wait_timeout = 28800; \
SET GLOBAL interactive_timeout = 28800; \
SET GLOBAL net_read_timeout = 600; \
SET GLOBAL net_write_timeout = 600; \
SET GLOBAL innodb_lock_wait_timeout = 300;" \
    2>&1 || echo "Railway DB prep: could not raise server settings (insufficient privilege on managed MySQL?); continuing"

echo "Railway DB prep: done"
