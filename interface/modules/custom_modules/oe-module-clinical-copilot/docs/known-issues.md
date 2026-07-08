# Clinical Co-Pilot — Known Issues / Backlog

Deferred items surfaced by the security + code review passes. Each has a
recommended fix; none are blocking for the first-pass build. Fixed items are
recorded in the git history (see the `fix(copilot): …` commits), not here.

## BL-1 — Pending-order gap: a report with no results yet is invisible (backburner)

**Severity:** medium-high (touches the UC4/UC5 "don't re-order what's drawn" promise).
**Where:** `src/Lab/LabSliceReader.php` — `readPendingOrders()` filters
`prep.procedure_report_id IS NULL` (order with *no report*), and `fetchRawRows()`
`INNER JOIN procedure_result` (order with *results*). A `procedure_report` row
that exists with `report_status='received'` but **no `procedure_result` rows yet**
falls between both: it isn't a "no report" order and it has no results to join.

**Consequence:** such an in-flight order never becomes a `pending_order` fact and
never feeds OverdueTests' reorder-suppression, so the physician could be told to
re-order a test already in the lab's queue. I14 conservation still "passes"
because the row never enters either source query's `rawInputCount` in the first
place.

**Recommended fix:** redefine pending detection as "an active order with no
*final/corrected* result" rather than "an active order with no *report*", so a
report-exists-but-unresulted order is caught. Needs the DB-backed contract suite
(docker stack) to validate against the preliminary-result and multi-result edge
cases before shipping. Add a seed landmine (report received, zero results) + a
`readPendingOrders` regression eval alongside the fix.

## BL-2 — LLM-spend *burn-rate* alert is noisy (low priority)

**Severity:** low (alert noise only; not a spend-safety gap).
**Where:** `src/Observability/Alert/AlertEvaluator.php` — `checkLlmSpend()` divides
7-day spend by a fixed `7*24=168` hours to derive the trailing hourly average,
then alerts when the current hour exceeds `2×` it.

**Why it's low priority:** spend correlates with clinic activity — the warm worker
only generates for *scheduled* patients, and chat only runs when a clinician is
actively using it, so most of the 168 hours (nights, weekends, no-appointment
days) contribute ~zero. That dilutes the average so any normal clinic hour trips
the 2× threshold — the alert cries wolf during clinic. **The actual spend
protection is the hard daily cap + circuit breaker (§3.7), which is unaffected;**
this burn-rate alert is a secondary early-warning signal, not the safety net.

**Recommended fix (when tuning alerts):** compute the trailing average over
*active* hours only (hours with >0 spend), or compare against a same-hour-of-week
baseline, so the threshold reflects real clinic load. Until then the alert can be
left as-is or its threshold raised; the daily cap covers the real risk.

## BL-3 — No token-budget eviction of chat context (not urgent)

**Severity:** low (bounded by the 30-turn/session cap).
**Where:** `src/Chat/` — ARCHITECTURE.md §1.1 specifies evicting oldest tool
results first (they're re-fetchable, I2) while keeping conversation turns
verbatim, within a token budget. No size-based eviction is implemented;
`ChatController::runTurnLocked` folds the full transcript + full fact set into the
prompt every round, growing unbounded up to `MAX_TURNS_PER_SESSION` (30).

**Why it's not urgent:** the 30-turn hard cap bounds the worst case, and Gemini
2.5 Pro's context window absorbs a 30-turn diabetes-synthesis session with
headroom. It's a cost-efficiency and future-proofing item, not a correctness bug.

**Recommended fix:** when assembling the prompt, drop oldest `tool`-role results
first once a token estimate exceeds a configured budget (keep all `user`/
`assistant` turns verbatim); re-fetch on demand via the tool executor if a later
turn needs them (facts are never cached, I2).

## BL-4 — Med grouping can't unify brand vs generic (accepted limitation)

`MedResponse::drugKey()` now keys on the drug name before the first digit (so
distinct drugs sharing a first word no longer collapse, and dose changes still
group — see the `fix(copilot): …` commit). It still cannot unify brand vs generic
(`Lantus` vs `insulin glargine`) without RxNorm/drug-code normalization. Accepted
for v1; the robust path is keying on a normalized drug code when one is present on
the source row (`prescriptions.drug_id`/RxNorm), falling back to the name
heuristic for code-less reconciled `lists` meds.
