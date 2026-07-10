#!/usr/bin/env sh
# Re-apply MySQL reference variables before deploy (GitLab CI + RAILWAY_TOKEN).
# Does not overwrite OE_* or Gemini vars already set on the Railway service.
set -eu

SVC_ID="${SVC_ID:-${RAILWAY_SERVICE_ID:-}}"
MYSQL_SERVICE="${RAILWAY_MYSQL_SERVICE_NAME:-MySQL}"

if [ -z "${RAILWAY_TOKEN:-}" ]; then
    echo "RAILWAY_TOKEN is not set." >&2
    exit 1
fi

if [ -z "${SVC_ID}" ]; then
    echo "RAILWAY_SERVICE_ID is not set." >&2
    exit 1
fi

ENV_FLAG=""
if [ -n "${RAILWAY_ENVIRONMENT:-}" ]; then
    ENV_FLAG="--environment ${RAILWAY_ENVIRONMENT}"
fi

echo "Syncing MySQL reference variables for service ${SVC_ID} (db service: ${MYSQL_SERVICE})..."

OE_PASS_VALUE="${RAILWAY_OE_PASS:-pass}"

# shellcheck disable=SC2086
railway variable set \
    "MYSQLHOST=\${{${MYSQL_SERVICE}.MYSQLHOST}}" \
    "MYSQLPORT=\${{${MYSQL_SERVICE}.MYSQLPORT}}" \
    "MYSQLUSER=\${{${MYSQL_SERVICE}.MYSQLUSER}}" \
    "MYSQLPASSWORD=\${{${MYSQL_SERVICE}.MYSQLPASSWORD}}" \
    "MYSQLDATABASE=\${{${MYSQL_SERVICE}.MYSQLDATABASE}}" \
    "MYSQL_USER=openemr" \
    "MYSQL_PASS=${OE_PASS_VALUE}" \
    "PORT=80" \
    --service "${SVC_ID}" \
    ${ENV_FLAG} \
    --skip-deploys

if [ -n "${RAILWAY_OE_PASS:-}" ]; then
    # shellcheck disable=SC2086
    railway variable set "OE_PASS=${RAILWAY_OE_PASS}" --service "${SVC_ID}" ${ENV_FLAG} --skip-deploys
fi

if [ -n "${CLINICAL_COPILOT_GEMINI_API_KEY:-}" ]; then
    # shellcheck disable=SC2086
    railway variable set \
        "CLINICAL_COPILOT_GEMINI_API_KEY=${CLINICAL_COPILOT_GEMINI_API_KEY}" \
        --service "${SVC_ID}" \
        ${ENV_FLAG} \
        --skip-deploys
fi

echo "Railway variables synced."
