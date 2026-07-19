#!/usr/bin/env bash
#
# Clinical Co-Pilot -- bring up the local pgvector knowledge store.
#
# Stacks the knowledge overlay (ops/local/compose.knowledge.yml) onto the dev
# stack + the gemini overlay, builds the openemr image variant that carries the
# pdo_pgsql driver, waits for Postgres health, applies the schema idempotently,
# and prints how to reach it + what to do next. Idempotent: safe to re-run.
#
# It brings up the stack, wires the env, applies the schema, AND seeds the in-repo
# corpus by default — query-ready with zero manual steps.
#
# Usage (from anywhere in the repo):
#   interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/knowledge-up.sh
#   ...  --no-seed  # bring up + wire + schema, but skip loading the in-repo corpus
#   ...  --down     # tear the whole stack down (keeps the data volume)
#
# Embeddings (vector search) use CLINICAL_COPILOT_GEMINI_API_KEY -- export it (or
# put it in ops/local/gemini.local.env) before running. Without a key the store
# still works on full-text search alone.
#
# @package OpenEMR\Modules\ClinicalCopilot
# @license GNU General Public License 3

set -euo pipefail

log()  { printf '\n\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m%s\033[0m\n' "$*" >&2; }
die()  { printf '\n\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
REPO_ROOT="$(cd "$MODULE_DIR/../../../.." && pwd)"

COMPOSE_MAIN="$REPO_ROOT/docker/development-easy/docker-compose.yml"
COMPOSE_GEMINI="$MODULE_DIR/ops/local/compose.gemini.yml"
COMPOSE_KNOWLEDGE="$MODULE_DIR/ops/local/compose.knowledge.yml"
SCHEMA_SQL="$MODULE_DIR/ops/knowledge/schema.sql"

[ -f "$COMPOSE_MAIN" ] || die "dev compose not found at $COMPOSE_MAIN"
command -v docker >/dev/null 2>&1 || die "docker is not installed / not on PATH"
docker info >/dev/null 2>&1 || die "the docker daemon is not reachable"

# Absolute paths so Compose's relative-path resolution can never misfire.
export CLINICAL_COPILOT_KB_SCHEMA="$SCHEMA_SQL"
export CLINICAL_COPILOT_KB_BUILD_CTX="$MODULE_DIR/ops/local/knowledge"
# Match openemr-cmd: let apache adopt the host uid so bind-mount writes stay
# host-owned (a no-op when your uid is already 1000).
export HOST_UID="${HOST_UID:-$(id -u)}"
export HOST_GID="${HOST_GID:-$(id -g)}"

DC=(docker compose -f "$COMPOSE_MAIN" -f "$COMPOSE_GEMINI" -f "$COMPOSE_KNOWLEDGE")

if [ "${1:-}" = "--down" ]; then
    log "Tearing the stack down (the knowledge data volume is preserved)..."
    "${DC[@]}" down
    exit 0
fi

log "Building + starting the stack (openemr + pgvector knowledge_db)..."
"${DC[@]}" up -d --build

log "Waiting for the knowledge Postgres to become healthy..."
for _ in $(seq 1 60); do
    if "${DC[@]}" exec -T knowledge_db pg_isready -U copilot -d knowledge >/dev/null 2>&1; then
        break
    fi
    sleep 2
done
"${DC[@]}" exec -T knowledge_db pg_isready -U copilot -d knowledge >/dev/null 2>&1 \
    || die "knowledge_db did not become ready in time (check: ${DC[*]} logs knowledge_db)"

# Re-apply the schema idempotently. The initdb mount only runs on a fresh volume;
# this covers a volume created before this overlay existed. schema.sql is all
# IF NOT EXISTS, so re-applying is a no-op on an up-to-date store.
log "Applying schema.sql (idempotent)..."
"${DC[@]}" exec -T knowledge_db psql -U copilot -d knowledge -v ON_ERROR_STOP=1 < "$SCHEMA_SQL" >/dev/null

if [ "${1:-}" != "--no-seed" ]; then
    log "Seeding the in-repo corpus (full-text; vector-index via the UI/CLI ingest)..."
    # Run the seeder inside the app container, where pdo_pgsql lives and the module
    # env (CLINICAL_COPILOT_KNOWLEDGE_DB_*) is already set by the overlay — so this
    # needs no manual configuration.
    "${DC[@]}" exec -T openemr \
        php /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-clinical-copilot/ops/knowledge/seed_from_corpus.php \
        || warn "Seed step failed -- the store is up regardless; re-run or seed later."
fi

cat <<EOF

$(printf '\033[1;32m✓ knowledge store is up\033[0m')

  From the app container : host=knowledge_db port=5432 db=knowledge user=copilot
  From your host (psql)  : psql "postgresql://copilot:copilot@localhost:${CLINICAL_COPILOT_KB_HOST_PORT:-55432}/knowledge"

  App URL                : http://localhost:8300/  (admin / pass)
  Maintenance → Knowledge Base (RAG): upload a PDF/text, chunk, and store.

  Deploy the SAME schema to a Railway instance:
    interface/modules/custom_modules/oe-module-clinical-copilot/ops/knowledge/deploy_railway.sh "\$CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL"

EOF
