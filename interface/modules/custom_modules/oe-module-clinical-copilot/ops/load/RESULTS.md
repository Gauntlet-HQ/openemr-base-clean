# Clinical Co-Pilot — Baseline + Load Results (R8, R9)

There are **two layers** of performance measurement here, and they answer
different questions:

- **Part A — in-process hot-path baseline + load (CAPTURED, below).** Real
  CPU / memory / latency / throughput of the module's own compute
  (retrieval, strict-schema extraction, V1–V6 verification, canonicalization,
  prompt assembly), at concurrency **1 (baseline), 10, and 50**, measured with
  `ops/load/bench/` — no DB, no LLM, no web stack, so it runs anywhere PHP
  does and the numbers below were captured directly in the build environment.
  This is what isolates *the module's* CPU cost and how it scales with worker
  count on a given machine.
- **Part B — full-stack HTTP baseline + load (dev-stack runbook).** The
  end-to-end round trip (Apache + PHP-FPM + MySQL + session/ACL + the real
  LLM), measured with `baseline/capture-baseline.sh` + `k6/*.js`. This
  requires a *reachable, seeded* stack; the runbook is below and the tables
  are filled from that run. It answers "how many PHP-FPM workers / how much
  RAM does a deployment need for N concurrent physicians."

They are complementary: Part A says the module's logic is sub-millisecond and
CPU-bound (so concurrency is a worker-count/core question, exactly the T20
vertical-first stance); Part B puts that under a real web stack and the real
LLM latency that dominates a chat turn.

---

## Part A — in-process hot-path results (CAPTURED)

Re-capture at any time with a single command (≈4 min on a 4-core box):

```bash
php ops/load/bench/capture.php --duration=8      # baseline + 10 + 50, every workload
php ops/load/bench/bench.php --list              # the individual workloads
php ops/load/bench/bench.php verify_chat --concurrency=1,10,50 --duration=10   # one workload
```

Latency percentiles are computed with the module's **own** `RateMath`
(`src/Observability/Metrics/RateMath.php`) — the same function the
observability dashboard uses — so the harness and the production metric agree
by construction. Full method map in `ops/load/bench/README.md`.

### In-process hot-path results (module compute only — no web stack / DB / LLM)

_Captured 2026-07-13T22:03:58+00:00 · commit `899c583` · host x86_64 · PHP 8.4.19 · 4 cores · 16075 MB RAM · 8s/cell · warmup 300/worker._

| Workload | Conc | Throughput (ops/s) | p50 (ms) | p95 (ms) | p99 (ms) | CPU (% all cores) | RSS/worker (MB) | Aggregate RSS (MB) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `guideline_retrieval_sparse` | 1 | 4,193.6 | 0.226 | 0.284 | 0.351 | 25% | 14.980 | 14.980 |
| `guideline_retrieval_sparse` | 10 | 16,716.0 | 0.221 | 4.258 | 8.279 | 99% | 14.760 | 147.290 |
| `guideline_retrieval_sparse` | 50 | 15,406.5 | 0.221 | 31.351 | 52.283 | 98% | 14.860 | 742.490 |
| `guideline_retrieval_hybrid` | 1 | 4,292.7 | 0.224 | 0.263 | 0.295 | 25% | 15.250 | 15.250 |
| `guideline_retrieval_hybrid` | 10 | 16,741.4 | 0.223 | 4.257 | 8.279 | 99% | 15.090 | 150.840 |
| `guideline_retrieval_hybrid` | 50 | 14,831.1 | 0.225 | 36.294 | 52.301 | 97% | 14.880 | 743.550 |
| `extraction_validate_parse` | 1 | 95,221.8 | 0.014 | 0.018 | 0.029 | 24% | 29.430 | 29.430 |
| `extraction_validate_parse` | 10 | 368,870.1 | 0.014 | 0.019 | 0.031 | 96% | 21.510 | 213.270 |
| `extraction_validate_parse` | 50 | 358,073.0 | 0.014 | 0.020 | 0.032 | 95% | 14.750 | 679.170 |
| `extraction_client_full` | 1 | 42,965.0 | 0.021 | 0.032 | 0.047 | 25% | 21.330 | 21.330 |
| `extraction_client_full` | 10 | 166,711.6 | 0.021 | 0.033 | 0.050 | 98% | 15.840 | 156.090 |
| `extraction_client_full` | 50 | 159,070.6 | 0.021 | 0.033 | 0.055 | 97% | 13.000 | 645.280 |
| `verify_chat` | 1 | 58,872.7 | 0.015 | 0.023 | 0.036 | 25% | 23.510 | 23.510 |
| `verify_chat` | 10 | 225,814.4 | 0.016 | 0.024 | 0.039 | 97% | 19.060 | 186.580 |
| `verify_chat` | 50 | 227,453.0 | 0.015 | 0.023 | 0.037 | 97% | 15.410 | 765.610 |
| `verify_synthesis` | 1 | 56,097.9 | 0.016 | 0.024 | 0.038 | 25% | 23.480 | 23.480 |
| `verify_synthesis` | 10 | 218,094.3 | 0.016 | 0.025 | 0.039 | 96% | 18.930 | 184.210 |
| `verify_synthesis` | 50 | 209,816.5 | 0.016 | 0.025 | 0.041 | 95% | 15.460 | 765.780 |
| `canonical_serialize_digest` | 1 | 10,543.6 | 0.087 | 0.134 | 0.160 | 25% | 15.490 | 15.490 |
| `canonical_serialize_digest` | 10 | 41,667.7 | 0.087 | 0.129 | 8.124 | 97% | 13.690 | 136.250 |
| `canonical_serialize_digest` | 50 | 39,995.1 | 0.086 | 0.121 | 48.137 | 96% | 13.360 | 666.710 |
| `prompt_assemble_reduce` | 1 | 38,643.0 | 0.024 | 0.037 | 0.052 | 25% | 21.950 | 21.950 |
| `prompt_assemble_reduce` | 10 | 155,343.3 | 0.023 | 0.035 | 0.052 | 98% | 16.340 | 161.770 |
| `prompt_assemble_reduce` | 50 | 152,254.9 | 0.023 | 0.035 | 0.051 | 97% | 13.710 | 682.120 |

_(Machine-readable copy: `ops/load/bench/results/inprocess-latest.json` and the
append-only `inprocess-results.ndjson`.)_

### Reading Part A

- **Baseline (conc=1).** Every hot path is **sub-millisecond at p50** and CPU
  utilization sits at ~25% (one of four cores). Extraction validate/parse and
  verification are the cheapest (~0.014–0.016 ms); guideline retrieval and
  canonicalization are the heaviest (TF-IDF over the corpus; per-fact sort +
  SHA-256), and even those are ~0.09–0.23 ms. The module's own logic is not the
  bottleneck — the LLM call (Part B) is.
- **Scaling 1 → 10 (a 4-core box).** Throughput rises ~3.6–4× and CPU climbs
  to ~96–99% of all four cores: healthy **core saturation**, i.e. throughput is
  bounded by cores, not by lock contention. p50 stays flat; only the tail (p95/
  p99) starts to show scheduler queueing once workers outnumber cores.
- **Scaling 10 → 50 (12.5× cores).** Throughput **plateaus** (the cores are
  already full) and the tail latency of the CPU-heavier paths inflates sharply
  (sparse retrieval p99 0.35 ms → 52 ms; canonical p99 0.16 ms → 48 ms) — the
  textbook oversubscription curve. The cheap paths (verify, extraction) barely
  move because each op is so short it clears before it can queue.
- **Memory — the real sizing signal.** Per-worker RSS high-water is a steady
  **~13–29 MB**; aggregate RSS grows **linearly** with worker count (~15 MB/worker
  → 50 workers ≈ **740 MB**). So a deployment sizing decision is essentially
  "cores for throughput, ~15–30 MB RAM per concurrent worker" — which is exactly
  the vertical-first (bigger machine type) stance in T20 below, now backed by a
  measured per-worker footprint rather than an assertion.

> **Why the numbers below (Part B) require a reachable stack.** These must be
> captured from a host
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

## Part B — full-stack HTTP baseline + load (dev-stack runbook)

Part A above measures the module's compute in isolation. Part B puts the same
code under a real Apache + PHP-FPM + MySQL stack and the real LLM, which is
where the chat-turn latency budget actually lives. It needs a reachable,
seeded target; the `TBD` cells are filled from that ~20-minute run.

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
