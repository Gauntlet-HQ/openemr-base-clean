# Clinical Co-Pilot — AI Cost Analysis

**Companion documents:** [ARCHITECTURE.md](../ARCHITECTURE.md) (agent layer: chat, verification, observability) · [ARCHITECTURE_COMPLETE.md](../ARCHITECTURE_COMPLETE.md) (fact layer, compute model, storage) · [USERS.md](../USERS.md) (target user and UC1–UC6, the workload's source of truth).

This document covers: (1) actual development spend to date, (2) projected production costs at 100 / 1,000 / 10,000 / 100,000 users derived from a workload model of the architecture — not naive cost-per-token × n, (3) the architectural changes each scale tier requires, and (4) how the module's own trace table validates and replaces every assumption below with measured data.

---

## 1. Actual development spend to date

**$0.00 in metered API usage** — reported by the project owner on 2026-07-06: no API billing to date, nothing to report. No estimate is substituted; this section records the actual number, not a reconstruction.

Going forward, development spend has two authoritative sources, and they should reconcile:

1. **GCP Cloud Billing (Vertex AI SKUs)** — the provider-side ground truth for the service account(s) used in development and eval runs.
2. **`mod_copilot_trace`** — every LLM span (`kind ∈ {llm_reduce, chat_turn}`) records `model`, `tokens_in`, `tokens_out`, `cost_usd` (ARCHITECTURE_COMPLETE.md, module tables). Once eval runs and dev-stack usage flow through the module, `SELECT SUM(cost_usd) FROM mod_copilot_trace` per day is the in-repo record, and any divergence from the Cloud Billing total indicates a bug in the cost accounting itself — worth catching *before* the same accounting drives production per-tenant caps (§3, 100K tier).

Until the module's first live LLM call, both read zero.

---

## 2. Projected production costs

### 2.1 Method: model the workload, then price it

A naive projection (average tokens per request × price × users) would miss the four things this architecture does specifically to shape cost:

- **Content-addressed caching (I1/I2):** only narratives are cached, keyed by a digest of freshly recomputed facts. A synthesis read is **free** (no LLM call) whenever the facts haven't changed since the last generation — the warmer exists precisely so the 8:50 AM read is a digest hit.
- **Deterministic verification (V1–V6):** the verifier is tested PHP, not a model — verification itself costs $0. The only LLM cost it adds is the single fail-closed **regeneration** on a failed check.
- **Bounded agent loop:** chat tool-chaining is capped at ≤5 tool calls / ≤3 rounds per turn (§1.2), so per-turn LLM calls are bounded by construction, not by hope.
- **The advisory LLM layers run on the cheap tier:** the second-pass reviewer (§2.5), the cleaning-accuracy audit (§2.6/I15), and eval scoring are all **Gemini Flash** and never gate — so the fidelity guards add cost on the cheapest tier, not on the Pro synthesis/chat tier.

### 2.2 Model selection and pricing

Provider is **Google Gemini on Vertex AI** (ARCHITECTURE.md T18). Current Vertex pricing (as of July 2026; per million tokens, standard tier ≤200k-token context):

| Task | Model | Vertex model ID | Input | Output | Why this tier |
|---|---|---|---|---|---|
| Synthesis reduce (UC1–UC5) | Gemini 2.5 Pro | `gemini-2.5-pro` | $1.25 | $10.00 | The reduce pass is prioritization + careful prose over pre-extracted facts under a strict claim schema — needs a capable model, not a frontier one; the verifier catches what it gets wrong |
| Chat agent (UC6) | Gemini 2.5 Pro | `gemini-2.5-pro` | $1.25 | $10.00 | Tool selection + anaphora resolution over multi-turn context; Vertex constrained decoding enforces the claim schema (T18); same verification backstop |
| Second-pass reviewer (§2.5) + cleaning-audit (§2.6/I15) + advisory eval scoring | Gemini 2.5 Flash | `gemini-2.5-flash` | $0.30 | $2.50 | Reader-facing re-read, pre/post cleaning-fidelity check, and emphasis scoring — all advisory, never blocking; cheapest tier suffices |
| Verification (V1–V6) | *none — deterministic PHP* | — | — | — | Citation resolution, pid guard, numeric grounding, banned-claim lint are string/set operations, not model calls |

Pricing levers used in the optimized scenario: **Vertex context caching** (cache reads ≈ 0.1× input price — up to ~90% off repeated input — plus a per-hour storage charge) and **Vertex Batch prediction** (50% off input and output, async up to 24 h). Batch fits the pre-clinic warmer, which submits each clinic's *next-day* schedule the evening before (the T-12h warm pass, ARCHITECTURE_COMPLETE.md worker) — so the 24 h turnaround is well inside the window. The model version is pinned and folds into the digest (T18): a tier change (e.g. to a newer Gemini 3.x line) is a deliberate, versioned event the eval baselines gate.

Sensitivity: our fact sets sit well under 200k tokens, so the standard Pro rate holds (the >200k rate roughly doubles input/output and would apply only to pathological inputs). Substituting a frontier Gemini tier for synthesis/chat raises those two lines proportionally — the narrative-quality evals are the instrument for that call; the projections below assume standard 2.5 Pro suffices.

### 2.3 Workload model — one physician clinic day

The unit of work is **one physician clinic day** (Dr. S., USERS.md: 20 patients, 15-minute slots). Every parameter is traceable to the specs and every one is measurable from `mod_copilot_trace` (§4).

| # | Parameter | Value | Source / rationale |
|---|---|---|---|
| W1 | Patients per clinic day | 20 | USERS.md §1 |
| W2 | Syntheses warmed per day | 20 | UC1; worker warms the day's schedule (I7) |
| W3 | Digest-miss rate at warm (fresh reduce needed) | 95% | Follow-up patients arrive *because* there is new data (new labs, med changes) — assume nearly every scheduled patient's fact digest changed since their last doc. Digest recurrence makes the remaining 5% free (I1). Conservative: measured hit rates can only lower cost |
| W4 | Warm-miss at read (facts changed between warm and 8:50 read → re-reduce) | 10% | Late-arriving labs between the warm pass and clinic; the ops alert fires at 20% (§3.5), so 10% is the assumed healthy midpoint |
| W5 | LLM reduce calls per day | 20×0.95 + 20×0.10 = **21** | W2×W3 + W2×W4 |
| W6 | Reduce tokens per call | 8,000 in / 1,200 out | Input: system prompt + refusal contract (~1.5k) + canonical fact set for a T2DM follow-up (~40–70 typed facts with citations, ~6k) + claim schema. Output: structured claim list with ordering metadata (§2.1) |
| W7 | Chat sessions per day | 20 × ⅓ ≈ **7** | USERS.md §2/UC6: "roughly a third of the time" the synthesis raises a follow-up |
| W8 | Turns per session | 3.5 (range 2–5) | UC6 anaphoric drill-down pattern |
| W9 | Tool calls per turn | 1.5 (range 0–5, hard cap 5) | §1.2 chaining budget; turn 1 needs zero retrieval (preloaded) |
| W10 | LLM calls per turn | 2.0 | Agent loop: one initial call + ~one continuation per tool round (≤3 rounds cap); 2.0 covers the 1.5-tool-call average with margin |
| W11 | Chat tokens per LLM call | 12,000 in / 600 out | Input: preloaded doc (fact set + narrative, ~7k) + system prompt + growing conversation + tool results (oldest tool results evicted first, §1.1 — this bounds growth). Output: short cited answers |
| W12 | Verification regeneration rate | 5% | One fail-closed retry per failure (§2.3); ops alert fires at 10% (§3.5), so 5% is the assumed healthy rate. Applied to all generation |
| W13 | Second-pass reviewer calls (Flash) | 45.5/day (per rendered response) | §2.5/T15: one Flash re-read per synthesis generation (21) + per chat turn (24.5); reads output + fact set (~9k in / 300 out). Reader-facing + feeds drift metrics (§3.3) |
| W14 | Clinic days per user-month | 18 | ~4 clinic days/week. Treats every provisioned user as a fully active physician — an upper bound; real utilization comes from the trace table |
| W15 | Cleaning-audit calls (Flash) | 20/day (warm extractions) | §2.6/I15: one Flash pre/post fidelity check per warm synthesis extraction (~10k in / 500 out); chat drill-down extractions sampled; runs on the warm path so it adds no read latency |

### 2.4 Cost per physician clinic day

**Unoptimized (list price, no caching, no batching):**

| Component | Calculation | $/day |
|---|---|---|
| Synthesis reduces (Pro) | 21 calls × (8k × $1.25 + 1.2k × $10)/10⁶ = 21 × $0.022 | $0.46 |
| Chat turns (Pro) | 7 sessions × 3.5 turns × 2.0 calls = 49 calls × (12k × $1.25 + 0.6k × $10)/10⁶ = 49 × $0.021 | $1.03 |
| Second-pass reviewer (Flash) | 45.5 calls × (9k × $0.30 + 0.3k × $2.50)/10⁶ = 45.5 × $0.0035 | $0.16 |
| Cleaning-audit (Flash) | 20 calls × (10k × $0.30 + 0.5k × $2.50)/10⁶ = 20 × $0.0043 | $0.09 |
| Verification regenerations | 5% × ($0.46 + $1.03) | $0.07 |
| **Total** | | **≈ $1.80** |

**Optimized (context caching + Batch prediction — the levers the 1K tier makes mandatory):**

| Component | What changes | $/day |
|---|---|---|
| Synthesis reduces | The 19 warmer calls go through Batch prediction at 50% (warm submits the evening before, well inside the 24 h window, §2.2); the 2 read-time misses stay live | $0.25 |
| Chat turns | Session prefix (system + preloaded facts + narrative + history) context-cached — live chat keeps the cache warm between turns. ~75% of input tokens become cache reads at 0.1×; plus one cache write per session | $0.60 |
| Second-pass reviewer (Flash) | Fact-set prefix cached; reader-facing so stays live | $0.12 |
| Cleaning-audit (Flash) | Batched with the warmer (50%) | $0.04 |
| Verification regenerations | 5% of the above | $0.04 |
| **Total** | | **≈ $1.05** |

Chat dominates (~55–60% of spend) in both scenarios. The single most cost-sensitive assumption is W7, chat adoption: if drill-down usage grows from ⅓ of patients to ⅔, total cost rises ~45%. Synthesis cost, by contrast, is structurally capped by the digest model — one reduce per patient-day plus the warm-miss fraction. The Flash fidelity layers (reviewer + cleaning-audit) add ~14% of spend and are the deliberate price of the §2.5/§2.6 guards, kept on the cheapest tier.

### 2.5 Cost at each tier

Per-user-month = per-day × 18 clinic days (W14): **$32 unoptimized, $19 optimized.** LLM cost scales linearly with active physician-days — there is no per-token economy of scale in the workload itself (facts are per-patient, cache scope is per-session). What changes across tiers is which optimizations are *applied* and what infrastructure carries the load (§3):

| Tier | Users | Scenario priced | LLM cost / month | Notes |
|---|---|---|---|---|
| 1 | 100 | Unoptimized (list, live calls) | **≈ $3,200** | Small enough that engineering time on cost optimization is worth more than it saves; caching alone brings it toward ~$2.5k |
| 2 | 1,000 | Optimized (caching + batch warmer) | **≈ $19,000** | (~$32k unoptimized — the ~$13k/month gap is what makes caching/batching mandatory at this tier) |
| 3 | 10,000 | Optimized | **≈ $190,000** | Assumes list pricing; at this run-rate (~$2.3M/yr) Google committed-use / provisioned-throughput discounts apply and are *not* modeled — treat as an upper bound |
| 4 | 100,000 | Optimized, list price | **≈ $1,900,000** | Upper bound before negotiated pricing, a Flash router for navigation-only chat turns (§3), and per-tenant caps trimming the tail |

All figures are Vertex API cost only — **hosting/infrastructure (the app runs on Railway, T24) is separate and comparatively small at demo / single-clinic scale** (a Railway service + managed MySQL is single-digit dollars/month for tier 1); DB replicas, queue workers, and FPM capacity (§3) add to it at higher tiers.

Sub-linear effects deliberately *not* credited (each makes real cost lower than the table): W14 assumes every user runs full clinic days; W3's 95% miss rate ignores stable patients whose digests recur; Google committed-use / provisioned-throughput discounts above tier 2.

---

## 3. Architectural changes by scale tier

The load-test baselines the module ships with (U13: 10 and 50 concurrent users, p50/p95/p99 + error rate, DB connection and PHP-FPM worker saturation recorded — ARCHITECTURE.md §3.6) are the empirical anchor for tiers 1–2 and the extrapolation base for tier 3.

The app is hosted on **Railway** (T24). Its per-service resource ceiling comfortably covers tiers 1–2; the tier-3/4 changes below assume a **migration to a higher-ceiling host** (cloud VM / k8s) once Railway's caps bind — a cost-triggered move, not a redesign, since the module is platform-agnostic (in-process PHP + MySQL). The Vertex LLM path is unchanged across all tiers and hosts.

### Tier 1 — 100 users (a handful of clinics): no structural change

- The shipped architecture carries this tier as-is on **Railway** (T24): one Railway service (OpenEMR + module) + a managed MySQL, the host `background_services` worker tick as the warmer, one Vertex service account, in-process module. Hosting is single-digit dollars/month at this scale — the reason for the platform choice.
- 100 physicians ≈ 2,000 warm generations/day spread over pre-clinic hours — trivially inside one worker tick's capacity and standard API rate limits.
- The 50-concurrent-user load-test baseline (U13) already covers this tier's peak concurrency outright; no extrapolation needed.
- Only knob worth turning: enable 5-minute prompt caching on chat (a client flag, not architecture).

### Tier 2 — 1,000 users: worker scheduling, rate limits, prompt caching

The warmer is where this tier bites: ~20,000 syntheses/day concentrated in each timezone's pre-clinic window.

- **Replace the single worker tick with a scheduled queue.** One `background_services` function looping 20k patients serially will not finish before clinic. Move the warm pass to a work queue with a worker pool, scheduled per clinic's first-appointment time rather than a global interval. The warmer is already correctness-neutral (I7 — worker failure degrades latency only), so this is an ops change, not a spine change.
- **Route the warm pass through the Batch API.** The warmer is batch-shaped by nature: submit each clinic's schedule ≥1–2 h before first appointment, poll for completion, insert docs. 50% cost reduction (§2.4) and batch rate limits are separate from live-traffic limits — the warmer stops competing with interactive chat for tokens-per-minute headroom.
- **Provider rate-limit management.** ~7,000 chat sessions/day of live traffic plus warm bursts needs an explicit token-throughput budget: request the appropriate API tier, and add client-side concurrency limiting with backoff so a rate-limit response degrades to queueing, not to user-visible errors (the trace table's `status=retried` spans make this visible).
- **Prompt caching becomes mandatory**, not optional (the ~$27k/month gap in §2.5).
- **Cost observability grows a rollup:** per-clinic daily `SUM(cost_usd)` from the trace table onto the dashboard, so tier-4's per-tenant caps have an operational ancestor.

### Tier 3 — 10,000 users: read replicas, warm-burst queueing, FPM sizing

- **MySQL read replicas for fact extraction.** Every synthesis and every tool call recomputes facts fresh (I2) — reads against `procedure_*`, `prescriptions`, `lists`, `form_vitals`. At 200k warm extractions plus ~500k chat-tool extractions/day this read load moves to replicas; the module's own writes (`mod_copilot_doc`, `mod_copilot_chat_turn`, `mod_copilot_trace`) are append-only inserts and stay on the primary. Replica lag is safe *for facts* by design — the digest recomputed at read time is the correctness authority (I1), so a lagged replica can only cause a spurious regeneration, never a stale narrative. The trace writer should stay on the primary path (I12 requires every invocation leave a trace).
- **Queue with backpressure for the 8–9 AM thundering herd.** Clinics across a timezone start simultaneously; timezones stagger the national load but concentrate it within each band. The warm queue needs depth monitoring against the "warm-miss rate > 20% at 8:00–9:00" alert (§3.5), autoscaled workers, and a defined shed order: warm misses at read time still self-heal (the read path generates on miss), so the failure mode under overload is slower first-reads, not wrong data.
- **PHP-FPM sizing per the U13 baselines.** Chat streams over SSE (§1.3), which holds an FPM worker for the duration of a turn (seconds, dominated by LLM latency). Concurrent chat turns — not requests/sec — are the binding constraint. Extrapolate from the 10/50-concurrent baselines' saturation numbers; isolate `/copilot/chat` in its own FPM pool so a chat-turn pileup cannot starve the host EMR's interactive traffic, and cap pool size below the DB connection budget observed in the load tests.
- **Provider scale-out:** multiple API keys/organizations for blast-radius isolation (a rate-limited key degrades one segment, not the fleet), and committed-use pricing negotiations begin to matter (~$3.8M/yr run-rate).

### Tier 4 — 100,000 users: multi-tenant isolation, per-tenant cost caps, provider redundancy

- **Multi-tenant isolation.** OpenEMR is single-tenant per installation, so 100k users is realistically a fleet of installs (per health system) plus a central control plane — not one giant database. The module's additivity invariant (I9) is what makes this deployable per-tenant: each install carries its own module tables, ledgers, and trace store, preserving the PHI boundary decision (T16 — traces never leave the tenant's protection domain) at fleet scale. Central plane aggregates *metrics* (counts, rates, `cost_usd` rollups), never payloads.
- **Per-tenant cost caps.** At ~$3.2M/month a runaway tenant (integration bug, adversarial usage, a clinic misusing chat as general search) must be containable. The trace table's `cost_usd` per span is the enforcement input: a per-tenant daily budget evaluated on the alert tick, with a breaker that degrades that tenant to facts-only mode (I6 — the architecture's existing degradation is the perfect enforcement action: service continues, prose pauses) rather than hard denial. Caps are ops config, versioned like cadence config.
- **Provider redundancy.** A single-region outage at this scale is unacceptable even with facts-only degradation. Gemini 2.5 is served across multiple Vertex AI regions, so intra-provider failover is multi-region routing with no prompt or verification change. Because verification is deterministic and downstream of generation (V1–V6 don't care which endpoint produced the output), endpoint failover is *safe by construction* — the gate that guarantees output quality is provider-independent. A cross-*provider* fallback (e.g. to Claude via Vertex or Anthropic) would additionally require re-running the full eval suite against the second model family before it may serve, and re-validating the BAA chain per endpoint (§4 trust boundary 3) — held in reserve, not the default path.
- **A dedicated LLM gateway service** (rate limiting, key management, cache administration, cap enforcement, failover) becomes the first justified departure from the in-process-module tradeoff — the module keeps its verification and fact boundary; only the outbound HTTP hop centralizes.
- **Model routing:** with chat at ~55–60% of spend, route navigation-only turns (pure tool selection, e.g. "list every A1c") to Gemini 2.5 Flash and reserve 2.5 Pro for narrative-bearing turns — the verifier gates both identically, so routing is a cost knob, not a quality gamble. Modeled saving not credited in §2.5; validate against narrative-quality evals first.

---

## 4. Validating the projections: the trace table is the meter

Every LLM span in `mod_copilot_trace` records `model`, `tokens_in`, `tokens_out`, `cost_usd` alongside `kind`, `status`, `duration_ms`, and the correlation ID (ARCHITECTURE_COMPLETE.md, module tables); `mod_copilot_doc` and `mod_copilot_chat_turn` carry the same token/cost columns per artifact. That makes **every parameter in §2.3 a query, not an assumption**:

| Model parameter | Measured by |
|---|---|
| Cost per physician-day (headline) | `SELECT user_id, DATE(started_at), SUM(cost_usd) FROM mod_copilot_trace GROUP BY 1, 2` |
| W3 digest-hit rate | `cache_lookup` spans: hit vs. miss ratio (the dashboard's cache-hit-rate tile, §3.3) |
| W4 warm-miss rate | `llm_reduce` spans on the read path (parented by a read, not a `warm` span) during 8:00–9:00, over scheduled patients — the §3.5 alert input |
| W5 reduces/day, W6 reduce tokens | count and mean `tokens_in`/`tokens_out` of `kind = llm_reduce` spans |
| W7 sessions/day, W8 turns/session | `mod_copilot_chat_session` / `mod_copilot_chat_turn` counts |
| W9 tool calls per turn, W10 LLM calls per turn | `tool_call` spans per parent `chat_turn` span; LLM-call spans per turn |
| W11 chat tokens per call | mean `tokens_in`/`tokens_out` on `chat_turn` LLM spans |
| W12 regeneration rate | `verify` spans with `status = retried`; per-check breakdown from the persisted V1–V6 verdicts |
| W13 second-pass reviewer volume/cost | reviewer Flash spans' `cost_usd`, per rendered response |
| W15 cleaning-audit volume/cost | `mod_copilot_clean_audit` rows: `cost_usd`, `attempt`, `verdict` per audited extraction |
| W14 utilization | distinct `user_id` active days per month from any span kind |

**Validation cadence:** after the first two weeks of pilot traffic, replace W3–W13 with measured medians and recompute §2.5; thereafter the dashboard's cost-per-request and cost-per-day tiles (§3.3) track drift continuously, and a projected-vs-actual divergence beyond ~25% should be treated like any other regression — trace it by correlation ID to the span level and find which parameter moved. The same `cost_usd` pipeline that validates this document later *enforces* it (tier-4 per-tenant caps), which is why reconciling trace-recorded cost against the provider's Cloud Billing total (§1) is worth doing from the very first metered call.

---

*Pricing sourced from Google Cloud Vertex AI pricing as of July 2026 (Gemini 2.5 Pro $1.25/$10, 2.5 Flash $0.30/$2.50 per 1M in/out; Batch 50%; context-cache reads ≈0.1×). Development spend figure as reported by the project owner on 2026-07-06. Workload parameters trace to USERS.md and ARCHITECTURE.md as cited inline; all are superseded by trace-table measurements as they accumulate (§4).*
