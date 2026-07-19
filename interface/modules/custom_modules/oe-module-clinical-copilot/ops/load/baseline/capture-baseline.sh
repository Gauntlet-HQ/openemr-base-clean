#!/usr/bin/env bash
#
# Clinical Co-Pilot -- baseline profile capture (ARCHITECTURE.md §3.6, R8).
#
# Captures CPU/memory/latency of the deployed dev stack for:
#   - synthesis read, COLD  (a digest with no cached doc row yet -- worker
#     never warmed it, or the fact digest just drifted)
#   - synthesis read, WARM  (a doc row already exists for the current digest
#     -- the read-path serves it with NO LLM call, I2/T7)
#   - chat turn with 0 tool calls (answerable entirely from the preloaded
#     seed facts -- e.g. "what is her most recent A1c?")
#   - chat turn with 1 tool call (needs one drill-down beyond the preload
#     window -- e.g. "what were her labs from a year ago?")
#   - chat turn with 3 tool calls (a chaining question spanning multiple
#     capabilities -- e.g. "how have her labs, vitals, and medications all
#     changed since her dose increase?", matching U11's med-date ->
#     vitals-window chaining fixture)
#
# This is a curl+docker-stats harness, not a load generator -- see
# ../k6/*.js for concurrent load at 10/50 VUs (R8-R9). Run this FIRST,
# once, against an idle stack (no concurrent load) to get an honest
# single-request baseline before the k6 runs saturate it.
#
# Usage (from inside the dev stack container, or any host with curl +
# network access to it):
#   BASE_URL=https://localhost:9300 PID=1 ./capture-baseline.sh
#
# Prereqs:
#   - the dev stack is up (`openemr-cmd worktree up` / `openemr-cmd up`)
#   - the U2 seed has been run so PID has a synthesis doc (or this script's
#     "cold" step will also be its first-ever compute, which is fine --
#     just note that in RESULTS.md, since a truly cold DIGEST-DRIFT read is
#     a different code path than a never-computed one, see ARCHITECTURE_COMPLETE.md
#     compute model, I1/I2)
#   - `jq` for JSON field extraction and `docker` for container stats (both
#     optional -- the script degrades to raw curl timing if either is
#     missing, and says so)
#
# Output: appends a timestamped block to ./baseline-results.ndjson (one
# JSON object per capture) and prints a human-readable summary. Copy the
# printed numbers into ../RESULTS.md's baseline table.

set -euo pipefail

BASE_URL="${BASE_URL:-https://localhost:9300}"
SITE="${SITE:-default}"
USERNAME="${USERNAME:-admin}"
PASSWORD="${PASSWORD:-pass}"
PID="${PID:-1}"
CONTAINER="${CONTAINER:-}"   # docker container name/id for the openemr app server; leave empty to skip docker stats
OUT="${OUT:-$(dirname "$0")/baseline-results.ndjson}"
COOKIE_JAR="$(mktemp)"
trap 'rm -f "$COOKIE_JAR"' EXIT

log() { echo "[baseline] $*" >&2; }

require_curl() {
  command -v curl >/dev/null 2>&1 || { echo "curl is required" >&2; exit 1; }
}

have_jq() { command -v jq >/dev/null 2>&1; }
have_docker() { [ -n "$CONTAINER" ] && command -v docker >/dev/null 2>&1; }

login() {
  log "logging in as ${USERNAME}@${SITE}"
  curl -sk -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "${BASE_URL}/interface/login/login.php?site=${SITE}" -o /dev/null

  curl -sk -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -d "new_login_session_management=1" \
    -d "languageChoice=1" \
    -d "authUser=${USERNAME}" \
    -d "clearPass=${PASSWORD}" \
    -d "facility=user_default" \
    "${BASE_URL}/interface/main/main_screen.php?auth=login&site=${SITE}" \
    -o /dev/null -L
}

fetch_csrf() {
  local body token
  body="$(curl -sk -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "${BASE_URL}/interface/modules/custom_modules/oe-module-clinical-copilot/public/doc.php?pid=${PID}")"
  token="$(echo "$body" | grep -oE 'id="ccpChatCsrf"[^>]*value="[^"]*"' | grep -oE 'value="[^"]*"' | head -1 | sed -E 's/value="([^"]*)"/\1/')"
  if [ -z "$token" ]; then
    log "ERROR: could not scrape CSRF token from doc.php -- is PID=${PID} a seeded patient with a computed doc? (see tests/Seed/SeedClinicalCopilot.php)"
    exit 1
  fi
  echo "$token"
}

# Times a single GET/POST with curl's own timing fields (connect, TTFB,
# total) -- no external dependency beyond curl itself.
time_request() {
  local method="$1" url="$2" data="${3:-}"
  local args=(-sk -o /dev/null -c "$COOKIE_JAR" -b "$COOKIE_JAR" -w '%{time_connect},%{time_starttransfer},%{time_total},%{http_code}')
  if [ "$method" = "POST" ]; then
    curl "${args[@]}" -X POST -d "$data" "$url"
  else
    curl "${args[@]}" "$url"
  fi
}

docker_stats_snapshot() {
  if have_docker; then
    docker stats --no-stream --format '{{json .}}' "$CONTAINER" 2>/dev/null || echo '{}'
  else
    echo '{}'
  fi
}

emit() {
  local label="$1" timing="$2" stats="$3"
  IFS=',' read -r connect ttfb total http_code <<<"$timing"
  local line
  line=$(printf '{"label":"%s","captured_at":"%s","time_connect_s":%s,"time_ttfb_s":%s,"time_total_s":%s,"http_code":%s,"docker_stats":%s}' \
    "$label" "$(date -u +%FT%TZ)" "$connect" "$ttfb" "$total" "$http_code" "$stats")
  echo "$line" | tee -a "$OUT"
}

require_curl
have_jq || log "jq not found -- JSON fields printed raw, no pretty-print"
have_docker || log "CONTAINER not set or docker not found -- skipping container CPU/mem capture (fill RESULTS.md's CPU/mem columns by hand from 'docker stats' during a k6 run instead)"

login
CSRF="$(fetch_csrf)"
log "csrf token acquired"

DOC_URL="${BASE_URL}/interface/modules/custom_modules/oe-module-clinical-copilot/public/doc.php?pid=${PID}"
CHAT_URL="${BASE_URL}/interface/modules/custom_modules/oe-module-clinical-copilot/public/chat.php"

# --- Synthesis read, WARM (assumes a doc already exists for this pid/digest) ---
log "timing warm synthesis read"
emit "synthesis_read_warm" "$(time_request GET "$DOC_URL")" "$(docker_stats_snapshot)"

# --- Synthesis read, COLD (force a fresh attempt, then immediately re-read
#     it warm to get a clean before/after pair; the Regenerate POST itself
#     IS the cold-path timing since it forces reduce+verify to run) ---
log "timing cold synthesis read (Regenerate)"
emit "synthesis_read_cold_regenerate" \
  "$(time_request POST "$DOC_URL" "action=regenerate&csrf_token_form=${CSRF}")" \
  "$(docker_stats_snapshot)"

# --- Chat session + turns with 0 / 1 / 3 tool calls ---
log "starting chat session"
START_BODY="$(curl -sk -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -d "action=start" -d "pid=${PID}" -d "csrf_token_form=${CSRF}" "$CHAT_URL")"
if have_jq; then
  SESSION_ID="$(echo "$START_BODY" | jq -r '.session_id')"
else
  SESSION_ID="$(echo "$START_BODY" | grep -oE '"session_id":[0-9]+' | head -1 | grep -oE '[0-9]+')"
fi
if [ -z "$SESSION_ID" ] || [ "$SESSION_ID" = "null" ]; then
  log "ERROR: could not start a chat session -- response was: $START_BODY"
  exit 1
fi
log "chat session_id=${SESSION_ID}"

turn() {
  local label="$1" message="$2" timing
  log "timing chat turn: ${label}"
  # Uses curl's own --data-urlencode for the free-text message (avoids a
  # python/perl dependency for URL-encoding); everything else about the
  # request matches time_request's POST path (same cookie jar, same -w
  # timing format), so results are directly comparable to the other rows.
  timing="$(curl -sk -o /dev/null -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -w '%{time_connect},%{time_starttransfer},%{time_total},%{http_code}' \
    -X POST \
    -d "action=turn" -d "session_id=${SESSION_ID}" -d "csrf_token_form=${CSRF}" \
    --data-urlencode "message=${message}" \
    "$CHAT_URL")"
  emit "chat_turn_${label}" "$timing" "$(docker_stats_snapshot)"
}

# 0 tool calls: answerable entirely from the pre-loaded seed fact set.
turn "0tools" "What is her most recent A1c?"
# 1 tool call: one drill-down beyond the preloaded window.
turn "1tool" "What were her labs from a year before that?"
# 3 tool calls: a chaining question spanning labs + meds + vitals (matches
# U11's chaining known-answer fixture: med-date -> vitals-window).
turn "3tools" "Since her last medication dose change, how have her labs, vitals, and weight all trended?"

log "done -- results appended to ${OUT}"
log "copy the printed numbers into ../RESULTS.md's baseline table"
