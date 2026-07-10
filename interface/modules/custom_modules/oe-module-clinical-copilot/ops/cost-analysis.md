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

- **Token counts are estimates, not measurements.** `TokIn_synth`,
  `TokCached`, `TokIncr`, and the two output figures are order-of-magnitude
  reads of `PromptAssembler`'s system-instruction block plus a plausible
  5-capability fact set for one synthetic patient — not counted with
  Vertex's own `countTokens` API against real seeded data. `ready.php`
  already calls a Vertex `countTokens` probe for reachability
  (`LlmReachabilityProbe`); the natural next step is instrumenting
  `mod_copilot_doc.tokens_in`/`tokens_out` and `mod_copilot_chat_turn.tokens_in`/
  `tokens_out` (both columns already exist in the schema) from real traffic
  and replacing every estimate above with a measured percentile.
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
