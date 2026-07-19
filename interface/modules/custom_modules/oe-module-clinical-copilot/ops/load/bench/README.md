# In-process hot-path benchmark (`ops/load/bench/`)

Real CPU / memory / latency / throughput of the Clinical Co-Pilot module's own
compute, at concurrency **1 (baseline), 10, and 50** — with **no database, no
LLM, and no OpenEMR core bootstrap**, so it runs in any PHP 8.2+ environment
(including the CI/agent sandbox that cannot reach the deployed stack).

This is the **in-process** complement to the full-stack HTTP measurement in
`../RESULTS.md` (Part B) — `../baseline/capture-baseline.sh` (curl timings) and
`../k6/*.js` (concurrent HTTP load). This harness isolates the cost of the
module's *logic*; the k6 runbook measures it end-to-end under Apache + PHP-FPM +
MySQL + the real LLM. See `../RESULTS.md` for the captured numbers and how to
read them.

## Why this is honest, not synthetic

- Every workload calls the module's **real production entry point** over
  **committed fixture data** — not a reimplementation. Retrieval goes through
  `SparseRetriever`/`HybridRetriever` over `src/Rag/corpus/`; extraction through
  `ExtractionSchema` + `ExtractionClient`; verification through `Verifier`;
  canonicalization through `CanonicalSerializer` + `Digest`; prompt assembly
  through `PromptAssembler`.
- Concurrency is **real OS-process concurrency** via `pcntl_fork` — N worker
  processes contending for the same cores, the way N PHP-FPM workers would.
- Latency percentiles use the module's **own** `RateMath::percentile` — the
  exact function the observability dashboard (`MetricsService`) uses — so the
  harness and the production metric agree by construction.
- What it deliberately does **not** measure: the HTTP round trip, the DB, and
  the real (network) LLM call. Those need the dev stack and are Part B.

## Files

| File | What it does |
|---|---|
| `_autoload.php` | Module PSR-4 autoloader (maps `src/` and the `tests/` factories). No Composer, no core. |
| `workloads.php` | The workload registry — one real hot path each (see below). |
| `bench.php` | The runner: `--concurrency`, `--duration`/`--iters`, `--warmup`, `--json`, `--all`, `--list`. Forks workers, aggregates p50/p95/p99 + CPU (`getrusage`) + memory (`memory_get_peak_usage` + `/proc/self/status` VmHWM). |
| `capture.php` | One command: run every workload at 1/10/50, write `results/inprocess-latest.{json,md}` + append `inprocess-results.ndjson`. |
| `measure-tokens.php` | Assemble the real reduce/chat prompts over the committed fixtures, measure exact prompt sizes, derive tokens (Gemini ÷4), and price them via `LlmCostEstimate`. Feeds `../cost-analysis.md`. |
| `dashboard-demo.php` | Offline demonstration of the observability dashboard + alert firing: computes the metric bag via real `RateMath`, evaluates the 8 alerts as real `AlertName`/`AlertFinding` value objects against `AlertEvaluator`'s real thresholds, over a healthy and an incident trace population; renders `results/dashboard-{healthy,incident}.html`. |
| `results/` | Captured output (committed so the numbers are reviewable without re-running). |

## Workloads

| Name | Real path exercised | Maps to |
|---|---|---|
| `guideline_retrieval_sparse` | `SparseRetriever::retrieve` (TF-IDF, degraded floor) | FR-7 |
| `guideline_retrieval_hybrid` | `HybridRetriever::retrieve` (sparse + passthrough rerank) | FR-7 |
| `extraction_validate_parse` | `ExtractionSchema::validate` + `parse` | FR-3 |
| `extraction_client_full` | `ExtractionClient::extract` (stub VLM → decode → validate → parse) | FR-1/FR-2 |
| `verify_chat` / `verify_synthesis` | `Verifier::verify` (V1–V6) on each path | read-path discipline |
| `canonical_serialize_digest` | `CanonicalSerializer::serializeFacts` + `Digest::compute` | idempotency seam |
| `prompt_assemble_reduce` | `PromptAssembler::assemble` (window + canonicalize + render) | synthesis CPU |

## Quick start

```bash
php ops/load/bench/bench.php --list
php ops/load/bench/capture.php --duration=8            # full baseline+load matrix (~4 min on 4 cores)
php ops/load/bench/bench.php verify_chat --concurrency=1,10,50 --duration=10
php ops/load/bench/measure-tokens.php                  # measured prompt sizes + per-call cost
php ops/load/bench/dashboard-demo.php                  # dashboard + alert-firing demo (HTML)
```
