#!/usr/bin/env bash
#
# Clinical Co-Pilot -- ONE command: the in-process load test at 50 concurrent
# workers (plus a 1-worker baseline and 10-worker step), across a few
# representative hot paths. No pcntl, no DB, no network -- runs anywhere a CLI
# php can spawn subprocesses (incl. the OpenEMR/Railway container).
#
# Usage (from anywhere; e.g. after `railway ssh` into the container):
#   interface/modules/custom_modules/oe-module-clinical-copilot/ops/load/bench/run-load-test.sh
#
# Env overrides (optional):
#   DURATION=15     seconds per (workload, concurrency) cell (default 15)
#   LEVELS=1,10,50  concurrency levels to run (default 1,10,50)
#   WORKLOADS="..." space-separated workload names (default: a representative 4)
#
# Prints a human summary and appends machine-readable JSON to
# results/load-test-latest.ndjson.

set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DURATION="${DURATION:-15}"
LEVELS="${LEVELS:-1,10,50}"
WORKLOADS="${WORKLOADS:-guideline_retrieval_sparse extraction_client_full verify_chat prompt_assemble_reduce}"
OUT="${DIR}/results/load-test-latest.ndjson"

if ! command -v php >/dev/null 2>&1; then
  echo "run-load-test: php not found on PATH" >&2
  exit 2
fi
mkdir -p "${DIR}/results"

echo "Clinical Co-Pilot -- in-process load test"
echo "  workloads : ${WORKLOADS}"
echo "  levels    : ${LEVELS}   duration: ${DURATION}s/cell"
echo "  (module compute only; the full HTTP 50-user test is k6 -- see ../RESULTS.md Part B)"
echo

# shellcheck disable=SC2086  # word-splitting WORKLOADS/LEVELS is intended
php "${DIR}/bench.php" ${WORKLOADS} --concurrency="${LEVELS}" --duration="${DURATION}" --out="${OUT}"

echo
echo "Machine-readable results appended to: ${OUT}"
