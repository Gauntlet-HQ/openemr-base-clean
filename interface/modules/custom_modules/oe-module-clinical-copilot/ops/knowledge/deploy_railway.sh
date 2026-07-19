#!/usr/bin/env bash
#
# Clinical Co-Pilot -- provision the medical-knowledge Postgres (Railway or any).
#
# Applies ops/knowledge/schema.sql -- CREATE EXTENSION vector, the guideline_chunks
# table, and the HNSW + GIN indexes -- to a target Postgres. Idempotent: schema.sql
# is all IF NOT EXISTS, so re-running only fills gaps. This is the SEPARATE,
# PHI-free knowledge database; it must never point at OpenEMR's MySQL or anything
# holding patient data.
#
# By default this ensures pgvector (schema.sql runs CREATE EXTENSION vector) AND
# seeds the in-repo corpus, so a fresh Postgres is deploy-ready in one command.
#
# Usage (from anywhere):
#   ops/knowledge/deploy_railway.sh "postgresql://user:pass@host:5432/db?sslmode=require"
#   ops/knowledge/deploy_railway.sh              # reads CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL
#   ops/knowledge/deploy_railway.sh --schema-only ...   # skip the corpus seed (extension + tables only)
#
# The corpus seed needs php + the pdo_pgsql extension. If those aren't on the box
# you run this from (e.g. your laptop), the schema/extension still apply and the
# seed is skipped with a note -- run seed_from_corpus.php inside the app container
# instead (it has pdo_pgsql), which is the recommended deploy hook anyway.
#
# psql resolution: uses host `psql` if present, otherwise a throwaway
# pgvector/pgvector:pg16 container (so no host Postgres client is required, just
# Docker). For Railway, grab the PUBLIC connection URL from the Postgres plugin's
# "Connect" tab (the *.proxy.rlwy.net host, not the internal one) so it is
# reachable from your machine.
#
# @package OpenEMR\Modules\ClinicalCopilot
# @license GNU General Public License 3

set -euo pipefail

log()  { printf '\n\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m%s\033[0m\n' "$*" >&2; }
die()  { printf '\n\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCHEMA_SQL="$SCRIPT_DIR/schema.sql"
SEED_PHP="$SCRIPT_DIR/seed_from_corpus.php"
[ -f "$SCHEMA_SQL" ] || die "schema.sql not found next to this script"

SEED=1
URL=""
for arg in "$@"; do
    case "$arg" in
        --seed)        SEED=1 ;;
        --schema-only) SEED=0 ;;
        --*)           die "unknown flag: $arg" ;;
        *)             URL="$arg" ;;
    esac
done
URL="${URL:-${CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL:-}}"

[ -n "$URL" ] || die "no connection URL. Pass one as an argument or set CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL"
case "$URL" in
    postgres://*|postgresql://*) : ;;
    mysql://*) die "that is a MySQL URL. This is the SEPARATE, PHI-free knowledge Postgres -- never point it at OpenEMR's database." ;;
    *) die "expected a postgres:// or postgresql:// URL" ;;
esac

# Apply the schema, preferring a host psql and falling back to a dockerized one.
if command -v psql >/dev/null 2>&1; then
    log "Applying schema.sql with host psql..."
    psql "$URL" -v ON_ERROR_STOP=1 -f "$SCHEMA_SQL"
elif command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    log "Applying schema.sql via a throwaway pgvector/pgvector:pg16 container..."
    docker run --rm -i pgvector/pgvector:pg16 \
        psql "$URL" -v ON_ERROR_STOP=1 -f - < "$SCHEMA_SQL"
else
    die "need either 'psql' on PATH or a running Docker daemon to apply the schema"
fi

log "Schema applied."

if [ "$SEED" = "1" ]; then
    if [ -f "$SEED_PHP" ] && command -v php >/dev/null 2>&1 && php -r 'exit(in_array("pgsql",PDO::getAvailableDrivers(),true)?0:1);' 2>/dev/null; then
        log "Seeding the in-repo corpus (full-text; vector-index later via the UI/CLI ingest)..."
        CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL="$URL" php "$SEED_PHP"
    else
        warn "Skipping the corpus seed: php with the pdo_pgsql extension is not available here."
        warn "The schema + pgvector extension were applied. Seed from inside the app container instead:"
        warn "  php <module>/ops/knowledge/seed_from_corpus.php   (with CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL set)"
    fi
fi

cat <<EOF

$(printf '\033[1;32m✓ knowledge store provisioned\033[0m')

  Point the app at it (Railway injects these on the OpenEMR service):
    CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL=$URL
    CLINICAL_COPILOT_GEMINI_API_KEY=...     # embeddings; without it, full-text only

  The app container also needs the pdo_pgsql PHP extension (see docs/knowledge-base.md).
EOF
