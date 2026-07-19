#!/bin/sh
# One-shot week-1 performance capture: baseline (1 user) + 10-user + 50-user load
# against the readiness endpoint, plus a CPU/memory snapshot, written to a single
# timestamped results file you can hand in.
#
# Run it INSIDE the app container (`railway ssh`) so latency is app time, not
# internet round-trip, and the CPU/memory readings are the app container's own:
#   sh ops/perf/run-week1.sh
#
# Override the target (e.g. to load-test a different endpoint) or request volume:
#   URL=http://localhost/.../public/health.php REQUESTS=3000 sh ops/perf/run-week1.sh
#
# For the authoritative CPU/Memory graph, ALSO screenshot the Railway service's
# Metrics tab across the run window -- /proc readings below are a container-local
# supplement, not a replacement for the platform graph.
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
BASE="/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-clinical-copilot/public"
URL="${URL:-http://localhost/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php}"
REQUESTS="${REQUESTS:-2000}"
OUT="${OUT:-/tmp/copilot-week1-perf-$(date +%Y%m%d-%H%M%S).md}"

log() { echo "$@"; echo "$@" >> "${OUT}"; }

: > "${OUT}"
log "# Clinical Co-Pilot -- Week 1 performance capture"
log ""
log "- captured_at (UTC): $(date -u '+%Y-%m-%d %H:%M:%S')"
log "- target url: ${URL}"
log "- requests per level: ${REQUESTS}"
log ""

log "## Resource snapshot (container-local)"
log '```'
# CPU: load averages (1/5/15 min) + core count.
if [ -r /proc/loadavg ]; then
    log "loadavg (1/5/15): $(cut -d' ' -f1-3 /proc/loadavg)"
fi
log "cpu_cores: $(nproc 2>/dev/null || grep -c ^processor /proc/cpuinfo 2>/dev/null || echo '?')"
# Memory: prefer the cgroup (container) figures; fall back to /proc/meminfo.
if [ -r /sys/fs/cgroup/memory.current ]; then
    used="$(cat /sys/fs/cgroup/memory.current)"
    max="$(cat /sys/fs/cgroup/memory.max 2>/dev/null || echo max)"
    log "cgroup_mem_used_bytes: ${used}"
    log "cgroup_mem_limit_bytes: ${max}"
elif [ -r /sys/fs/cgroup/memory/memory.usage_in_bytes ]; then
    log "cgroup_mem_used_bytes: $(cat /sys/fs/cgroup/memory/memory.usage_in_bytes)"
    log "cgroup_mem_limit_bytes: $(cat /sys/fs/cgroup/memory/memory.limit_in_bytes 2>/dev/null || echo '?')"
fi
if [ -r /proc/meminfo ]; then
    log "meminfo_total: $(awk '/MemTotal/{print $2" "$3}' /proc/meminfo)"
    log "meminfo_available: $(awk '/MemAvailable/{print $2" "$3}' /proc/meminfo)"
fi
log '```'
log ""

run_level() { # run_level <concurrency> <label>
    log "## ${2} (concurrency ${1})"
    log '```'
    sh "${HERE}/loadtest.sh" "${URL}" "${REQUESTS}" "${1}" "${2}" | tee -a "${OUT}"
    log '```'
    log ""
    # Brief pause so each level starts from a settled server.
    sleep 5
}

run_level 1  "Baseline (single user)"
run_level 10 "Load test -- 10 concurrent users"
run_level 50 "Load test -- 50 concurrent users"

log "## Notes"
log "- Latency is server-side (run from inside the container against localhost)."
log "- CPU/memory above is container-local; pair it with the Railway Metrics tab"
log "  graph over this run window for the platform-authoritative figure."
log "- Readiness endpoint is unauthenticated by design, so it exercises the full"
log "  request path (bootstrap + DB round-trip + writable probe) without a login."
log ""

echo ""
echo "Results written to: ${OUT}"
echo "Copy it out with:   cat ${OUT}"
