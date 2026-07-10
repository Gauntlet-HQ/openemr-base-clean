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

## BL-5 — `ExclusionReason::UnparseableValue` is defined but never wired up; OverdueTests doesn't check `value.parsed` (pre-Thursday review recommended)

**Severity:** medium — touches the "overdue only if last-draw + interval prove
it" promise, same class of risk as BL-1.
**Where:** `src/Capability/OverdueTests.php` (`findLastClockResetting()`) and
`src/Lab/ResultStatusClassifier.php` (`resetsClock` derivation). `resetsClock`
is derived purely from the `result_status` text column; nothing checks
`value.parsed !== null`. A qualitative/garbage value (e.g. free text like
`"Positive"` left in a numeric result column) on an otherwise `final`-status,
numeric-eligible row can satisfy "last draw proves not overdue" with zero real
numeric evidence — the physician could be told a patient isn't overdue for an
A1c/ACR/lipid panel based on a row with no interpretable value.
`ExclusionReason::UnparseableValue` (`src/Fact/Enum/ExclusionReason.php`) and
its docblock-documented contract ("a qualitative fact if the capability accepts
qualitative, else excluded-and-flagged") exist for exactly this case but no
code anywhere in the module ever constructs it.

**Recommended fix:** decide whether `resetsClock` should require
`value.parsed !== null`, and whether an unparseable numeric-eligible value
should become an exclusion (wiring up `UnparseableValue`) rather than a
presented-but-unflagged result. No test exists for this case in
`tests/Isolated/Lab/` or `tests/Db/Capability/OverdueTestsTest.php` — add one
alongside the fix.

## BL-6 — Correction/supersession grouping keys only on calendar-day `clinical_date`, not enforced against `date_collected` drift

**Severity:** medium — same failure class the correction contract (C2) exists
to prevent ("physician sees a stale value as current"), but requires an
uncommon input to trigger.
**Where:** `src/Lab/LabRowProcessor.php` (`groupKey()`). The whole
correction/supersession model assumes a corrected re-transmission lands in the
same `(patientId, resultCode, clinicalDate-truncated-to-day)` group as the
original. Verified against the real HL7 receiver
(`interface/orders/receive_hl7_results.inc.php`) that this usually holds, but
nothing *enforces* it — if a corrected re-transmission's timestamp lands on a
different calendar day than the original (midnight-adjacent draw, a manual
correction entered with a different date), the correction silently becomes a
second, unrelated result instead of superseding the stale one. Untested in
`tests/Isolated/Lab/` or `tests/Db/Lab/`.

**Recommended fix:** key correction-matching more robustly — e.g. additionally
or alternately join on `procedure_order_id` lineage when a correction shares
the same order, rather than solely on calendar day. Needs a design decision,
not a local patch.

## BL-7 — HDL cholesterol thresholded in the clinically wrong direction (accepted simplification, needs a real fix before wide clinical use)

**Severity:** medium — reaches physician-facing out-of-range flags.
**Where:** `table.sql` / `sql/install.sql` (`cadence:cholesterol` /
`threshold:cholesterol` config row) and
`src/Lab/Config/DbLabContractConfigProvider.php`. HDL (LOINC `2085-9`) is
folded into the same `cholesterol` analyte bucket as total cholesterol and LDL,
using threshold `direction="high"`. Clinically backwards for HDL — it's *low*
HDL that's the actionable abnormal finding. As written, a favorable HDL of 110
mg/dL could be flagged "out of range," while a genuinely low/dangerous HDL of
30 mg/dL would never be flagged via the threshold path. The SQL comment
candidly documents this as "an accepted simplification, not directionally
correct for HDL" but it wasn't previously tracked here and nothing in code
mitigates it.

**Recommended fix:** give HDL its own `analyte` bucket with a `low`-direction
threshold (need a clinical decision on the correct cutoff, e.g. `<40 mg/dL`
men / `<50 mg/dL` women) instead of sharing `cholesterol`'s `high` direction.

## BL-8 — QA-driven rerun (T22) bypasses the per-tick worker LLM budget entirely, including an explicit zero (review before re-enabling background LLM)

**Severity:** high if `CLINICAL_COPILOT_WORKER_LLM_ENABLED=true` — this is a
cost/resource-exhaustion class bug, not a data-safety one. **Not currently
exploitable**: `WorkerConfig::backgroundLlmEnabled()` defaults to `false`
(commit `b0b7a69`), so a fresh/default deployment is safe.
**Where:** `src/Worker.php` — `runQaDrivenReruns()` vs. the correctly-budgeted
`warm()`. Commit `b454857` ("honour zero worker budget") correctly fixed
`warm()`: it gates every appointment against `maxGenerationsPerTick()`, which
returns `0` when `per_tick_worker_llm_budget_usd &lt;= 0.0`. But
`maxGenerationsPerTick()` is `private` and called *only* from `warm()`.
`runQaDrivenReruns()` never calls it and never reads
`per_tick_worker_llm_budget_usd` at all — its only spend gate is
`$breaker-&gt;isOpen()`. Once a QA-flagged doc passes the status/breaker/
cutoff/rerun-count/freshness gates, it unconditionally calls
`readPath-&gt;regenerate(...)` (`forceRegenerate: true`) — an unconditional
paid LLM call, up to ~20×/tick (`QA_SWEEP_LIMIT`). **Setting the worker budget
to `$0` does not stop this path**, directly contradicting the commit's own
"honour zero worker budget" claim.

**Recommended fix:** needs a product decision, not a one-line patch — should
`qa_rerun` share `warm()`'s tick-wide generation counter, or get its own
budget-derived cap checked before the `regenerate()` call? **Do this before
flipping `CLINICAL_COPILOT_WORKER_LLM_ENABLED` on in any environment that
relies on the budget knob to control spend.**

## BL-9 — QA sweep can loop forever on a malformed/unresolvable target, and undercounts the failure in its own summary

**Severity:** medium — "the sweep looks clean when it isn't."
**Where:** `src/Observability/Qa/QaReviewer.php` — `pendingDocTargets()`/
`pendingChatTurnTargets()` select `WHERE qa_status = 'pending' ORDER BY id ASC
LIMIT $limit`. Two early-return paths (the doc's stored JSON fails to decode;
a chat turn's `session_id` can't be resolved) return before `QaStore::insert()`
or any `qa_status` transition happens — so a broken row is never marked
terminal and gets re-selected on *every* subsequent worker tick, forever,
consuming a `$limit` slot each time (can starve legitimately-pending targets
behind it if `$limit` is small). Neither path increments `sweep()`'s `$errors`
counter (only the `catch (\Throwable)` branches do), so
`QaSweepSummary::errors` can read `0` while this row silently fails every
single tick.

**Recommended fix:** count these paths as errors in the tally (or a distinct
bucket), and have `DocQaAnnotator` (or an equivalent) transition such rows to
a terminal `qa_status` (e.g. `'error'`/`'unavailable'`) so they stop being
re-selected. Needs a decision on the right terminal semantics.

## BL-10 — `DocController::isAuthorizedForCorrelation()` fails open when no trace row exists yet (low priority, effectively inert today)

**Severity:** low. **Where:** `src/Controller/DocController.php`. Returns
`true` (authorized) when no `mod_copilot_trace` row exists yet for
`(correlationId, pid)` — a race where a status-poll request arrives before the
first trace span for a just-started regenerate has been inserted. Blast radius
is minimal: the endpoint is already gated by this fork's documented
chart-wide (not per-patient) ACL model, so this doesn't grant any PHI access
beyond what ACL already allows; the only concrete exposure is cross-user
isolation of a live regenerate-progress stream, which requires guessing
another user's UUIDv7 correlation id.

**Recommended fix:** flip the default to fail-closed (`return false` when no
row is found) only if the team wants stricter isolation than the current
chart-wide ACL model provides; otherwise this is working as designed.

## BL-11 — `sql/uninstall.sql` (and the root `table.sql`) are never executed by OpenEMR's real Module Manager

**Severity:** low today (verified content-identical to the live path), but a
maintenance trap.
**Where:** Traced the actual core code paths: install resolves
`sql/table.sql` → falls back to `sql/install.sql` (the root `table.sql` is
never read — it's a documentation duplicate, verified byte-identical to
`sql/install.sql`). Uninstall (`reset_module` action) goes through
`ModuleManagerListener::reset_module()`'s own pure-PHP `DROP TABLE`/
`DELETE background_services` logic — `sql/uninstall.sql` is never referenced
by any core code path. `ModuleManagerListener::OWNED_TABLES` and
`sql/uninstall.sql`'s `DROP TABLE` list are identical today, so there's no
live divergence yet, but the next person who adds a table and (reasonably,
given the filename) edits only `sql/uninstall.sql` will find that change
silently never executes in production.

**Recommended fix:** either keep `sql/uninstall.sql` explicitly documented as
a human-readable mirror only (done — see the module README), or remove it
entirely to eliminate the drift risk. Not urgent either way.

## BL-12 — `IpRateLimiter` uses non-atomic APCu fetch-then-store (minor)

**Severity:** low — only gates the unauthenticated `/copilot/ready` endpoint
(a soft cost cap, not a security boundary; confirmed **not** vulnerable to
`X-Forwarded-For` spoofing, it keys on `REMOTE_ADDR`).
**Where:** `src/Observability/IpRateLimiter.php` — classic check-then-increment
race: concurrent requests from the same IP within one window can all read the
same counter before any of them stores the incremented value, letting the
effective rate exceed `maxRequestsPerWindow` under load.

**Recommended fix:** swap to the atomic `apcu_inc($key, 1, $success,
self::WINDOW_SECONDS)` instead of `apcu_fetch()`+`apcu_store()`. Not applied in
this pass — `apcu` extension behavior couldn't be exercised in the review
sandbox (no live PHP-FPM/Apache process with `apcu` loaded); low-risk to take
directly once someone can smoke-test it against a real stack.
