# Week-1 performance & observability runbook

Everything here is designed to be **run against the live deployment** and to
produce **recorded artifacts** you can hand in. Each section maps to a specific
gap. Run the shell/PHP tools from inside the app container (`railway ssh`), or
adapt the URLs to the public host.

Paths below assume the module lives at
`/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-clinical-copilot`
(call it `$MOD`). The public endpoints are under `$MOD/public/`.

---

## 1. Baseline performance (CPU, memory, latency, throughput)

```sh
cd $MOD
sh ops/perf/run-week1.sh
```

This runs a **single-user baseline** plus **10-user** and **50-user** load levels
against the readiness endpoint (unauthenticated, but exercises the full request
path: bootstrap + DB round-trip + writable probe), and snapshots the container's
CPU load and memory. It writes one markdown file:

```
Results written to: /tmp/copilot-week1-perf-<timestamp>.md
cat /tmp/copilot-week1-perf-<timestamp>.md   # copy this into your submission
```

Reported per level: **throughput (req/s)**, **latency avg / p50 / p95 / p99 / max
(ms)**, error count. The resource snapshot section has **loadavg**, **cpu_cores**,
and **container memory used / limit**.

> For the platform-authoritative CPU/Memory graph, also screenshot the Railway
> service **Metrics** tab across the run window and attach it — pair it with the
> `/proc` numbers in the file.

Tune volume or target:
```sh
REQUESTS=4000 URL=http://localhost/interface/modules/custom_modules/oe-module-clinical-copilot/public/health.php sh ops/perf/run-week1.sh
```

---

## 2. 10- and 50-user load test results  *(the 5 points)*

The `run-week1.sh` output above **is** the 10- and 50-user load test — the
`Load test -- 10 concurrent users` and `-- 50 concurrent users` blocks are the
recorded results. To run a single level on its own (e.g. to re-capture just the
50-user number):

```sh
sh ops/perf/loadtest.sh \
  http://localhost/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php \
  2000 50 load-50
```
Args: `<url> <total_requests> <concurrency> <label>`. No `ab`/`k6`/installs
needed — pure `curl` + `xargs`.

**To load-test the authenticated synthesis path** (the real LLM workload) instead
of the readiness probe, capture a logged-in session cookie from your browser
(DevTools → Application → Cookies → `OpenEMR` session cookie) and point curl at a
seeded patient's doc endpoint. Note this drives real Gemini calls (cost + rate
limits), so keep the request count low:
```sh
# one-shot latency of a warm vs cold synthesis, authenticated:
curl -s -o /dev/null -w 'status=%{http_code} time=%{time_total}s\n' \
  -b 'OpenEMR=<your-session-cookie>' \
  'http://localhost/interface/modules/custom_modules/oe-module-clinical-copilot/public/doc.php?pid=<PID>'
```

---

## 3. Readiness endpoint (and the "LLM down" case)

```sh
curl -s http://localhost/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php | tee /tmp/ready.json
echo "HTTP: $(curl -s -o /dev/null -w '%{http_code}' http://localhost/.../public/ready.php)"
```

The response now carries an explicit boolean:
```json
{"ready": true, "status": "ok", "db": "ok", "tables_writable": "ok",
 "llm": "ok", "worker_heartbeat": "ok", "breaker": "closed",
 "document_store": "ok", "knowledge": "offline-corpus", "reranker": "in-process"}
```

Key point for grading: **`ready` and the HTTP status reflect the *service*, not
the LLM.** If Gemini is down you get `"ready": true, "status": "degraded",
"llm": "unreachable"` at **HTTP 200** — the service is up and serving (reads work,
chat degrades to a facts browser); only the optional LLM dependency is degraded.
`/ready` returns HTTP 503 **only** when a hard dependency (DB or writable tables)
is actually down. So an LLM outage is not an app-readiness failure, and the
endpoint says so explicitly.

Liveness (checks nothing, never fails on a dependency):
```sh
curl -s http://localhost/interface/modules/custom_modules/oe-module-clinical-copilot/public/health.php
```

---

## 4. Dashboard & alerts — demonstrate live

Open the observability dashboard in the browser (admin-gated + audit-logged):
```
https://<host>/interface/modules/custom_modules/oe-module-clinical-copilot/public/dashboard.php
```
It renders `MetricsService::overview()` over a window (default 24h; add
`?window_hours=1` to tighten for the demo): request counts by kind/status,
latency, token/cost rollups, recent fired alerts, the circuit-breaker state (with
force-open / reset admin actions), and a per-correlation span waterfall.

To show an **alert firing live**, trip the circuit breaker from the dashboard
(the *Force open* button) or exercise a synthesis while the rate limiter is
constrained — `recentFiredAlerts()` will list it, and `AlertEvaluator` records it
each worker tick. Show the breaker flip on `/ready` too (`"breaker": "open"`).

---

## 5. Verification, observability metrics, and eval results

- **Verification (V1–V6):** the verifier gate can be re-enabled per environment:
  ```sh
  # set on the service, then regenerate a narrative to show the gate running:
  CLINICAL_COPILOT_VERIFY_ENFORCE=1
  ```
  Each served doc/turn stores its per-check verdict JSON (`verification_verdict`);
  the dashboard's span waterfall and the doc's own trace show V1–V6 pass/fail.

- **Observability metrics:** the dashboard (§4) is the live view;
  `MetricsService::overview()` is the same data as JSON if you want to capture it
  programmatically.

- **Eval results:** the acceptance evals live in `tests/` and produce recorded
  pass/fail output. Run the suite in-container and capture it:
  ```sh
  openemr-cmd unit-test        # isolated + unit evals (fast, no DB)
  openemr-cmd services-test    # DB-backed acceptance evals (E1-E6 read path, verifier, worker, ready/health)
  ```
  Redirect to a file to hand in the eval log:
  `openemr-cmd services-test > /tmp/copilot-evals.txt 2>&1`

---

## 6. Cost analysis tied to production measurements

```sh
php ops/perf/cost-report.php 7    # trailing 7-day window (default 7)
```

Aggregates the **recorded** `cost_usd`, token counts (`tokens_in`/`tokens_out`
from live `usageMetadata`), and LLM latency the module already writes to
`mod_copilot_doc` (narratives) and `mod_copilot_chat_turn` (chat) on every real
call. Output: runs, total & per-run cost, tokens, latency avg/p50/max, plus daily
and projected 30-day cost. This is measured production cost, not an estimate.

> Generate real traffic first (open several seeded patients, run a few chat
> turns) so the tables have rows, then run the report and attach its output.

---

### Suggested capture order for the redeploy demo

1. `curl .../ready.php` → show `ready:true` (and explain the degraded-LLM case).
2. `sh ops/perf/run-week1.sh` → baseline + 10 + 50 user results file.
3. Open `dashboard.php` → metrics, trip the breaker → alert + `/ready` breaker flip.
4. Regenerate a narrative with `CLINICAL_COPILOT_VERIFY_ENFORCE=1` → V1–V6 verdicts.
5. `openemr-cmd services-test > /tmp/copilot-evals.txt` → eval results.
6. `php ops/perf/cost-report.php` → production cost analysis.
