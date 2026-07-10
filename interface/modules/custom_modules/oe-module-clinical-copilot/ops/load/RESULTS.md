# Clinical Co-Pilot — Baseline + Load Results (R8, R9)

**Status: harness + runbook complete; numbers not yet captured.** The k6
scripts (`k6/`) and the baseline harness (`baseline/capture-baseline.sh`) are
finished and verified. What remains is a single ~20-minute capture run
against a reachable, seeded stack — the exact commands are the runbook
below. Fill every `TBD` from that run, then commit this file; it is the
tracked baseline future changes get diffed against (ARCHITECTURE.md §3.6).

> **Why the numbers are still blank.** These must be captured from a host
> that can reach the target stack over the network. The CI/agent sandbox
> this module was built in cannot reach the live Railway deployment
> (`abundant-art-production-d560.up.railway.app`) — the environment's
> egress proxy denies that host by network policy (`403 CONNECT`,
> `connect_rejected`), and the sandbox has no Docker daemon to stand up a
> local stack either. A co-located load generator inside the app container
> would also produce misleading numbers (generator and system-under-test
> competing for the same CPU). So this capture is a deliberate
> real-hardware step, run from your laptop or a CI runner with network
> access to the deployment — not something to fake from the build box.

---

## Runbook — how to capture these numbers (~20 min)

### 0. Prereqs (once)

- A host that can reach the target `BASE_URL` (your laptop → the Railway
  URL, or `https://localhost:9300` for a local dev stack).
- **k6** installed:
  ```bash
  # macOS
  brew install k6
  # Debian/Ubuntu
  sudo gpg -k && sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
    --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
  echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" \
    | sudo tee /etc/apt/sources.list.d/k6.list && sudo apt-get update && sudo apt-get install k6
  # or Docker, no install: `docker run --rm -i grafana/k6 run - <script.js` (mount the dir)
  ```
- **Seeded synthetic patients on the target stack.** The scripts default to
  `PIDS=1,2,3,4` (the U2 seed's CCP-001..CCP-004) and every VU scrapes a
  CSRF token off `doc.php?pid=<pid>`, so each pid needs a **computed
  synthesis doc**. Seed + warm against the target stack first:
  ```bash
  # local dev stack: one command seeds + installs + warms
  interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/setup.sh
  # Railway (or any remote): run the seeder in the app container, then let the
  # worker warm the docs (or hit each doc.php once to force a cold compute):
  #   php interface/modules/custom_modules/oe-module-clinical-copilot/tests/Seed/SeedClinicalCopilot.php
  ```
  Synthetic data only (OPEN-1) — never point this at real PHI.

### 1. Environment to record (fill the table below)

Capture the target's machine type / vCPU / RAM, PHP-FPM `pm.max_children`,
MySQL `max_connections`, and **whether an LLM is configured** — this last
one decides the meaning of every chat number:

```bash
# LLM configured? (redacted, unauthenticated) — look at the "llm" field.
curl -s "$BASE_URL/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php"
#   "llm":"ok"          -> real Vertex/Gemini latency AND real token spend on chat turns
#   "llm":"unreachable" -> chat runs the facts-only degraded path (no spend; NOT comparable to a warm-LLM run)
```

> ⚠️ **Cost warning for the chat load test.** If `llm` is `ok`, the 50-VU
> chat run submits thousands of real LLM turns and **will incur Gemini
> token spend** (rough order: turns/run × ~1 generation each — estimate
> against `ops/cost-analysis.md` before running). The per-site daily/hourly
> spend caps (§3.7) will trip the circuit breaker mid-run if exceeded —
> which is itself a valid data point (record it), but decide the scope
> deliberately. The **doc-read** load test never calls the LLM (I2 warm
> read) and is always safe to run.

### 2. Baseline (single request, unloaded — run FIRST, against an idle stack)

```bash
export BASE_URL="https://abundant-art-production-d560.up.railway.app"   # or https://localhost:9300
export PID=1
# CONTAINER=<app container name> adds docker-stats CPU/mem rows; omit to skip
CONTAINER="" ./baseline/capture-baseline.sh
# -> appends to baseline/baseline-results.ndjson and prints the numbers;
#    copy them into the "Baseline" table below.
```

### 3. Load — doc read (safe, no LLM), then chat (see cost warning)

```bash
cd k6
# --- synthesis read (warm, no LLM) — required both levels ---
k6 run --vus 10 --duration 3m -e BASE_URL="$BASE_URL" doc-read-load.js  | tee ../doc-10vu.txt
k6 run --vus 50 --duration 3m -e BASE_URL="$BASE_URL" doc-read-load.js  | tee ../doc-50vu.txt
# --- chat turn (LLM path; heed the cost warning above) — required both levels ---
k6 run --vus 10 --duration 5m -e BASE_URL="$BASE_URL" chat-turn-load.js | tee ../chat-10vu.txt
k6 run --vus 50 --duration 5m -e BASE_URL="$BASE_URL" chat-turn-load.js | tee ../chat-50vu.txt
```

Env overrides (all optional): `SITE`, `USERNAME`, `PASSWORD`, `PIDS`
(comma-separated seeded pids), `MAX_TURNS_PER_VU` (chat only). Read the
metric values off k6's end-of-run summary: `doc_read_duration` /
`chat_turn_duration` (p50/p95/p99), `http_req_failed` (error rate),
`iterations`/s (throughput), and the `chat_turns_degraded` /
`chat_turns_frozen` counters.

### 4. Saturation (k6 sees client latency only — capture the server side too)

During each run, in a second terminal on the target host:

```bash
docker stats <app-container> <mysql-container>          # CPU% + mem peak
# MySQL peak connections during the run:
mysql -e "SHOW STATUS LIKE 'Threads_connected';"        # sample repeatedly; record the peak
# PHP-FPM active workers vs pm.max_children (if the FPM status page is enabled)
```

On Railway, use the service's **Metrics** tab (CPU / memory / network) for
the same window instead of `docker stats`, and note the plan's vCPU/RAM as
the "machine type."

---

## Scaling stance (T20 — vertical-first, read this before the numbers)

OpenEMR is a session-holding monolith and this module is in-process by
design (T2): every open chat turn holds a PHP-FPM worker for the turn's
whole duration (up to the 30s deadline), so concurrency is bought with
**worker count**, which is bought with **cores and RAM on a bigger machine
type** — not with horizontal app replicas (which would immediately hit
shared-session, worker-singleton, and sticky-connection problems this
monolith isn't built for). The numbers below exist to answer one question:
**how many workers/how much RAM does this deployment need for N concurrent
users**, not "how do we add more app servers." When a deployment outgrows
the biggest sensible single machine (multi-clinic scale), the honest next
moves are read replicas for fact extraction and pulling the chat loop out
of process — a deliberate re-opening of T2 (recorded as T20), not something
this harness is trying to defer or hide.

## Environment captured against

| Field | Value |
|---|---|
| Date | TBD |
| Git commit | TBD |
| Host / machine type | TBD (e.g. GCE `n2-standard-4`, Railway service plan vCPU/RAM, or dev laptop — state it plainly; these numbers are only meaningful relative to their hardware) |
| PHP-FPM `pm.max_children` | TBD |
| MySQL max_connections | TBD |
| LLM credentials configured? | TBD (from `/ready` — `llm:ok` = real Vertex latency + spend; `llm:unreachable` = degraded/facts-only path — state which was measured, they are NOT comparable) |
| Seed state | TBD (U2 seed run? how many patients/facts? which PIDS?) |

## Baseline (single-request, unloaded — `capture-baseline.sh`)

| Scenario | time_connect (s) | TTFB (s) | total (s) | HTTP | Notes |
|---|---|---|---|---|---|
| Synthesis read, WARM | TBD | TBD | TBD | TBD | doc row already exists for current digest; no LLM call expected |
| Synthesis read, COLD (Regenerate) | TBD | TBD | TBD | TBD | forces reduce+verify; expect this ≈ one LLM round trip + verify |
| Chat turn, 0 tool calls | TBD | TBD | TBD | TBD | answerable from preloaded seed facts alone |
| Chat turn, 1 tool call | TBD | TBD | TBD | TBD | one drill-down beyond the preload window |
| Chat turn, 3 tool calls | TBD | TBD | TBD | TBD | chaining question across labs+meds+vitals |

CPU/mem during baseline capture (idle stack, single in-flight request):

| Container | CPU % | Mem (used / limit) |
|---|---|---|
| openemr (apache+php) | TBD | TBD |
| mysql | TBD | TBD |

## Load: 10 concurrent users (`doc-read-load.js`, `chat-turn-load.js`)

Command run: `k6 run --vus 10 --duration 3m ...` (doc), `--duration 5m ...` (chat)

### Synthesis read (`doc_read_duration`)

| Metric | Value |
|---|---|
| p50 | TBD |
| p95 | TBD |
| p99 | TBD |
| error rate | TBD |
| requests/s | TBD |

### Chat turn (`chat_turn_duration`)

| Metric | Value |
|---|---|
| p50 | TBD |
| p95 | TBD |
| p99 | TBD |
| error rate | TBD |
| `chat_turns_degraded` count | TBD |
| `chat_turns_frozen` count | TBD (should be 0 under normal load — a nonzero count here means something in the run is tripping V3, worth investigating before reading the rest of the numbers) |

### Saturation @ 10 VUs

| Signal | Value |
|---|---|
| PHP-FPM active workers (peak) | TBD |
| PHP-FPM `pm.max_children` headroom | TBD (peak / max_children) |
| MySQL `Threads_connected` (peak) | TBD |
| openemr container CPU % (peak) | TBD |
| openemr container mem (peak) | TBD |

## Load: 50 concurrent users

Command run: `k6 run --vus 50 --duration 3m ...` (doc), `--duration 5m ...` (chat)

### Synthesis read (`doc_read_duration`)

| Metric | Value |
|---|---|
| p50 | TBD |
| p95 | TBD |
| p99 | TBD |
| error rate | TBD |
| requests/s | TBD |

### Chat turn (`chat_turn_duration`)

| Metric | Value |
|---|---|
| p50 | TBD |
| p95 | TBD |
| p99 | TBD |
| error rate | TBD |
| `chat_turns_degraded` count | TBD |
| `chat_turns_frozen` count | TBD |

### Saturation @ 50 VUs

| Signal | Value |
|---|---|
| PHP-FPM active workers (peak) | TBD |
| PHP-FPM `pm.max_children` headroom | TBD |
| MySQL `Threads_connected` (peak) | TBD |
| openemr container CPU % (peak) | TBD |
| openemr container mem (peak) | TBD |
| Did the run hit the circuit breaker (§3.7)? | TBD |

## Reading these numbers (fill in once captured)

- Does chat p95 at 50 VUs blow the 30s hard turn deadline (§1.3)? If so,
  that is the sizing signal T20 describes — the fix is more PHP-FPM
  workers / a bigger machine type, not application changes.
- Compare 10 VU vs 50 VU PHP-FPM worker saturation: is it linear (healthy —
  purely a worker-count ceiling) or superlinear (a lock contention / DB
  connection-pool problem worth its own investigation before it's assumed
  to be "just" a sizing question)?
- Does `chat_turns_degraded` rise disproportionately at 50 VUs vs 10? That
  would point at LLM-side rate limiting/timeouts under concurrency, not a
  local resource ceiling — a different remediation (raise Vertex quota /
  widen the per-tick budget in §3.7) than adding local workers.
