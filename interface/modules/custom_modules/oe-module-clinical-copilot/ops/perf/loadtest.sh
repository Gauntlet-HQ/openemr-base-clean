#!/bin/sh
# Dependency-free concurrent load test (curl + xargs + sort + awk only -- no ab,
# no k6, no installs), so it runs anywhere the app runs (the Railway container,
# a dev box, CI). Fires N requests at a fixed concurrency C against one URL and
# reports throughput and latency percentiles -- exactly the "10 and 50 user load
# test" numbers plus a single-user baseline.
#
# Usage:
#   loadtest.sh <url> [total_requests] [concurrency] [label]
#
# Examples (run inside `railway ssh`, hitting the app on localhost so the
# numbers are app latency, not internet round-trip):
#   sh loadtest.sh http://localhost/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php 2000 1  baseline
#   sh loadtest.sh http://localhost/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php 2000 10 load-10
#   sh loadtest.sh http://localhost/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php 2000 50 load-50
set -u

URL="${1:?usage: loadtest.sh <url> [total_requests] [concurrency] [label]}"
N="${2:-1000}"
C="${3:-10}"
LABEL="${4:-load}"

RESULTS="$(mktemp)"
trap 'rm -f "$RESULTS" "$RESULTS.times" "$RESULTS.sorted"' EXIT

echo "== load test [${LABEL}] =="
echo "url:         ${URL}"
echo "requests:    ${N}"
echo "concurrency: ${C}"

START="$(date +%s)"
# Each worker prints "<http_code> <time_total_seconds>". xargs -P C runs C in
# parallel; -I _ avoids substituting the seq value into the (fixed) command.
seq "${N}" | xargs -P "${C}" -I _ \
    curl -s -o /dev/null -w '%{http_code} %{time_total}\n' --max-time 60 "${URL}" \
    > "${RESULTS}"
END="$(date +%s)"

ELAPSED=$((END - START))
[ "${ELAPSED}" -lt 1 ] && ELAPSED=1

# Split codes/times; count non-2xx/3xx as errors.
OK="$(awk '$1 ~ /^[23]/ {c++} END {print c+0}' "${RESULTS}")"
ERR=$((N - OK))
awk '{print $2}' "${RESULTS}" | sort -n > "${RESULTS}.sorted"

pctile() { # pctile <p> -> value in ms
    awk -v p="$1" -v n="${N}" '
        BEGIN { i = int((p/100)*n); if (i < 1) i = 1 }
        NR == i { printf "%.1f", $1 * 1000; exit }
    ' "${RESULTS}.sorted"
}
avg_ms="$(awk '{s+=$1; c++} END {if (c) printf "%.1f", (s/c)*1000; else print 0}' "${RESULTS}.sorted")"
throughput="$(awk -v n="${N}" -v e="${ELAPSED}" 'BEGIN {printf "%.1f", n/e}')"

echo "--"
echo "duration_s:      ${ELAPSED}"
echo "throughput_rps:  ${throughput}"
echo "requests_ok:     ${OK}"
echo "requests_err:    ${ERR}"
echo "latency_ms_avg:  ${avg_ms}"
echo "latency_ms_p50:  $(pctile 50)"
echo "latency_ms_p95:  $(pctile 95)"
echo "latency_ms_p99:  $(pctile 99)"
echo "latency_ms_max:  $(pctile 100)"
echo "== end [${LABEL}] =="
