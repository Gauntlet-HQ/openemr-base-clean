#!/bin/sh
# Map Railway MySQL plugin variables to OpenEMR flex expectations.
set -eu

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

if [ -z "${MYSQL_HOST:-}" ] || [ -z "${MYSQL_ROOT_PASS:-}" ]; then
    echo "Railway OpenEMR: missing database connection variables." >&2
    echo "Add references on this service (replace MySQL with your DB service name):" >&2
    echo "  MYSQLHOST=\${{MySQL.MYSQLHOST}}" >&2
    echo "  MYSQLPORT=\${{MySQL.MYSQLPORT}}" >&2
    echo "  MYSQLUSER=\${{MySQL.MYSQLUSER}}" >&2
    echo "  MYSQLPASSWORD=\${{MySQL.MYSQLPASSWORD}}" >&2
    echo "  MYSQLDATABASE=\${{MySQL.MYSQLDATABASE}}" >&2
    exit 1
fi

rm -rf /openemr /openemr-base-clean

/usr/local/bin/railway-flex-bootstrap.sh

cd /var/www/localhost/htdocs
exec ./openemr.sh
