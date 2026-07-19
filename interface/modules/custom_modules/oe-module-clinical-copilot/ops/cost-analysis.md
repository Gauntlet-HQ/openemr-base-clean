# Clinical Co-Pilot — AI Cost Analysis at 100 / 1K / 10K / 100K users

**Status:** one of the case study's "still owed" submission artifacts (see
`ARCHITECTURE.md`'s Case-study compliance map: "AI cost analysis at
100/1K/10K/100K users"), delivered here as U13. This is a **parameterized
model**, not a measured bill — no production traffic exists yet (v1 scope is
literally one outpatient endocrinologist, per `USERS.md`). Every lever below
is a named, adjustable assumption; the formula and per-tier table are built
so a reader can swap in real numbers once `ops/load/RESULTS.md` and live
usage replace the estimates. Treat the dollar figures as **order-of-magnitude
and directionally honest**, not a quote.

> **Update — the prompt-size inputs are now MEASURED, not guessed.** The
> `TokIn_synth` / `TokCached` / `TokIncr` figures below were originally
> order-of-magnitude estimates. They are now measured by
> `ops/load/bench/measure-tokens.php`, which assembles the **real** production
> prompts (`PromptAssembler` / `ChatPromptAssembler`) over the committed
> synthetic-patient fixtures and counts characters exactly, converting to
> tokens with Gemini's published ~4-chars/token ratio (it does not call the
> network `countTokens`). See "Measured token counts" immediately below; the
> per-tier table still prices at the conservative `TokIn_synth = 8000`, which
> the measurement confirms is a safe **upper bound** (measured heavy-patient
> ceiling ≈ 6,458; typical ≈ 2,155), so the table over-states rather than
> under-states synthesis cost.

## Measured token counts (production-tied — `ops/load/bench/measure-tokens.php`)

Measured over the four committed synthetic endo patients (`ccp_001..004`,
4–6 canonical facts each — a representative mid-complexity follow-up visit),
plus one synthetic long-history patient as an upper bound:

| Lever | Prior estimate | **Measured (median, real fixtures)** | Heavy-patient upper bound | How measured |
|---|---:|---:|---:|---|
| `TokIn_synth` (synthesis reduce input) | 8,000 | **2,155** | 6,458 | exact chars of assembled system+user+responseSchema ÷ 4 |
| `TokCached` (chat preloaded fact block) | 8,000 | **1,270** | — | exact chars of the identical-every-turn PATIENT+FACTS prefix ÷ 4 |
| `TokIncr` (chat turn delta) | 700 | **18** (turn-1, empty transcript) | grows with transcript | exact chars of the QUESTION/CONVERSATION tail ÷ 4 |
| `TokOut_synth` / `TokOut_turn` | 800 / 300 | _unchanged — output needs a live generation to measure_ | | flagged estimate |

Per-call cost, computed by the module's own `LlmCostEstimate` (Gemini 2.5 Pro
list rates) from the measured inputs:

| Call | Measured cost |
|---|---:|
| Synthesis reduce (one cache-miss narrative), measured `TokIn_synth`=2,155 + est. 800 out | **$0.01069** |
| Chat turn 1 (cache write), measured `TokCached`+`TokIncr`=1,288 + est. 300 out | **$0.00461** |
| Vision extraction / document (1–2 pg scan, ~2,000 in + 400 out) | **$0.00650** |

**Finding:** the real synthesis prompt for these representative patients is
~2.1K tokens — about **4× smaller** than the original 8,000 estimate — because
the fixtures carry 4–6 facts, not the 5-capability multi-visit set the estimate
assumed. A long-history patient (windowed by `PromptFactWindow`) tops out around
6.5K, still under 8K. So the per-tier dollar figures below (which keep 8,000)
are a deliberate conservative ceiling; a deployment measuring
`mod_copilot_doc.tokens_in` from real traffic would likely see the synthesis
line come in lower. Re-run `measure-tokens.php` against real seeded data (or
wire in `countTokens`) to replace the ÷4 derivation with exact provider counts.

---

"Users" here = **clinicians** (physicians using the copilot), matching the
module's actual unit of scaling — each clinician generates their own
schedule of patient syntheses and chat turns. 100K clinician-users is a
hypothetical extrapolation for this exercise, not a real deployment target;
see `ops/load/RESULTS.md`'s T20 note on the vertical-first scaling stance,
which this document's linear token-cost scaling does *not* by itself imply
about infrastructure cost (compute/DB scales by machine size in steps, not
linearly with user count).

## Pricing basis (Vertex AI, current published rates — cited, not memorized)

Standard (non-Priority, non-Batch) tier, ≤200K-token context (every prompt in
this model is far under that threshold, so the >200K tier never applies):

| Model | Input $/1M tok | Output $/1M tok | Cached input $/1M tok |
|---|---|---|---|
| Gemini 2.5 Pro | $1.25 | $10.00 | $0.13 |
| Gemini 2.5 Flash | $0.30 | $2.50 | $0.03 |

Source: Google Cloud Vertex AI / Agent Platform generative-AI pricing page
(fetched at the time this document was written; verify against
`https://cloud.google.com/vertex-ai/generative-ai/pricing` before relying on
these for a real budget — Vertex pricing has changed before and will again).

**Assumption, explicitly flagged (not confirmed on the fetched pricing
page):** context-cache **storage** is billed separately from cached-read
tokens, by convention on other Gemini generations at roughly **$1.00 per 1M
cached tokens held per hour**, prorated to the minute. This document uses
that figure for the storage term below; it is the single least-certain input
in the whole model and worth confirming against current Vertex documentation
or a sales contact before budgeting against it. It is also the smallest line
item by far (see the per-tier table), so getting it wrong by 2× barely moves
the total.

## What gets charged, and to which model (per `docs/build-notes.md` / T18)

| Workload | Model | Cached? |
|---|---|---|
| Synthesis reduce (one narrative per scheduled patient, **only on a fact-digest cache miss** — I1/I2: a warm hit serves the existing doc row with zero LLM calls) | Gemini 2.5 Pro | No (each reduce is a fresh, one-shot prompt over that visit's fact set — nothing to reuse turn-over-turn) |
| Chat turns (multi-turn, pinned to one patient per session) | Gemini 2.5 Pro | **Yes** — the preloaded fact block + system instructions are identical for every turn in a session; Vertex context caching means **turn 1 pays full input price to establish the cache, turns 2+ pay the ~10%-of-standard cached-read price for that same block and only pay full price for the small incremental delta** (new tool-call results + the latest message). This is the load-bearing lever in the whole model — see "Dominant levers" below. |
| Post-mortem QA sweep (U12: a second, decoupled Flash pass over each synthesis doc and each chat turn, off the serving path) | Gemini 2.5 Flash | No in this model (each QA pass re-reads a different target's stored fact snapshot; nothing to cache across independent sweep items — though see "Dominant levers" for why Batch pricing is a real opportunity here specifically) |

## Formula

Let:

- `U` = clinician-users
- `P` = patients scheduled per clinician per day (assumption: **20**, a full
  outpatient endocrinology day; `USERS.md`'s worked example references
  "patient #14" on a schedule, consistent with a day in the high teens/low
  twenties)
- `D` = clinic days per month (assumption: **20**, a 5-day week)
- `M` = synthesis cache-**miss** rate (assumption: **30%** — most scheduled
  patients' facts are unchanged between the T-12h and T-1h warm passes
  [T22], so most synthesis "computations" on any given day are warm cache
  hits with zero LLM cost; only a fact-digest change forces a real reduce
  call)
- `S` = fraction of patients who trigger at least one chat session that day
  (assumption: **25%** — chat is explicitly "the exception," per
  ARCHITECTURE.md §1.3: the primary path is the pre-warmed synthesis; chat
  is a follow-up drill-down, not every patient's default path)
- `T` = average turns per chat session once started (assumption: **2.4**)
- `TokIn_synth` = 8,000 (system instructions + the full canonical fact set
  for one patient — see `src/Reduce/PromptAssembler.php`'s system-instruction
  block plus a realistic 5-capability fact set)
- `TokOut_synth` = 800 (a synthesis narrative: ~15-25 cited claims)
- `TokCached` = 8,000 (the preloaded fact block a chat session reuses every
  turn — same shape/size as the synthesis fact set, since chat preloads the
  same session facts)
- `TokIncr` = 700 (the turn-specific delta: new tool-call results, the
  physician's message, transcript growth since the last turn)
- `TokOut_turn` = 300 (one turn's narrated, cited answer)
- `TokIn_qa` = 6,000, `TokOut_qa` = 300 (Flash re-reading one target's stored
  facts + rendered answer and emitting a structured verdict)

Per month:

```
patients_month     = U * P * D
missed_syntheses    = patients_month * M
sessions_month      = patients_month * S
turn1s              = sessions_month                     # pays full price, also the cache write
followup_turns      = sessions_month * (T - 1)            # pays cached-read + incremental-only
total_turns         = turn1s + followup_turns
qa_targets          = missed_syntheses + total_turns       # one QA pass per doc row + per chat turn

reduce_cost   = missed_syntheses * (TokIn_synth/1e6*$1.25 + TokOut_synth/1e6*$10.00)

chat_in_cost  = turn1s      * (TokCached + TokIncr)/1e6 * $1.25
              + followup_turns * (TokCached/1e6*$0.13 + TokIncr/1e6*$1.25)
chat_out_cost = total_turns * TokOut_turn/1e6 * $10.00
cache_storage = sessions_month * TokCached/1e6 * $1.00/hr * 0.25hr   # ~15 min average session length

qa_cost       = qa_targets * (TokIn_qa/1e6*$0.30 + TokOut_qa/1e6*$2.50)

total_month   = reduce_cost + chat_in_cost + chat_out_cost + cache_storage + qa_cost
```

## Per-tier table

| Users (clinicians) | Patients/mo | Missed syntheses/mo | Chat sessions/mo | Chat turns/mo | Reduce cost | Chat cost (in+out+storage) | QA cost | **Total/mo** | $/user/mo |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 100 | 40,000 | 12,000 | 10,000 | 24,000 | $216.00 | $227.56 | $91.80 | **$535.36** | $5.35 |
| 1,000 | 400,000 | 120,000 | 100,000 | 240,000 | $2,160.00 | $2,275.60 | $918.00 | **$5,353.60** | $5.35 |
| 10,000 | 4,000,000 | 1,200,000 | 1,000,000 | 2,400,000 | $21,600.00 | $22,756.00 | $9,180.00 | **$53,536.00** | $5.35 |
| 100,000 | 40,000,000 | 12,000,000 | 10,000,000 | 24,000,000 | $216,000.00 | $227,560.00 | $91,800.00 | **$535,360.00** | $5.35 |

The model is linear in `U` by construction (every lever is a per-patient or
per-turn rate, none of them depend on total scale) — so $/user/mo is
constant at **$5.35** across all four tiers. That flatness is itself a
finding: **nothing in the token-cost model produces an economy of scale**;
whatever changes with scale (negotiated Vertex volume discounts, a move to
Provisioned Throughput, Priority-tier SLAs) is a commercial lever outside
this model, not a mechanical one. Infrastructure cost (PHP-FPM workers, DB
connections) is the piece that actually steps rather than scales linearly —
see `ops/load/RESULTS.md`'s T20 vertical-first note.

## Dominant levers (most to least, holding pricing fixed)

1. **Warm hit-rate (`M`, synthesis cache-miss rate) — linear, direct
   multiplier on the single largest controllable line item.** Reduce cost
   scales 1:1 with `M`. At the modeled 100-user tier: `M=30%` → $216.00/mo;
   `M=50%` → $360.00/mo; `M=10%` → $72.00/mo. This is entirely a function of
   T22's warm cadence discipline (T-12h / T-1h / T-30min passes) and how
   often patients' facts actually change between passes — it is the
   single biggest cost lever the *design* controls (as opposed to a pricing
   lever Google controls), because every point of hit-rate improvement is a
   1:1 dollar reduction with zero product tradeoff (a cache hit is strictly
   better for the physician too — instant, and already-verified).

2. **Context caching on chat turns — the load-bearing mechanic named in
   this build unit's brief.** Without it, every turn (not just turn 1) would
   pay full standard-rate input for the entire 8,700-token preloaded block:

   | Users | Chat input+output cost, WITH caching | WITHOUT caching (all turns full price) | Caching saves |
   |---:|---:|---:|---:|
   | 100 | $207.56 | $333.00 | 37.7% |
   | 100,000 | $207,560 | $333,000 | 37.7% |

   (These two columns exclude the small cache-storage line so the comparison
   isolates the caching mechanic itself.) The saving is a direct function of
   `T` (turns/session) — the more turns a session accumulates, the more of
   them are cheap cached-read turns amortizing the one full-price turn 1.
   **Session-length is therefore also a hidden lever**: a physician who asks
   3-4 follow-ups per session is *cheaper per turn* than one who opens three
   separate 1-turn sessions, because each new session re-pays the cache-write
   price. The rate limiter's own "30 turns/session, then start fresh" policy
   (§3.7) is, incidentally, cost-neutral-to-favorable up to that ceiling.

3. **Sessions-per-patient rate (`S`) and turns-per-session (`T`)** — these
   are genuine clinical-usage unknowns (no production traffic exists yet).
   Doubling `S` roughly doubles chat cost; the model's 25%/2.4 assumption is
   a placeholder pending real usage data from a pilot deployment.

4. **QA sweep cost is a real but secondary line (~17% of total)** — and it
   is the one workload in this model explicitly decoupled from the serving
   path (U12: "post-mortem QA pass ... zero latency on the request ...
   sweeps recently-served rows on the worker tick"). Because it is
   async-by-design and not latency-sensitive, it is the strongest Batch/Flex
   pricing candidate in the whole system: Flash Batch pricing is
   $0.15/$1.25 per 1M in/out vs. $0.30/$2.50 standard — roughly **50% off**
   this line item if the worker tick's QA sweep were submitted as a Vertex
   Batch job rather than synchronous Flash calls. That would cut the
   100-user-tier QA cost from $91.80/mo to ~$45.90/mo. **Not implemented in
   code today** (U12 ships the QA sweep as ordinary synchronous Flash calls
   on the tick) — flagged here as a concrete, low-risk future optimization
   precisely because QA is advisory and already tolerant of lag (I6-style
   degradation: "QA status: pending" until the sweep lands).

5. **Patients/clinician/day (`P`) and clinic days/month (`D`)** are
   demand-side inputs, not cost-model choices — they scale total spend but
   don't change the *shape* of where the money goes (the percentage split
   across reduce/chat/QA stays constant regardless of `P`/`D`, since every
   term in the formula scales by the same `patients_month` factor).

## Cost mix at every tier (percentage of total — constant across tiers, since the model is linear)

| Component | Share of total |
|---|---:|
| Synthesis reduce (Pro) | 40.3% |
| Chat turns (Pro, cached) | 42.5% |
| Post-mortem QA (Flash) | 17.2% |

Chat and synthesis are comparably sized cost centers; QA is meaningfully
smaller and, per lever 4 above, has the most headroom left on the table.

## Honest gaps in this model

- **Input token counts are now MEASURED (see "Measured token counts" near the
  top); output counts remain estimates.** `TokIn_synth`, `TokCached`, and
  `TokIncr` are measured by `ops/load/bench/measure-tokens.php` — the real
  `PromptAssembler` / `ChatPromptAssembler` output over the committed fixtures,
  counted exactly and converted at Gemini's ~4-chars/token ratio (not the
  network `countTokens`). Two residual gaps remain: (1) the ÷4 ratio is a
  derivation, not a provider count — replace it with Vertex `countTokens` (the
  `LlmReachabilityProbe` already reaches that endpoint) or with instrumented
  `mod_copilot_doc.tokens_in`/`tokens_out` and `mod_copilot_chat_turn.tokens_in`/
  `tokens_out` (both columns already exist) from real traffic; (2) **output**
  token counts (`TokOut_synth`/`TokOut_turn`) still cannot be measured without a
  live generation, so they stay flagged estimates.
- **`M`, `S`, `T` are clinical-usage guesses.** There is no production
  traffic (synthetic patients only, OPEN-1) to derive them from yet. The
  dashboard (U12) already tracks `cache hit rate` and `chat_drilldown_rate`
  — once a pilot runs, pull real numbers from there and rerun this model.
- **Cache storage pricing is an assumption**, flagged inline above, and is
  also the smallest line item — low risk if wrong, but worth confirming
  before this document is used for an actual budget line.
- **Priority-tier / Provisioned Throughput are not modeled.** If chat's p95
  latency (see `ops/load/RESULTS.md`) turns out to need Priority tier for
  SLA reasons, chat costs roughly double (Pro Priority input is $2.25 vs
  $1.25 standard) — not reflected above, which prices everything at
  standard tier.
- **No volume-discount / committed-use modeling.** Real Vertex pricing at
  the 100K-user tier's spend level would almost certainly involve a
  negotiated enterprise agreement; this document intentionally prices
  every tier at public list rates so the *shape* of the model (where the
  money goes, which levers dominate) stays legible, rather than guessing at
  a discount schedule no one has actually negotiated.

---

# Week 2 addendum — ingestion, retrieval, and eval cost/latency

Week 2 adds a document-ingestion path, hybrid retrieval, and an eval gate. As
with the model above, these are **estimates** — no production ingestion traffic
exists yet (synthetic data only). Every figure is a named, adjustable lever.

## Where the money goes (Week 2)

- **Vision extraction (the only new per-request LLM cost).** One multimodal
  `generateContent` call per uploaded document. Cost is dominated by the input
  image/PDF tokens, not output (extraction JSON is small). A 1–2 page lab/intake
  scan is order ~1–3K input tokens + a few hundred output tokens on
  `gemini-2.5-pro`. At list rates that is roughly **$0.005–0.02 per document** —
  and it happens **once per document**, not per view (the extraction is persisted
  and verified, never re-run on read).
- **Retrieval + rerank.** The default path is **$0** — sparse TF-IDF over the
  committed corpus runs in-process, no embeddings API, no vector DB. Dense
  retrieval + a Cohere-style reranker are optional upgrades; when enabled, cost
  is one embedding call per query (~hundreds of tokens) plus a rerank call over a
  handful of candidates — negligible next to the vision call.
- **Eval gate.** **$0 at CI time** — the 54-case gate feeds recorded model
  outputs through the real deterministic code; no live model or DB is called.
- **Chart write-back, review, and the guideline surface.** Deterministic PHP and
  local retrieval — no LLM cost.

So Week 2's marginal AI spend is essentially **one bounded vision call per
document ingested**, gated behind a human upload — a naturally low-frequency,
high-value event, unlike per-view synthesis.

## Latency profile (targets to confirm against the dev stack)

| Flow | Dominant cost | Target |
|---|---|---|
| Document ingestion (upload→draft) | one vision call (non-streaming) | p95 < ~8 s |
| Guideline retrieval (sparse) | in-process TF-IDF over the corpus | p95 < ~50 ms |
| Verify/edit (per field) | one indexed UPDATE | p95 < ~150 ms |
| Lock → chart commit | a few indexed INSERTs + accuracy calc | p95 < ~300 ms |
| Eval gate (54 cases, CI) | deterministic, no I/O to models/DB | < ~2 s total |

The vision call is the only step whose latency depends on an external provider;
it carries the Week 1 LLM client's 90 s per-attempt timeout plus the shared
retry policy (`src/Http/RetryingHttpRequester`): up to 2 retries with short
exponential backoff (200 ms, then 400 ms), taken only on transport errors,
HTTP 429, and 5xx — other 4xx rejections fail immediately, and provider/key
failover still sits above the in-provider retries. The ingestion SLO
and its alert (extraction failure rate, retrieval latency) are tracked as an
M5 observability item; the numbers above are the targets those SLOs assert
against once `ops/load/` is extended to the Week 2 endpoints.

## Levers

- **Model choice for extraction** — `gemini-2.5-pro` (accuracy) vs `flash`
  (cheaper/faster). Extraction accuracy is measured for free (vlm vs verified),
  so this is an evidence-based tuning knob, not a guess.
- **Documents per patient per visit** — the ingestion frequency; low by nature.
- **Dense/rerank on or off** — off is free and offline; on trades a small cost
  for retrieval quality on a larger corpus.

## Actual development spend

The rubric asks for actual dev spend, not just the projection above. The repo
does not contain billing records, so this section gives (a) what the repo's
own artifacts prove cost **nothing**, (b) a **clearly-labelled ledger-derived
estimate** of the metered dev usage with its methodology, and (c) the marker
where the real console figure belongs. **Do not read the estimate as a bill.**

**Provably $0 (deterministic, no live model):**

- Every eval-gate run — the 54-case golden set (`ops/eval/run-evals.php`)
  feeds recorded model outputs through the real code; no API call, in local
  runs or in `w2-eval-gate.yml` CI.
- Every load/baseline capture — `ops/load/RESULTS.md` Part A
  (captured 2026-07-13) runs module compute only: no DB, no LLM.
- The entire isolated PHPUnit suite (509 tests) — a hand-written
  `LlmClientInterface` stub stands in for the model.

**Metered dev usage (Gemini API key, synthetic data only):** manual
exercising of synthesis, chat, and vision extraction against the seeded
synthetic patients during development and on the Railway demo, plus one-time
embedding of the small guideline corpus (`gemini-embedding-001`).

**Ledger-derived estimate — methodology and figure.** Using the module's own
measured per-call costs above (synthesis reduce ≈ $0.0107, chat turn ≈
$0.0046, vision extraction ≈ $0.0065, at Gemini 2.5 Pro list rates), even a
generous assumption of the dev period's manual traffic — order of hundreds of
synthesis regenerations, hundreds of chat turns, and tens of document
extractions across dev/debug/demo cycles — prices out at roughly
**$1–10 for the whole development period**, i.e. low single digits at the
central estimate. Corpus embedding is negligible (a small committed corpus at
embedding rates measured in cents per million tokens). Two honest caveats:
(1) the call volumes are recollection-based, not instrumented — the
`tokens_in`/`tokens_out` columns on `mod_copilot_doc`/`mod_copilot_chat_turn`
exist but no dev-period export of them is committed; (2) some or all AI Studio
API-key traffic may have fallen under the free tier, in which case the billed
figure is lower still. Worst-case exposure was bounded throughout by the
module's own spend breakers (defaults: $50/day, $10/hour —
`docs/configuration.md`).

> **Console-reported spend for the dev period: $\_\_\_\_ — fill from the
> Google Cloud / AI Studio billing console (project owner action).** Until
> that figure is pasted here, the $1–10 band above is a repo-derived
> estimate, not a measured bill.
